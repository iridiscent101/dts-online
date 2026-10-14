<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Models\Office;
use App\Models\User;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Orchid\Layouts\User\UserOfficeLayout;
use App\Orchid\Layouts\User\UserPasswordLayout;
use App\Orchid\Layouts\User\UserRoleLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserEditScreen extends Screen
{
    /**
     * @var User
     */
    public $user;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(User $user): iterable
    {
        $user->load(['offices', 'roles']);
        $user->setAttribute('office_ids', $this->selectedOfficeIds($user));

        return [
            'user' => $user,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->user->exists ? 'Edit User' : 'Create User';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'User profile and assigned roles.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.users',
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
                ->confirm(__('Once the account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.'))
                ->method('remove')
                ->canSee($this->user->exists),

            Button::make(__('Save'))
                ->class('btn dts-button dts-button-primary')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [

            Layout::block(UserEditLayout::class)
                ->title(__('Profile Information'))
                ->description(__('Update your account\'s profile information and email address.')),

            Layout::block(UserPasswordLayout::class)
                ->title(__('Password'))
                ->description(__('Ensure your account is using a long, random password to stay secure.')),

            Layout::block(UserOfficeLayout::class)
                ->title(__('Office Assignment'))
                ->description(__('Controls which document queue this staff member is responsible for.')),

            Layout::block(UserRoleLayout::class)
                ->title(__('Roles'))
                ->description(__('A Role defines a set of tasks a user assigned the role is allowed to perform.')),

        ];
    }

    /**
     * @return RedirectResponse
     */
    public function save(User $user, Request $request)
    {
        abort_if($user->exists && $user->isAdministrator() && ! $request->user()?->isAdministrator(), 403);

        $validated = $request->validate([
            'user.name' => ['required', 'string', 'max:255'],
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
            'user.password' => [Rule::requiredIf(! $user->exists), 'nullable', 'string'],
            'user.office_ids' => ['nullable', 'array'],
            'user.office_ids.*' => ['integer', Rule::exists(Office::class, 'id')->where('is_active', true)],
            'user.roles' => ['sometimes', 'array'],
            'user.roles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ]);

        if (! $request->user()?->isAdministrator()
            && ($request->has('user.office_ids') || $request->boolean('user.offices_submitted'))) {
            $submittedOffices = Office::query()->whereKey($this->officeIdsFromRequest($request))->pluck('name')->sort()->values()->all();
            abort_if($submittedOffices !== $user->assignedOfficeNames()->sort()->values()->all(), 403);
        }

        if (! $request->user()?->isAdministrator()) {
            $roles = Role::query()->whereKey(data_get($validated, 'user.roles', []))->get();

            abort_if($roles->contains(function (Role $role) use ($request): bool {
                return $role->slug === User::ADMIN_ROLE_SLUG
                    || collect($role->permissions ?? [])
                        ->filter()
                        ->keys()
                        ->contains(fn (string $permission): bool => ! $request->user()->hasAccess($permission));
            }), 403);
        }

        DB::transaction(function () use ($request, $user): void {
            $user->when($request->filled('user.password'), function (Builder $builder) use ($request) {
                $builder->getModel()->password = Hash::make($request->input('user.password'));
            });

            $user
                ->fill($request->collect('user')->except(['office', 'office_ids', 'offices_submitted', 'password', 'permissions', 'roles'])->toArray())
                ->forceFill(['permissions' => []])
                ->save();

            if ($request->user()->isAdministrator() && $request->boolean('user.offices_submitted')) {
                $officeIds = $this->officeIdsFromRequest($request);
                $officeNames = Office::query()
                    ->whereKey($officeIds)
                    ->pluck('name', 'id');

                $user->forceFill([
                    'office' => $officeIds
                        ->map(fn (int $officeId): ?string => $officeNames->get($officeId))
                        ->filter()
                        ->first(),
                ])->save();

                $user->offices()->sync($officeIds->all());
            }

            $user->replaceRoles($request->input('user.roles', []));
        });

        Toast::info(__('User was saved.'));

        return redirect()->route('platform.systems.users');
    }

    /**
     * @return RedirectResponse
     *
     * @throws \Exception
     */
    public function remove(User $user, Request $request)
    {
        abort_if($user->id === $request->user()?->id || $user->isAdministrator(), 403);

        $user->delete();

        Toast::info(__('User was removed'));

        return redirect()->route('platform.systems.users');
    }

    /**
     * @return list<int>
     */
    private function selectedOfficeIds(User $user): array
    {
        $officeIds = $user->offices->pluck('id');

        if ($officeIds->isEmpty() && filled($user->office)) {
            $legacyOfficeId = Office::query()
                ->where('name', $user->office)
                ->value('id');

            if ($legacyOfficeId !== null) {
                $officeIds->push((int) $legacyOfficeId);
            }
        }

        return $officeIds->map(fn (mixed $officeId): int => (int) $officeId)->all();
    }

    /**
     * @return Collection<int, int>
     */
    private function officeIdsFromRequest(Request $request): Collection
    {
        return collect($request->input('user.office_ids', []))
            ->filter(fn (mixed $officeId): bool => filled($officeId))
            ->map(fn (mixed $officeId): int => (int) $officeId)
            ->unique()
            ->values();
    }
}
