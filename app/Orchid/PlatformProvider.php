<?php

declare(strict_types=1);

namespace App\Orchid;

use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(Dashboard $dashboard): void
    {
        parent::boot($dashboard);

        // ...
    }

    /**
     * Register the application menu.
     *
     * @return Menu[]
     */
    public function menu(): array
    {
        return [
            Menu::make('Dashboard')
                ->icon('bs.grid')
                ->title('WORKSPACE')
                ->route(config('platform.index')),

            Menu::make('All documents')->icon('bs.files')->route('platform.documents')->title('DOCUMENT REGISTRY'),
            Menu::make('Incoming')->icon('bs.inbox')->route('platform.incoming'),
            Menu::make('Outgoing')->icon('bs.send')->route('platform.outgoing'),
            Menu::make('Finished')->icon('bs.check2-circle')->route('platform.archived')
                ->divider(),

            Menu::make(__('Users'))
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission('platform.systems.users')
                ->title(__('ADMINISTRATION')),

            Menu::make(__('Roles'))
                ->icon('bs.shield')
                ->route('platform.systems.roles')
                ->permission('platform.systems.roles'),

            Menu::make(__('Offices'))
                ->icon('bs.building')
                ->route('platform.systems.offices')
                ->permission('platform.systems.offices'),

            Menu::make(__('Schools'))
                ->icon('bs.buildings')
                ->route('platform.systems.schools')
                ->permission('platform.systems.schools'),

            Menu::make(__('System health'))
                ->icon('bs.activity')
                ->route('platform.systems.health')
                ->permission('platform.systems.health')
                ->divider(),
        ];
    }

    /**
     * Register permissions for the application.
     *
     * @return ItemPermission[]
     */
    public function permissions(): array
    {
        return [
            ItemPermission::group(__('Documents'))
                ->addPermission('platform.documents.view-all', __('View all documents'))
                ->addPermission('platform.documents.edit', __('Edit document details'))
                ->addPermission('platform.documents.routes.manage', __('Manage document routes'))
                ->addPermission('platform.documents.attachments.manage', __('Manage document attachments')),

            ItemPermission::group(__('System'))
                ->addPermission('platform.systems.roles', __('Roles'))
                ->addPermission('platform.systems.users', __('Users'))
                ->addPermission('platform.systems.offices', __('Offices'))
                ->addPermission('platform.systems.schools', __('Schools'))
                ->addPermission('platform.systems.health', __('View system health')),
        ];
    }
}
