<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks the staff login for one email on one computer after five failed attempts; the person waits a minute and
 * tries again. Orchid's login controller is in vendor, so this wraps its route instead.
 */
class LimitLoginAttempts
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 60;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('post') || ! $request->routeIs('platform.login.auth')) {
            return $next($request);
        }

        $key = 'login:'.Str::lower(trim((string) $request->input('email'))).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Too many failed sign-in attempts. Please wait {$seconds} ".Str::plural('second', $seconds).' and try again.',
            ]);
        }

        $response = $next($request);

        if (Auth::guard(config('platform.guard', 'web'))->check()) {
            RateLimiter::clear($key);
        } else {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
        }

        return $response;
    }
}
