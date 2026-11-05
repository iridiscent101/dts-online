<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orchid\Platform\Http\Controllers\LoginController as OrchidLoginController;

/**
 * Orchid's login controller, with logout sending staff back to the sign-in page instead of the public tracking page.
 * Bound over Orchid's controller in AppServiceProvider, so the platform.logout route keeps its name and URL.
 */
class LoginController extends OrchidLoginController
{
    public function logout(Request $request)
    {
        $this->guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->wantsJson()
            ? new JsonResponse([], 204)
            : redirect()->route('platform.login');
    }
}
