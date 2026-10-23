<?php

namespace App\Http\Middleware;

use App\Auth\AccountActivity;
use App\Models\User;
use App\Notifications\RepeatedSignInLockoutNotification;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks the staff login for one email on one computer after five failed attempts. Each lockout within a day lasts
 * longer (1, then 5, then 15 minutes), and the third one alerts the administrators. A successful sign-in starts the
 * count over. Orchid's login controller is in vendor, so this wraps its route instead.
 */
class LimitLoginAttempts
{
    public const MAX_ATTEMPTS = 5;

    public const MAX_IP_ATTEMPTS = 100;

    // How long the 1st, 2nd and 3rd (and every later) lockout of the day lasts.
    public const LOCKOUT_STEPS = [60, 300, 900];

    // Failed attempts more than this far apart do not add up to a lockout.
    public const ATTEMPT_WINDOW_SECONDS = 900;

    // Lockouts are counted for a day; the alert goes out when the count reaches this.
    public const ALERT_AFTER_LOCKOUTS = 3;

    public const LOCKOUT_MEMORY_SECONDS = 86400;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('post') || ! $request->routeIs('platform.login.auth')) {
            return $next($request);
        }

        $email = Str::lower(trim((string) $request->input('email')));
        $key = 'login:'.$email.'|'.$request->ip();
        $ipKey = 'login-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_IP_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => self::lockedMessage(RateLimiter::availableIn($ipKey))]);
        }

        $lockedUntil = (int) Cache::get($key.':locked-until', 0);
        if ($lockedUntil > now()->getTimestamp()) {
            $seconds = $lockedUntil - now()->getTimestamp();

            throw ValidationException::withMessages(['email' => self::lockedMessage($seconds)]);
        }

        $response = $next($request);

        $guard = Auth::guard(config('platform.guard', 'web'));
        if ($guard->check()) {
            RateLimiter::clear($key);
            Cache::forget($key.':lockouts');

            if ($guard->user() instanceof User) {
                RequirePasswordChange::checkSignInPassword($guard->user(), (string) $request->input('password'));
            }

            return $response;
        }

        RateLimiter::hit($key, self::ATTEMPT_WINDOW_SECONDS);
        RateLimiter::hit($ipKey, self::ATTEMPT_WINDOW_SECONDS);

        if (RateLimiter::attempts($key) >= self::MAX_ATTEMPTS) {
            $this->lockOut($key, $email);
        }

        return $response;
    }

    public static function lockedMessage(int $seconds): string
    {
        $wait = $seconds > 90
            ? (int) ceil($seconds / 60).' minutes'
            : $seconds.' '.Str::plural('second', $seconds);

        return "Too many failed sign-in attempts. Please wait {$wait} and try again. If you forgot your password, use \"Forgot your password?\" below or ask the administrator to set a new one.";
    }

    private function lockOut(string $key, string $email): void
    {
        RateLimiter::clear($key);

        $lockouts = (int) Cache::get($key.':lockouts', 0) + 1;
        Cache::put($key.':lockouts', $lockouts, self::LOCKOUT_MEMORY_SECONDS);

        $seconds = self::LOCKOUT_STEPS[min($lockouts, count(self::LOCKOUT_STEPS)) - 1];
        Cache::put($key.':locked-until', now()->getTimestamp() + $seconds, $seconds);

        $account = User::query()->where('email', $email)->first();
        AccountActivity::record(AccountActivity::LOCKED_OUT, $account, null, [
            'email' => Str::limit($email, 255, ''),
            'lockout' => $lockouts,
            'minutes' => (int) ceil($seconds / 60),
        ]);

        if ($account !== null && $lockouts === self::ALERT_AFTER_LOCKOUTS) {
            $administrators = User::query()
                ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('slug', User::ADMIN_ROLE_SLUG))
                ->active()
                ->get();

            Notification::send($administrators, new RepeatedSignInLockoutNotification($email, (string) request()->ip(), $lockouts));
        }
    }
}
