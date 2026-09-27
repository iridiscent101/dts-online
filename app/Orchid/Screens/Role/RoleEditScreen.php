<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Role;

use App\Models\User;
use App\Orchid\Layouts\Role\RoleEditLayout;
use App\Orchid\Layouts\Role\RolePermissionLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class RoleEditScreen extends Screen
{
    /**
     * @var Role
     */
    public $role;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(Role $role): iterable
    {
        return [
            'role' => $role,
            'permission' => $role->statusOfPermissions(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Edit Role';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Modify the privileges and permissions associated with a specific role.';
    }

    /**
     * The permissions required to access this screen.
     */
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
            Button::make(__('Delete'))
                ->class('btn dts-button dts-button-danger')
                ->icon('bs.trash3')
                ->method('remove')
                ->canSee($this->role->exists && $this->role->slug !== User::ADMIN_ROLE_SLUG),

            Button::make(__('Save'))
                ->class('btn dts-button dts-button-primary')
                ->icon('bs.check-circle')
                ->method('save'),
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
            Layout::block([
                RoleEditLayout::class,
            ])
                ->title('Role')
                ->description('Defines a set of privileges that grant users access to various services and allow them to perform specific tasks or operations.'),

            Layout::block([
                RolePermissionLayout::class,
            ])
                ->title('Permission/Privilege')
                ->description('A privilege is necessary to perform certain tasks and operations in an area.'),
        ];
    }

    /**
     * @return RedirectResponse
     */
    public function save(Request $request, Role $role)
    {
        $validated = $request->validate([
            'role.name' => ['required', 'string', 'max:255'],
            'role.description' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $role->exists) {
            $role->slug = $this->uniqueRoleCode($validated['role']['name']);
        }

        $role->name = $validated['role']['name'];
        $role->description = $validated['role']['description'] ?? null;

        $role->permissions = collect($request->get('permissions'))
            ->map(fn ($value, $key) => [base64_decode($key) => $value])
            ->collapse()
            ->toArray();

        $role->save();

        Toast::info(__('Role was saved'));

        return redirect()->route('platform.systems.roles');
    }

    private function uniqueRoleCode(string $name): string
    {
        $baseCode = Str::slug($name) ?: 'role';
        $roleCode = $baseCode;
        $suffix = 2;

        while (Role::where('slug', $roleCode)->exists()) {
            $roleCode = $baseCode.'-'.$suffix;
            $suffix++;
        }

        return $roleCode;
    }

    /**
     * @return RedirectResponse
     *
     * @throws \Exception
     */
    public function remove(Role $role)
    {
        abort_if($role->slug === User::ADMIN_ROLE_SLUG, 403, 'The administrator role cannot be deleted.');

        $role->delete();

        Toast::info(__('Role was removed'));

        return redirect()->route('platform.systems.roles');
    }
}
