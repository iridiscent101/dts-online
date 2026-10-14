<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use App\Models\User;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class UserEditLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        /** @var User|null $user */
        $user = $this->query->get('user');
        $isCreating = ! $user?->exists;

        return [
            Input::make('user.name')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Name'))
                ->placeholder(__('Name'))
                ->autocomplete('off')
                ->when($isCreating, fn (Input $field): Input => $field->value('')),

            Input::make('user.email')
                ->type('email')
                ->required()
                ->title(__('Email'))
                ->placeholder(__('Email'))
                ->autocomplete('off')
                ->when($isCreating, fn (Input $field): Input => $field->value('')),
        ];
    }
}
