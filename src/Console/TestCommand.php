<?php

namespace Sereno\Laravel\Console;

use Illuminate\Console\Command;
use Sereno\Laravel\Support\ConnectionTester;

class TestCommand extends Command
{
    protected $signature = 'sereno:test';

    protected $description = 'Send a test event to Sereno using the configured key and URL';

    public function handle(ConnectionTester $tester): int
    {
        return self::report($this, $tester->test(config('sereno.url'), config('sereno.key')));
    }

    /**
     * @param  array{status: string, detail: ?string}  $result
     */
    public static function report(Command $command, array $result): int
    {
        switch ($result['status']) {
            case ConnectionTester::CONNECTED:
                $command->info('Connected: Sereno accepted the test event.');

                return Command::SUCCESS;
            case ConnectionTester::UNAUTHORIZED:
                $command->error('Sereno rejected the key (401): it is invalid or has been revoked.');

                return Command::FAILURE;
            case ConnectionTester::NO_URL:
                $command->error('The Sereno URL is missing: set SERENO_URL (or pass --url to sereno:install). No event was sent.');

                return Command::FAILURE;
            case ConnectionTester::NO_KEY:
                $command->error('The Sereno key is missing: set SERENO_KEY. No event was sent.');

                return Command::FAILURE;
            default:
                $command->error('Could not deliver the test event: '.($result['detail'] ?? 'unknown error').'.');

                return Command::FAILURE;
        }
    }
}
