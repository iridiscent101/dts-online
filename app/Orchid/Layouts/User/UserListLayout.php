<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use App\Models\User;
use App\Orchid\Layouts\Concerns\HasTableSelectionColumn;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
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
            $this->selectColumn('Select user', fn (User $user): bool => $user->id === auth()->id() || $user->isAdministrator()),

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
                ->render(fn (User $user): string => $user->isDeactivated()
                    ? '<strong>'.e(__('Deactivated')).'</strong>'
                    : e(__('Active'))),

            TD::make('created_at', __('Date created'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make('updated_at', __('Last updated'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
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
                    // Accounts are deactivated, not deleted, so their names stay on the documents they handled.
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
