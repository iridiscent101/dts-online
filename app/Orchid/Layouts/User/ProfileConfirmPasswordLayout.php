<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Password;
use Orchid\Screen\Layouts\Rows;

class ProfileConfirmPasswordLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Password::make('current_password')
                ->placeholder(__('Enter the current password'))
                ->title(__('Current password'))
                ->help(__('Needed only when you change the sign-in or recovery email.')),
        ];
    }
}
