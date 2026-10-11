<?php

namespace Sereno\Laravel\Console;

use Illuminate\Console\Command;
use Sereno\Laravel\Support\ConnectionTester;

class InstallCommand extends Command
{
    protected $signature = 'sereno:install {key : Source key (wsk_...)} {--url= : Sereno ingest URL} {--no-test : Skip the test event}';

    protected $description = 'Configure Sereno in the .env file and add it to the log stack';

    public function handle(ConnectionTester $tester): int
    {
        $key = (string) $this->argument('key');
        $url = $this->option('url');

        if (preg_match('/\Awsk_[A-Za-z0-9]+\z/', $key) !== 1) {
            $this->error('Invalid key: it must start with wsk_ followed by letters and digits.');

            return self::FAILURE;
        }

        if ($url !== null && ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/[$"\'`\\\\]/', $url) === 1)) {
            $this->error('Invalid URL.');

            return self::FAILURE;
        }

        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            $this->error("Cannot write the environment file at {$path}. Create it (cp .env.example .env) and try again.");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        $contents = $this->setVariable($contents, 'SERENO_KEY', $key);

        if ($url !== null) {
            $contents = $this->setVariable($contents, 'SERENO_URL', $url);
        }

        $contents = $this->setVariable($contents, 'LOG_STACK', $this->stackWithSereno($contents));

        file_put_contents($path, $contents);

        $this->info('Sereno configured in '.basename($path).'.');

        $channel = $this->readVariable($contents, 'LOG_CHANNEL');

        if ($channel !== null && $channel !== 'stack') {
            $this->warn("LOG_CHANNEL is \"{$channel}\", not \"stack\": add the \"sereno\" channel to your logging setup by hand.");
        }

        if ($this->option('no-test')) {
            return self::SUCCESS;
        }

        $effectiveUrl = $url ?? $this->readVariable($contents, 'SERENO_URL') ?? config('sereno.url');

        TestCommand::report($this, $tester->test($effectiveUrl, $key));

        return self::SUCCESS;
    }

    private function stackWithSereno(string $contents): string
    {
        $current = $this->readVariable($contents, 'LOG_STACK') ?? 'single';

        $channels = array_values(array_filter(
            array_map('trim', explode(',', $current)),
            fn (string $channel): bool => $channel !== '',
        ));

        if (! in_array('sereno', $channels, true)) {
            $channels[] = 'sereno';
        }

        return implode(',', $channels);
    }

    private function readVariable(string $contents, string $name): ?string
    {
        if (preg_match_all('/^'.$name.'=([^\r\n]*)(?=\r?$)/m', $contents, $matches) < 1) {
            return null;
        }

        return trim(trim(end($matches[1])), '"\'');
    }

    private function setVariable(string $contents, string $name, string $value): string
    {
        $line = $name.'='.(preg_match('/\A[A-Za-z0-9_.,:\/@%+-]*\z/', $value) === 1 ? $value : '"'.addcslashes($value, '"\\').'"');

        $count = 0;
        $replaced = preg_replace_callback(
            '/^'.$name.'=[^\r\n]*(?=\r?$)/m',
            fn (): string => $line,
            $contents,
            -1,
            $count,
        );

        if ($count > 0) {
            return $replaced;
        }

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $separator = $contents === '' || str_ends_with($contents, "\n") ? '' : $eol;

        return $contents.$separator.$line.$eol;
    }
}
