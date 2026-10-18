<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The kiosk laptop's account has only the "Signing kiosk" permission. When it lands on a staff page (for example right
 * after signing in at /admin/login), send it to the kiosk screen instead of an "access denied" page. Signing out still
 * works.
 */
class SendKioskAccountsToKiosk
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && ! $request->routeIs('platform.logout')
            && ! $user->hasAccess('platform.index')
            && $user->hasAccess('platform.kiosk')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This account is for the signing kiosk.'], 403)
                : redirect()->route('kiosk');
        }

        return $next($request);
    }
}
