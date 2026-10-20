<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;

class NotificationEmailVerificationController extends Controller
{
    public function __invoke(User $user, string $hash): Response
    {
        abort_unless(
            filled($user->notification_email)
                && hash_equals(hash('sha256', $user->notification_email), $hash),
            403,
        );

        if ($user->notification_email_verified_at === null) {
            $user->forceFill(['notification_email_verified_at' => now()])->save();
        }

        return response('Email notifications enabled. You may close this page.');
    }
}
