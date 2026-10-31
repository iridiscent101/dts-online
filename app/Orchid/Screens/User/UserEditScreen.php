<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Auth\AccountActivity;
use App\Http\Controllers\RecoveryEmailController;
use App\Models\Office;
use App\Models\User;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Orchid\Layouts\User\UserOfficeLayout;
use App\Orchid\Layouts\User\UserPasswordLayout;
use App\Orchid\Layouts\User\UserRoleLayout;
use App\Rules\EmailDomainReceivesMail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserEditScreen extends Screen
{
    /**
     * @var User
     */
    public $user;

    /** @var array<int, string> */
    public array $administrators = [];

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(User $user): iterable
    {
        $user->load(['offices', 'roles']);
        $user->setAttribute('office_ids', $this->selectedOfficeIds($user));

        $this->administrators = User::query()
            ->active()
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('slug', User::ADMIN_ROLE_SLUG))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return [
            'user' => $user,
            'administrators' => $this->administrators,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->user->exists ? 'Edit user' : 'Create user';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Account details, office assignments and roles.';
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
            Button::make(__('Deactivate'))
                ->class('btn dts-button dts-button-danger')
                ->icon('bs.person-slash')
                ->confirm(__('Deactivate this account? The person is signed out now and cannot sign in until reactivated. Their document history stays.'))
                ->set('data-confirm-title', __('Deactivate account'))
                ->set('data-confirm-label', __('Deactivate'))
                ->method('deactivate')
                ->canSee($this->canChangeStatus() && ! $this->user->isDeactivated()),

            Button::make(__('Reactivate'))
                ->class('btn dts-button dts-button-secondary')
                ->icon('bs.person-check')
                ->confirm(__('Reactivate this account? The person can sign in again with their current password.'))
                ->set('data-confirm-title', __('Reactivate account'))
                ->set('data-confirm-label', __('Reactivate'))
                ->method('reactivate')
                ->canSee($this->canChangeStatus() && $this->user->isDeactivated()),

            ModalToggle::make(__('Delete account'))
                ->class('btn dts-button dts-button-danger')
                ->icon('bs.trash3')
                ->modal('deleteUserModal')
                ->method('delete')
                ->canSee($this->canDelete()),

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
                ->description(__('Name, sign-in email and recovery email.')),

            Layout::block(UserPasswordLayout::class)
                ->title(__('Password'))
                ->description(__('Set or change the account password.')),

            Layout::block(UserOfficeLayout::class)
                ->title(__('Office Assignment'))
                ->description(__('Office assignments determine access to document queues.')),

            Layout::block(UserRoleLayout::class)
                ->title(__('Roles'))
                ->description(__('Roles determine account permissions.')),

            Layout::modal('deleteUserModal', Layout::rows([
                Select::make('transfer_to')
                    ->title(__('Transfer account data to'))
                    ->options($this->administrators)
                    ->empty(__('Select an administrator'))
                    ->help(__('Uploaded files and kiosk signing requests will be reassigned. Document history keeps the original staff member as its actor.'))
                    ->required(),
            ]))
                ->title(__('Permanently remove :name?', ['name' => $this->user->name]))
                ->applyButton(__('Delete account'))
                ->closeButton(__('Cancel'))
                ->canSee($this->canDelete()),

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
                'string',
                'email:rfc,filter',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
            'user.notification_email' => ['bail', 'nullable', 'email:rfc,filter', 'max:255', new EmailDomainReceivesMail($user->notification_email)],
            'user.password' => [Rule::requiredIf(! $user->exists), 'nullable', 'string', 'confirmed', Password::defaults()],
            'user.password_confirmation' => ['nullable', 'string'],
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

        $passwordChanged = $user->exists && $request->filled('user.password');

        $previousRecoveryEmail = $user->notification_email;
        DB::transaction(function () use ($request, $user, $passwordChanged): void {
            if ($request->has('user.notification_email')) {
                $user->setNotificationEmail($request->input('user.notification_email'));
            }

            $user->when($request->filled('user.password'), function (Builder $builder) use ($request) {
                $builder->getModel()->password = Hash::make($request->input('user.password'));
                // A password someone else chose is known to them, so the person must pick their own at the next sign-in.
                // Administrators setting their own password on this screen chose it themselves.
                $builder->getModel()->must_change_password = $builder->getModel()->getKey() !== $request->user()?->getKey();
            });

            $user
                ->fill($request->collect('user')->except(['office', 'office_ids', 'offices_submitted', 'password', 'password_confirmation', 'permissions', 'roles', 'notification_email'])->toArray())
                ->forceFill(['permissions' => []])
                ->save();

            if ($request->user()->isAdministrator() && $request->boolean('user.offices_submitted')) {
                $officeIds = $this->officeIdsFromRequest($request);
                $user->offices()->sync($officeIds->all());
            }

            $user->replaceRoles($request->input('user.roles', []));

            if ($passwordChanged) {
                AccountActivity::record(AccountActivity::PASSWORD_CHANGED, $user, $request->user(), ['set_by_admin' => true]);
            }
        });

        if ($previousRecoveryEmail !== $user->notification_email && filled($user->notification_email)) {
            RecoveryEmailController::sendVerification($user);
        }

        Toast::success(__('User was saved.'));

        return redirect()->route('platform.systems.users');
    }

    public function deactivate(User $user, Request $request): RedirectResponse
    {
        abort_if($user->id === $request->user()?->id || $user->isAdministrator(), 403);

        if (! $user->isDeactivated()) {
            $user->deactivate($request->user());
        }

        Toast::success(__(':name was deactivated and can no longer sign in.', ['name' => $user->name]));

        return redirect()->route('platform.systems.users');
    }

    public function reactivate(User $user, Request $request): RedirectResponse
    {
        abort_if($user->id === $request->user()?->id || $user->isAdministrator(), 403);

        if ($user->isDeactivated()) {
            $user->reactivate($request->user());
        }

        Toast::success(__(':name can sign in again.', ['name' => $user->name]));

        return redirect()->route('platform.systems.users.edit', $user);
    }

    public function delete(User $user, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        abort_if($user->is($request->user()) || $user->isAdministrator() || ! $user->isDeactivated(), 403);

        $validated = $request->validate([
            'transfer_to' => ['required', 'integer', Rule::exists(User::class, 'id')->whereNull('deactivated_at')->whereNull('deleted_at')],
        ]);
        $administrator = User::query()->with('roles')->findOrFail($validated['transfer_to']);

        if (! $administrator->isAdministrator()) {
            throw ValidationException::withMessages([
                'transfer_to' => __('Choose an active administrator.'),
            ]);
        }

        DB::transaction(function () use ($administrator, $user): void {
            DB::table('attachments')->where('user_id', $user->id)->update(['user_id' => $administrator->id]);
            DB::table('kiosk_signing_requests')->where('requested_by', $user->id)->update(['requested_by' => $administrator->id]);
            AccountActivity::record(AccountActivity::DELETED, $user, auth()->user(), [
                'email' => $user->email,
                'transferred_to' => $administrator->email,
            ]);
            $user->delete();
        });

        Toast::success(__(':name was removed. Their uploaded files and signing requests were transferred to :administrator.', [
            'name' => $user->name,
            'administrator' => $administrator->name,
        ]));

        return redirect()->route('platform.systems.users');
    }

    private function canChangeStatus(): bool
    {
        return $this->user->exists && $this->user->id !== auth()->id() && ! $this->user->isAdministrator();
    }

    private function canDelete(): bool
    {
        return $this->canChangeStatus() && $this->user->isDeactivated() && auth()->user()?->isAdministrator();
    }

    /**
     * @return list<int>
     */
    private function selectedOfficeIds(User $user): array
    {
        return $user->offices->pluck('id')->map(fn (mixed $officeId): int => (int) $officeId)->all();
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
