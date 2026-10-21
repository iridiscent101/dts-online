<?php

namespace App\Http\Middleware;

use App\Auth\AccountActivity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passwords set before the current rule (13+ characters, capital, number, symbol) keep working only until the next
 * sign-in: the password typed there is checked, and if it falls short the person is sent to My Account and can use
 * nothing else until they set a new one. Staff whose password already meets the rule are never asked.
 */
class RequirePasswordChange
{
    public const MESSAGE = 'Please set a new password before you continue. The password rules have changed, and your current password does not meet them.';

    // My Account (to change it), signing out, and the idle timer's own background requests stay open.
    private const ALLOWED_ROUTES = [
        'platform.profile',
        'platform.logout',
        'platform.session.keep-alive',
        'platform.session.idle-sign-out',
        'platform.api.notifications',
    ];

    /**
     * Called by LimitLoginAttempts right after a successful sign-in, while the typed password is still at hand.
     */
    public static function checkSignInPassword(User $user, string $password): void
    {
        if (! $user->canChangeOwnPassword()) {
            return;
        }

        $meetsRule = Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes();

        if (! $meetsRule && ! $user->must_change_password) {
            $user->forceFill(['must_change_password' => true])->save();
            AccountActivity::record(AccountActivity::PASSWORD_CHANGE_REQUIRED, $user, $user);
        }
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()->route('platform.profile');
    }
}
