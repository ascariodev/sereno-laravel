<?php

namespace Ascario\Sereno\Logging;

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

        $handler = new SerenoLogHandler(
            config('sereno.url'),
            config('sereno.key'),
            Level::fromName(ucfirst(strtolower((string) ($config['level'] ?? config('sereno.level') ?? 'debug')))),
            timeout: config('sereno.timeout'),
            fallbackChannel: $fallbackChannel === '' ? SerenoLogHandler::DEFAULT_FALLBACK_CHANNEL : $fallbackChannel,
        );

        return new Logger('sereno', [$handler]);
    }
}
