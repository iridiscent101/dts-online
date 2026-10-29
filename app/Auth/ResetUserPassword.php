<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        abort_if($user->isDeactivated() || ! $user->canChangeOwnPassword() || ! $user->notification_email_verified_at, 403);
        Validator::make($input, ['password' => ['required', 'confirmed', Password::defaults()]])->validate();
        $user->forceFill(['password' => Hash::make($input['password']), 'must_change_password' => false])->save();
        AccountActivity::record(AccountActivity::PASSWORD_CHANGED, $user, $user);
    }
}
