<?php

namespace App\Http\Middleware;

use App\Auth\AccountActivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs staff out after a stretch without activity (config session.idle_minutes), even with "Remember me" on, so an
 * unattended office computer does not stay signed in. The browser warns first (resources/js/dts.js, IdleSignOut).
 */
class LogoutInactiveStaff
{
    public const SESSION_KEY = 'dts_last_activity';

    public const SIGNED_OUT_MESSAGE = 'You were signed out because there was no activity for a while. Sign in again to continue.';

    // Requests the page makes on its own; they must not keep an idle session alive.
    private const BACKGROUND_ROUTES = ['platform.api.notifications'];

    public static function idleSeconds(): int
    {
        return max(1, (int) config('session.idle_minutes', 15)) * 60;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard(config('platform.guard'))->check()) {
            return $next($request);
        }

        $lastActivity = $request->session()->get(self::SESSION_KEY);

        if ($lastActivity !== null && now()->getTimestamp() - (int) $lastActivity >= self::idleSeconds()) {
            return self::signOut($request);
        }

        if (! $request->routeIs(...self::BACKGROUND_ROUTES)) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        }

        return $next($request);
    }

    public static function signOut(Request $request): Response
    {
        $request->attributes->set(AccountActivity::SIGN_OUT_REASON, 'inactivity');
        Auth::guard(config('platform.guard'))->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => self::SIGNED_OUT_MESSAGE], 401);
        }

        return redirect()->route('platform.login')->with('dts_signed_out', self::SIGNED_OUT_MESSAGE);
    }
}
