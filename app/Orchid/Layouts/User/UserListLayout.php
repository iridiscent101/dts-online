<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use App\Models\User;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class UserListLayout extends Table
{
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
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide(),

            TD::make('email', __('Email'))
                ->sort()
                ->cantHide(),

            TD::make('roles', __('Role'))
                ->render(fn (User $user): string => e($user->roles->pluck('name')->join(', ') ?: __('No role'))),

            TD::make('office', __('Office'))
                ->sort()
                ->render(fn (User $user): string => e($user->office ?: __('Not assigned'))),

            TD::make('created_at', __('Date created'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('112px')
                ->render(fn (User $user) => view('orchid.cells.admin-row-actions', [
                    'editAction' => Link::make(__('Edit'))
                        ->class('btn dts-row-action dts-row-action-edit')
                        ->route('platform.systems.users.edit', $user->id)
                        ->icon('bs.pencil'),
                    'deleteAction' => Button::make(__('Delete'))
                        ->class('btn dts-row-action dts-row-action-delete')
                        ->icon('bs.trash3')
                        ->confirm(__('Once the account is deleted, all of its resources and data will be permanently deleted.'))
                        ->method('remove', [
                            'id' => $user->id,
                        ]),
                ])),
        ];
    }
}
