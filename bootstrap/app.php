<?php

use App\Http\Middleware\AddSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every web page, public and staff alike, gets the security headers and Content-Security-Policy.
        $middleware->web(append: [AddSecurityHeaders::class]);
        $middleware->redirectGuestsTo(fn (): string => route('platform.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();

        // Vercel only keeps stderr, so log a compact line per exception there instead of to a file.
        $exceptions->report(function (Throwable $exception): false {
            error_log(sprintf(
                'VERCEL_EXCEPTION=%s: %s in %s:%s',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
