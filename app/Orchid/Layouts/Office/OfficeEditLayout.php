<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Office;

use App\Models\DocumentMovement;
use App\Models\Office;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class OfficeEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $nameLock = $this->nameLockReason($this->query->get('office'));

        return [
            Input::make('office.name')
                ->type('text')
                ->max(180)
                ->required()
                ->readonly($nameLock !== null)
                ->title(__('Name'))
                ->placeholder(__('Office name'))
                ->help($nameLock ?? __('This is the office name shown in document routing and personnel assignment lists.')),

            Input::make('office.sort_order')
                ->type('number')
                ->min(0)
                ->max(65535)
                ->required()
                ->title(__('General sort order'))
                ->help(__('Lower numbers appear first in shared office lists.')),

            Input::make('office.sdo_sort_order')
                ->type('number')
                ->min(1)
                ->max(65535)
                ->title(__('SDO sort order'))
                ->help(__('Optional ordering from the SDO office list.')),

            Input::make('office.client_sort_order')
                ->type('number')
                ->min(1)
                ->max(65535)
                ->title(__('Client sort order'))
                ->help(__('Optional ordering from the client office list.')),

            CheckBox::make('office.is_sdo')
                ->sendTrueOrFalse()
                ->title(__('Included in SDO list')),

            CheckBox::make('office.is_client')
                ->sendTrueOrFalse()
                ->title(__('Included in client list')),

            CheckBox::make('office.is_active')
                ->sendTrueOrFalse()
                ->title(__('Active'))
                ->help(__('Inactive offices stay in history but are hidden from new assignment dropdowns.')),
        ];
    }

    /**
     * Why the name cannot change, matching the checks in OfficeEditScreen::save(); null when it can.
     */
    private function nameLockReason(?Office $office): ?string
    {
        if ($office === null || ! $office->exists) {
            return null;
        }

        if (in_array($office->name, Office::RECORDS_UNIT_NAMES, true)) {
            return __('The Records intake office cannot be renamed.');
        }

        $hasHistory = DocumentMovement::query()
            ->where(fn ($movements) => $movements->where('from_office', $office->name)->orWhere('to_office', $office->name))
            ->exists();

        return $hasHistory
            ? __('This office cannot be renamed because documents have already moved through it; their history records the office by name.')
            : null;
    }
}
