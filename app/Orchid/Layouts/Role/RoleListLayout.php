<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Role;

use App\Models\User;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class RoleListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'roles';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide(),

            TD::make('description', __('Description'))
                ->render(fn (Role $role) => $role->description ?: __('No description provided'))
                ->cantHide(),

            TD::make('created_at', __('Created'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->defaultHidden()
                ->sort(),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('112px')
                ->render(fn (Role $role) => view('orchid.cells.admin-row-actions', [
                    'editAction' => Link::make(__('Edit'))
                        ->class('btn dts-row-action dts-row-action-edit')
                        ->route('platform.systems.roles.edit', $role->id)
                        ->icon('bs.pencil'),
                    'deleteAction' => $role->slug === User::ADMIN_ROLE_SLUG
                        ? null
                        : Button::make(__('Delete'))
                            ->class('btn dts-row-action dts-row-action-delete')
                            ->icon('bs.trash3')
                            ->confirm(__('Once the role is deleted, its permissions will no longer be assigned to users.'))
                            ->method('remove', [
                                'id' => $role->id,
                            ]),
                ])),
        ];
    }
}
