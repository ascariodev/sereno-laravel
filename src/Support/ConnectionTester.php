<?php

namespace Sereno\Laravel\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Sereno\Laravel\Logging\EventFormatter;
use Throwable;

class ConnectionTester
{
    public const CONNECTED = 'connected';

    public const UNAUTHORIZED = 'unauthorized';

    public const NO_URL = 'no_url';

    public const NO_KEY = 'no_key';

    public const FAILED = 'failed';

    public const TIMEOUT_SECONDS = 5;

    private const INGEST_PATH = '/api/ingest/events';

    public function __construct(private readonly EventFormatter $formatter = new EventFormatter) {}

    /**
     * @return array{status: string, detail: ?string}
     */
    public function test(?string $url, ?string $key): array
    {
        $url = trim((string) $url);
        $key = trim((string) $key);

        if ($url === '') {
            return ['status' => self::NO_URL, 'detail' => null];
        }

        if ($key === '') {
            return ['status' => self::NO_KEY, 'detail' => null];
        }

        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'sereno',
            level: Level::Info,
            message: 'Sereno connection test',
        );

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(rtrim($url, '/').self::INGEST_PATH, ['events' => [$this->formatter->format($record)]]);
        } catch (Throwable $e) {
            return ['status' => self::FAILED, 'detail' => $e->getMessage()];
        }

        if ($response->status() === 202) {
            return ['status' => self::CONNECTED, 'detail' => null];
        }

        if ($response->status() === 401) {
            return ['status' => self::UNAUTHORIZED, 'detail' => null];
        }

        return ['status' => self::FAILED, 'detail' => 'HTTP '.$response->status()];
    }
}
