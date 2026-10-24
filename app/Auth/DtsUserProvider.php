<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Staff sign-in: the real password, or a pending temporary password from forgot password. Both work until one of them
 * is used, so someone asking for a colleague's reset cannot lock the colleague out (User::settleTemporaryPasswordAfterSignIn
 * then keeps whichever was used).
 */
class DtsUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if (parent::validateCredentials($user, $credentials)) {
            return true;
        }

        $plain = $credentials['password'] ?? null;

        if ($user instanceof User && is_string($plain) && $user->temporaryPasswordMatches($plain)) {
            $user->signedInWithTemporaryPassword = true;

            return true;
        }

        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        // The typed password is the temporary one, which replaces the stored password after sign-in anyway.
        if ($user instanceof User && $user->signedInWithTemporaryPassword) {
            return;
        }

        parent::rehashPasswordIfRequired($user, $credentials, $force);
    }
}
