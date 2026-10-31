<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use App\Models\User;
use App\Orchid\Layouts\Concerns\HasTableSelectionColumn;
use App\Support\DisplayDate;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class UserListLayout extends Table
{
    use HasTableSelectionColumn;

    /**
     * @var string
     */
    public $target = 'users';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            $this->selectColumn(
                'Select user',
                fn (User $user): bool => $user->id === auth()->id() || $user->isAdministrator(),
                fn (User $user): bool => $user->isDeactivated(),
            ),

            $this->numberColumn(),

            TD::make('name', __('Name'))
                ->sort()
                ->cantHide(),

            TD::make('email', __('Email'))
                ->sort()
                ->cantHide(),

            TD::make('roles', __('Role'))
                ->render(fn (User $user): string => e($user->roles->pluck('name')->join(', ') ?: __('No role'))),

            // Offices come from memberships, which the list cannot sort by.
            TD::make('office', __('Office'))
                ->render(fn (User $user): string => e($user->assignedOfficeNames()->join(', ') ?: __('Not assigned'))),

            TD::make('deactivated_at', __('Status'))
                // The same badges as document statuses: green for active, grey with a stop mark for deactivated.
                ->render(fn (User $user): string => $user->isDeactivated()
                    ? '<span class="dts-record-status" data-status="cancelled">'.e(__('Deactivated')).'</span>'
                    : '<span class="dts-record-status" data-status="received">'.e(__('Active')).'</span>'),

            TD::make('created_at', __('Date created'))
                ->render(fn ($row): string => (string) DisplayDate::cell($row->created_at))
                ->sort(),

            // Hidden by default so the table fits a laptop screen; the column menu can show it.
            TD::make('updated_at', __('Last updated'))
                ->render(fn ($row): string => (string) DisplayDate::cell($row->updated_at))
                ->defaultHidden()
                ->sort(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('112px')
                ->cantHide()
                ->render(fn (User $user) => view('orchid.cells.admin-row-actions', [
                    'editAction' => Link::make(__('Edit'))
                        ->class('btn dts-row-action dts-row-action-edit')
                        ->route('platform.systems.users.edit', $user->id)
                        ->rawClick(false)
                        ->icon('bs.pencil'),
                    // Permanent deletion is available from the edit page only after deactivation and data transfer.
                    'deleteAction' => $user->id === auth()->id() || $user->isAdministrator()
                        ? null
                        : ($user->isDeactivated()
                            ? Button::make(__('Reactivate'))
                                ->class('btn dts-row-action dts-row-action-edit')
                                ->icon('bs.person-check')
                                ->confirm(__('Reactivate :name? They can sign in again with their current password.', ['name' => $user->name]))
                                ->set('data-confirm-title', __('Reactivate account'))
                                ->set('data-confirm-label', __('Reactivate'))
                                ->method('reactivate', ['id' => $user->id])
                            : Button::make(__('Deactivate'))
                                ->class('btn dts-row-action dts-row-action-delete')
                                ->icon('bs.person-slash')
                                ->confirm(__('Deactivate :name? They are signed out now and cannot sign in until reactivated. Their document history stays.', ['name' => $user->name]))
                                ->set('data-confirm-title', __('Deactivate account'))
                                ->set('data-confirm-label', __('Deactivate'))
                                ->method('deactivate', ['id' => $user->id])),
                ])),
        ];
    }
}
