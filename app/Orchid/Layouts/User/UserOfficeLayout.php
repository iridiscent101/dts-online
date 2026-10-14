<?php

namespace App\Orchid\Layouts\User;

use App\Models\Office;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
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
        // Only administrators can change office assignments; the server rejects edits from anyone else.
        $canAssign = (bool) request()->user()?->isAdministrator();

        return [
            Input::make('user.offices_submitted')
                ->type('hidden')
                ->value('1')
                ->canSee($canAssign),

            Select::make('user.office_ids.')
                ->fromQuery(
                    Office::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name'),
                    'name',
                )
                ->multiple()
                ->title(__('Assigned offices'))
                ->placeholder(__($canAssign ? 'Select offices' : 'No offices assigned'))
                ->disabled(! $canAssign)
                ->help($canAssign
                    ? 'User can view and act on documents currently assigned to any selected office.'
                    : 'User can view and act on documents assigned to these offices. Only an administrator can change them.'),
        ];
    }
}
