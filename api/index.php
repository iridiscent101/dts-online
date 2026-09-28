<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/up') {
    header('Content-Type: text/plain; charset=UTF-8');

    echo 'Application up';

    return;
}

if ($path === '/_deploy-check') {
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'commit' => $_ENV['VERCEL_GIT_COMMIT_SHA'] ?? $_SERVER['VERCEL_GIT_COMMIT_SHA'] ?? null,
        'branch' => $_ENV['VERCEL_GIT_COMMIT_REF'] ?? $_SERVER['VERCEL_GIT_COMMIT_REF'] ?? null,
        'environment' => $_ENV['VERCEL_ENV'] ?? $_SERVER['VERCEL_ENV'] ?? null,
    ]);

    return;
}

if ($path === '/favicon.ico') {
    http_response_code(204);

    return;
}

if (str_ends_with((string) ($_SERVER['HTTP_HOST'] ?? ''), '.vercel.app')) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
}

require __DIR__.'/../vendor/autoload.php';

$storage = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'storage';
$bootstrapCache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';

$_ENV['LARAVEL_STORAGE_PATH'] = $storage;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
$_ENV['LOG_CHANNEL'] ??= 'stderr';
$_SERVER['LOG_CHANNEL'] ??= 'stderr';

foreach ([
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '12',
    'FILESYSTEM_DISK' => 'local',
    'PLATFORM_FILESYSTEM_DISK' => 'public',
] as $key => $value) {
    if (empty($_ENV[$key]) && empty($_SERVER[$key])) {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}

foreach ([
    'APP_SERVICES_CACHE' => $bootstrapCache.DIRECTORY_SEPARATOR.'services.php',
    'APP_PACKAGES_CACHE' => $bootstrapCache.DIRECTORY_SEPARATOR.'packages.php',
    'APP_CONFIG_CACHE' => $bootstrapCache.DIRECTORY_SEPARATOR.'config.php',
    'APP_ROUTES_CACHE' => $bootstrapCache.DIRECTORY_SEPARATOR.'routes.php',
    'APP_EVENTS_CACHE' => $bootstrapCache.DIRECTORY_SEPARATOR.'events.php',
] as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}

register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf(
            'VERCEL_FATAL=%s in %s:%s',
            $error['message'],
            $error['file'],
            $error['line'],
        ));
    }
});

$directories = [
    $storage,
    $bootstrapCache,
    $storage.'/app',
    $storage.'/framework',
    $storage.'/framework/cache',
    $storage.'/framework/cache/data',
    $storage.'/framework/sessions',
    $storage.'/framework/views',
    $storage.'/logs',
];

foreach ($directories as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

try {
    $app = require_once __DIR__.'/../bootstrap/app.php';

    register_shutdown_function(function (): void {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log(sprintf(
                'VERCEL_LAST_FATAL=%s in %s:%s',
                $error['message'],
                $error['file'],
                $error['line'],
            ));
        }
    });

    $app->handleRequest(Request::capture());
} catch (Throwable $exception) {
    error_log(sprintf('VERCEL_EXCEPTION=%s: %s', $exception::class, $exception->getMessage()));
    error_log((string) $exception);

    throw $exception;
}
