<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\School;

use App\Models\School;
use App\Orchid\Layouts\Concerns\HasTableSelectionColumn;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class SchoolListLayout extends Table
{
    use HasTableSelectionColumn;

    /**
     * @var string
     */
    public $target = 'schools';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            $this->selectColumn('Select school'),

            $this->numberColumn(),

            TD::make('name', __('School name'))
                ->sort()
                ->cantHide(),

            TD::make('updated_at', __('Last updated'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            // Schools come from the DepEd catalog (each has a source unit ID), so there is no "Add"; rows can be
            // renamed in a dialog or removed.
            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('112px')
                ->cantHide()
                ->render(fn (School $school) => view('orchid.cells.admin-row-actions', [
                    'editAction' => ModalToggle::make(__('Edit'))
                        ->class('btn dts-row-action dts-row-action-edit')
                        ->icon('bs.pencil')
                        ->modal('editSchoolModal')
                        ->modalTitle(__('Edit school'))
                        ->method('saveSchool')
                        ->asyncParameters(['school' => $school->id]),
                    'deleteAction' => Button::make(__('Delete'))
                        ->class('btn dts-row-action dts-row-action-delete')
                        ->icon('bs.trash3')
                        ->confirm(__('Remove :school from the school list? Documents already registered keep their recorded origin.', ['school' => $school->name]))
                        ->method('remove', ['id' => $school->id]),
                ])),
        ];
    }
}
