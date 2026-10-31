<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use App\Models\User;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Password;
use Orchid\Screen\Layouts\Rows;

class ProfilePasswordLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Password::make('old_password')
                ->placeholder(__('Enter the current password'))
                ->title(__('Current password'))
                ->help('Enter the current password.'),

            Password::make('password')
                ->placeholder(__('Enter the password to be set'))
                ->title(__('New password')),

            Password::make('password_confirmation')
                ->placeholder(__('Enter the password to be set'))
                ->title(__('Confirm new password'))
                ->help(User::PASSWORD_HINT),
        ];
    }
}
