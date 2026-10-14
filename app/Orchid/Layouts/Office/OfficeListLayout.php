<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Office;

use App\Models\Office;
use App\Orchid\Layouts\Concerns\HasTableSelectionColumn;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class OfficeListLayout extends Table
{
    use HasTableSelectionColumn;

    /**
     * @var string
     */
    public $target = 'offices';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            $this->selectColumn('Select office', fn (Office $office): bool => ! $office->canBeDeleted()),

            $this->numberColumn(),

            TD::make('name', __('Name'))
                ->sort()
                ->cantHide(),

            TD::make('is_sdo', __('SDO'))
                ->align(TD::ALIGN_CENTER)
                ->render(fn (Office $office): string => $office->is_sdo ? __('Yes') : __('No')),

            TD::make('is_client', __('Client'))
                ->align(TD::ALIGN_CENTER)
                ->render(fn (Office $office): string => $office->is_client ? __('Yes') : __('No')),

            TD::make('is_active', __('Active'))
                ->align(TD::ALIGN_CENTER)
                ->sort()
                ->render(fn (Office $office): string => $office->is_active ? __('Yes') : __('No')),

            TD::make('updated_at', __('Last updated'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('112px')
                ->cantHide()
                ->render(fn (Office $office) => view('orchid.cells.admin-row-actions', [
                    'editAction' => Link::make(__('Edit'))
                        ->class('btn dts-row-action dts-row-action-edit')
                        ->route('platform.systems.offices.edit', $office->id)
                        ->rawClick(false)
                        ->icon('bs.pencil'),
                    // The Records Unit receives every paper submission and can never be deleted.
                    'deleteAction' => $office->name === 'SDO-Records Unit' ? null : Button::make(__('Delete'))
                        ->class('btn dts-row-action dts-row-action-delete')
                        ->icon('bs.trash3')
                        ->confirm(__('Delete :office? Only an office never used by documents or staff can be deleted; deactivate it otherwise.', ['office' => $office->name]))
                        ->method('remove', ['id' => $office->id]),
                ])),
        ];
    }
}
