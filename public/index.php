<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';

if (is_file($envFile)) {
    Env::load($envFile);
}

try {
    $container = require dirname(__DIR__) . '/bootstrap/app.php';

    /** @var Application $application */
    $application = $container->get(Application::class);

    $response = $application->handle(Request::fromGlobals());
} catch (Throwable $exception) {
    error_log(sprintf(
        '[bootstrap] %s: %s in %s:%d',
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    $response = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
        ? Response::error('server_error', 'Something went wrong.', 500)
        : Response::html(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Vending Machine</title></head>'
            . '<body><main><h1>Something went wrong</h1><p>The application could not start. Please try again later.</p></main></body></html>',
            500
        );
}

$response->send();
