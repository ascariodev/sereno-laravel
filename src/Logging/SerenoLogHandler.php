<?php

namespace Sereno\Laravel\Logging;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Throwable;
use WeakReference;

class SerenoLogHandler extends AbstractHandler
{
    public const BATCH_SIZE = 100;

    public const TIMEOUT_SECONDS = 2;

    public const MAX_TIMEOUT_SECONDS = 10;

    public const MIN_RETRY_SECONDS = 0.5;

    public const DEFAULT_FALLBACK_CHANNEL = 'daily';

    public const INGEST_PATH = '/api/ingest/events';

    /**
     * Shared by every instance: while one handler is sending (or reporting a failure), any record that
     * reaches a Sereno handler is left to the other handlers of its logger instead of being buffered.
     */
    private static bool $sending = false;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private readonly ?string $endpoint;

    private readonly ?string $key;

    private readonly EventFormatter $formatter;

    private readonly int|float $timeout;

    public function __construct(
        ?string $url,
        ?string $key,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?EventFormatter $formatter = null,
        private readonly ?LoggerInterface $fallback = null,
        ?Closure $shutdownRegistrar = null,
        int|float|string|null $timeout = null,
        private readonly string $fallbackChannel = self::DEFAULT_FALLBACK_CHANNEL,
    ) {
        parent::__construct($level, $bubble);

        $url = trim((string) $url);
        $key = trim((string) $key);

        $this->endpoint = $url === '' ? null : rtrim($url, '/').self::INGEST_PATH;
        $this->key = $key === '' ? null : $key;
        $this->formatter = $formatter ?? new EventFormatter;
        $this->timeout = $this->normalizeTimeout($timeout);

        if ($this->isConfigured()) {
            $this->registerFlushPoints($shutdownRegistrar);
        }
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->isConfigured() && ! self::$sending && parent::isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        try {
            $this->buffer[] = $this->formatter->format($record);
        } catch (Throwable $e) {
            $this->reportFailure('Sereno log event could not be formatted', ['error' => $e->getMessage()]);

            return $this->bubble === false;
        }

        if (count($this->buffer) >= self::BATCH_SIZE) {
            $this->flush();
        }

        return $this->bubble === false;
    }

    public function flush(): void
    {
        if ($this->buffer === [] || self::$sending) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        self::$sending = true;

        try {
            foreach (array_chunk($events, self::BATCH_SIZE) as $batch) {
                $this->send($batch);
            }
        } finally {
            self::$sending = false;
        }
    }

    public function close(): void
    {
        $this->flush();
    }

    public function reset(): void
    {
        $this->flush();
    }

    private function normalizeTimeout(int|float|string|null $timeout): int|float
    {
        if (! is_numeric($timeout) || $timeout + 0 <= 0) {
            return self::TIMEOUT_SECONDS;
        }

        $value = $timeout + 0;

        return ! is_finite($value) || $value > self::MAX_TIMEOUT_SECONDS ? self::MAX_TIMEOUT_SECONDS : $value;
    }

    private function isConfigured(): bool
    {
        return $this->endpoint !== null && $this->key !== null;
    }

    /**
     * The same batch (same event ids) is sent again once after a connection failure, a timeout or a 5xx, so the
     * server discards the events the first attempt already stored. Both attempts share MAX_TIMEOUT_SECONDS.
     *
     * @param  list<array<string, mixed>>  $batch
     */
    private function send(array $batch): void
    {
        $startedAt = hrtime(true);
        $failure = $this->attempt($batch, $this->timeout);

        if ($failure !== null && $failure['transient']) {
            $remaining = self::MAX_TIMEOUT_SECONDS - (hrtime(true) - $startedAt) / 1e9;

            if ($remaining >= self::MIN_RETRY_SECONDS) {
                $failure = $this->attempt($batch, min($this->timeout, $remaining));
            }
        }

        if ($failure !== null) {
            $this->reportFailure('Sereno log delivery failed', $failure['context']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @return array{transient: bool, context: array<string, mixed>}|null
     */
    private function attempt(array $batch, int|float $timeout): ?array
    {
        try {
            $response = Http::withToken($this->key)
                ->acceptJson()
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->post($this->endpoint, ['events' => $batch]);

            if ($response->successful()) {
                return null;
            }

            return [
                'transient' => $response->serverError(),
                'context' => [
                    'status' => $response->status(),
                    'events' => count($batch),
                    'body' => mb_substr($response->body(), 0, 500),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'transient' => $e instanceof ConnectionException,
                'context' => [
                    'error' => $e->getMessage(),
                    'events' => count($batch),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reportFailure(string $message, array $context): void
    {
        $wasSending = self::$sending;
        self::$sending = true;

        try {
            ($this->fallback ?? Log::channel($this->fallbackChannel))->warning($message, $context);
        } catch (Throwable) {
        } finally {
            self::$sending = $wasSending;
        }
    }

    /**
     * Queue workers never terminate between jobs, so the buffer is also flushed on each worker loop.
     * Fatal errors skip terminate() and __destruct(): the shutdown function registered here runs after
     * Laravel's own and sends what its report left in the buffer.
     * Callbacks hold a weak reference so they never keep the handler alive.
     */
    private function registerFlushPoints(?Closure $shutdownRegistrar): void
    {
        $handlerRef = WeakReference::create($this);
        $flush = static function () use ($handlerRef): void {
            try {
                $handlerRef->get()?->flush();
            } catch (Throwable) {
            }
        };

        try {
            ($shutdownRegistrar ?? register_shutdown_function(...))($flush);
        } catch (Throwable) {
        }

        try {
            $app = app();

            if (! $app instanceof Application) {
                return;
            }

            $app->terminating($flush);
            Event::listen(Looping::class, $flush);
        } catch (Throwable) {
        }
    }
}
