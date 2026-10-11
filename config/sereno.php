<?php

use Sereno\Laravel\SerenoServiceProvider;

return [
    'url' => env('SERENO_URL', env('SERENO_LOG_URL', SerenoServiceProvider::DEFAULT_URL)),
    'key' => env('SERENO_KEY', env('SERENO_LOG_KEY')),
    'timeout' => env('SERENO_TIMEOUT', env('SERENO_LOG_TIMEOUT', 2)),
    'level' => env('SERENO_LEVEL', env('SERENO_LOG_LEVEL', 'debug')),
    'fallback_channel' => env('SERENO_FALLBACK_CHANNEL', env('SERENO_LOG_FALLBACK_CHANNEL', 'daily')),
];
