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

        // Behind a proxy every request arrives from the proxy's address, so the sign-in limits (LimitLoginAttempts)
        // would count all staff as one computer. On Vercel each request comes through Vercel's edge, which sets the
        // client's address in X-Forwarded-For itself, so it is trusted there. Elsewhere nothing is trusted unless
        // TRUSTED_PROXIES lists the proxy (a server reached directly must not let clients fake their address).
        $trustedProxies = env('TRUSTED_PROXIES', env('VERCEL') ? '*' : null);
        if (filled($trustedProxies)) {
            // Only the client address, scheme and port are taken from the proxy, never the host: links must not follow a
            // forwarded host header that a client could set.
            $middleware->trustProxies(
                at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)),
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
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
