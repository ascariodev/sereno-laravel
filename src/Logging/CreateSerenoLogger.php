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

        $levelName = $levelName === '' ? 'debug' : $levelName;
        try {
            $level = Level::fromName(ucfirst(strtolower($levelName)));
        } catch (\UnhandledMatchError) {
            throw new \InvalidArgumentException("Invalid Sereno log level \"{$levelName}\".");
        }

        $handler = new SerenoLogHandler(
            config('sereno.url'),
            config('sereno.key'),
            $level,
            timeout: config('sereno.timeout'),
            fallbackChannel: $fallbackChannel === '' ? SerenoLogHandler::DEFAULT_FALLBACK_CHANNEL : $fallbackChannel,
        );

        return new Logger('sereno', [$handler]);
    }
}
