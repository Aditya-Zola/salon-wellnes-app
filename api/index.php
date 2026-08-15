<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log('[vercel-bootstrap] Fatal PHP error: '.json_encode([
        'type' => $error['type'],
        'message' => $error['message'],
        'file' => $error['file'],
        'line' => $error['line'],
    ], JSON_UNESCAPED_SLASHES));
});

// Vercel Functions only provide a writable /tmp directory. Laravel needs a
// writable storage path for compiled Blade views and other temporary files.
$storagePath = '/tmp/laravel-storage';

foreach ([
    $storagePath.'/framework/cache/data',
    $storagePath.'/framework/sessions',
    $storagePath.'/framework/testing',
    $storagePath.'/framework/views',
    $storagePath.'/logs',
] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->useStoragePath($storagePath);

$app->handleRequest(Request::capture());
