<?php

return [
    'url' => env('SERENO_LOG_URL'),
    'key' => env('SERENO_LOG_KEY'),
    'timeout' => env('SERENO_LOG_TIMEOUT', 2),
    'level' => env('SERENO_LOG_LEVEL', 'debug'),
    'fallback_channel' => env('SERENO_LOG_FALLBACK_CHANNEL', 'daily'),
];
