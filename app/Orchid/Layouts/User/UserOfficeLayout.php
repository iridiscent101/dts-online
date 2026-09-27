<?php

namespace App\Orchid\Layouts\User;

use App\Models\Document;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class UserOfficeLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('user.office')
                ->options(array_combine(Document::OFFICES, Document::OFFICES))
                ->empty('Not assigned')
                ->title(__('Office assignment'))
                ->help('Assign the office whose document queue this staff member will handle.'),
        ];
    }
}
