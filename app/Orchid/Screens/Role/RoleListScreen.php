<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Role;

use App\Models\User;
use App\Orchid\Layouts\Role\RoleFiltersLayout;
use App\Orchid\Layouts\Role\RoleListLayout;
use Illuminate\Http\Request;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class RoleListScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        return [
            'roles' => Role::filters(RoleFiltersLayout::class)->defaultSort('id', 'desc')->paginate($this->perPage()),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Role Management';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'A comprehensive list of all roles, including their permissions and associated users.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.roles',
        ];
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->href(route('platform.systems.roles.create')),
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            RoleFiltersLayout::class,
            Layout::view('orchid.table-page-size'),
            RoleListLayout::class,
        ];
    }

    public function remove(Request $request): void
    {
        $role = Role::findOrFail($request->integer('id'));

        abort_if($role->slug === User::ADMIN_ROLE_SLUG, 403, 'The administrator role cannot be deleted.');

        $role->delete();

        Toast::info(__('Role was removed'));
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
