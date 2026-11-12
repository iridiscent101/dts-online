<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        abort_if($user->isDeactivated() || ! $user->canChangeOwnPassword() || ! $user->notification_email_verified_at, 403);
        Validator::make($input, [
            'password' => ['required', 'confirmed', Password::defaults()],
            'totp_code' => [filled($user->password_recovery_totp_secret) ? 'required' : 'nullable', 'digits:6'],
        ], [], ['totp_code' => 'authenticator code'])->validate();
        if (filled($user->password_recovery_totp_secret)
            && ! app(TwoFactorAuthenticationProvider::class)->verify($user->password_recovery_totp_secret, $input['totp_code'])) {
            throw ValidationException::withMessages(['totp_code' => 'The authenticator code is invalid or expired.']);
        }
        $user->forceFill(['password' => Hash::make($input['password']), 'must_change_password' => false])->save();
        AccountActivity::record(AccountActivity::PASSWORD_CHANGED, $user, $user);
    }
}
