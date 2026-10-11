<?php

namespace Sereno\Laravel\Logging;

use Monolog\Level;
use Monolog\Logger;

class CreateSerenoLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $fallbackChannel = trim((string) config('sereno.fallback_channel'));

        $levelName = trim((string) ($config['level'] ?? ''));
        if ($levelName === '') {
            $levelName = trim((string) config('sereno.level'));
        }

        $handler = new SerenoLogHandler(
            config('sereno.url'),
            config('sereno.key'),
            Level::fromName(ucfirst(strtolower($levelName === '' ? 'debug' : $levelName))),
            timeout: config('sereno.timeout'),
            fallbackChannel: $fallbackChannel === '' ? SerenoLogHandler::DEFAULT_FALLBACK_CHANNEL : $fallbackChannel,
        );

        return new Logger('sereno', [$handler]);
    }
}
