<?php

namespace App\Http\Middleware;

use App\Auth\AccountActivity;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Validated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps deactivated accounts out. Sign-in is refused once the password checks out (so the message does not tell a
 * stranger which emails exist), and a session that was open when the account was deactivated ends on its next request.
 */
class EnsureAccountIsActive
{
    public const DEACTIVATED_MESSAGE = 'This account has been deactivated. Please contact the administrator if you need access again.';

    /**
     * Listener for Illuminate\Auth\Events\Validated: the password was right, and the person is about to be signed in.
     */
    public static function refuseSignIn(Validated $event): void
    {
        if ($event->user instanceof User && $event->user->isDeactivated()) {
            AccountActivity::record(AccountActivity::DEACTIVATED_SIGN_IN, $event->user);

            throw ValidationException::withMessages(['email' => self::DEACTIVATED_MESSAGE]);
        }
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard(config('platform.guard'));
        $user = $guard->user();

        if (! $user instanceof User || ! $user->isDeactivated()) {
            return $next($request);
        }

        $request->attributes->set(AccountActivity::SIGN_OUT_REASON, 'deactivated');
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => self::DEACTIVATED_MESSAGE], 401);
        }

        return redirect()->route('platform.login')->withErrors(['email' => self::DEACTIVATED_MESSAGE]);
    }
}
