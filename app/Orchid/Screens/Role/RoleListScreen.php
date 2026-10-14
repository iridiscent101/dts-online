<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Role;

use App\Models\User;
use App\Orchid\Layouts\Role\RoleFiltersLayout;
use App\Orchid\Layouts\Role\RoleListLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            Layout::wrapper('orchid.admin-table-page', [
                'filters' => RoleFiltersLayout::class,
                'pageSize' => Layout::view('orchid.table-page-size'),
                'table' => RoleListLayout::class,
            ]),
        ];
    }

    public function remove(Request $request): void
    {
        $role = Role::findOrFail($request->integer('id'));

        abort_if($role->slug === User::ADMIN_ROLE_SLUG, 403, 'The administrator role cannot be deleted.');
        abort_unless($request->user()?->canManageRole($role), 403);

        $role->delete();

        Toast::info(__('Role was removed'));
    }

    public function bulkRemove(Request $request): void
    {
        abort_unless($request->user()?->hasAccess('platform.systems.roles'), 403);

        $selected = $request->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'distinct', Rule::exists(Role::class, 'id')],
        ])['selected'];
        $roles = Role::query()->whereKey($selected)->get();

        if ($roles->contains(fn (Role $role): bool => $role->slug === User::ADMIN_ROLE_SLUG)) {
            throw ValidationException::withMessages([
                'selected' => 'The administrator role cannot be deleted.',
            ]);
        }
        abort_if($roles->contains(fn (Role $role): bool => ! $request->user()->canManageRole($role)), 403);

        DB::transaction(function () use ($roles): void {
            foreach ($roles as $role) {
                $role->delete();
            }
        });

        Toast::info($roles->count().' roles were removed.');
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
