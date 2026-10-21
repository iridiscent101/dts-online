<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Models\Office;
use App\Models\User;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Orchid\Layouts\User\UserFiltersLayout;
use App\Orchid\Layouts\User\UserListLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserListScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        return [
            'users' => User::with(['offices', 'roles'])
                ->filters(UserFiltersLayout::class)
                ->defaultSort('id', 'desc')
                ->paginate($this->perPage()),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'User management';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'A comprehensive list of all registered users, including their profiles and privileges.';
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
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.systems.users.create'),
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
                'filters' => UserFiltersLayout::class,
                'pageSize' => Layout::view('orchid.table-page-size'),
                'table' => UserListLayout::class,
            ]),

            Layout::modal('editUserModal', UserEditLayout::class)
                ->deferred('loadUserOnOpenModal'),
        ];
    }

    /**
     * Loads user data when opening the modal window.
     *
     * @return array
     */
    public function loadUserOnOpenModal(User $user): iterable
    {
        return [
            'user' => $user,
        ];
    }

    public function saveUser(Request $request, User $user): void
    {
        abort_if($user->isAdministrator() && ! $request->user()?->isAdministrator(), 403);

        $request->validate([
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
            'user.notification_email' => ['nullable', 'email', 'max:255'],
            'user.office_ids' => ['nullable', 'array'],
            'user.office_ids.*' => ['integer', Rule::exists(Office::class, 'id')->where('is_active', true)],
        ]);

        if (! $request->user()?->isAdministrator()
            && ($request->has('user.office_ids') || $request->boolean('user.offices_submitted'))) {
            $submittedOffices = Office::query()->whereKey($this->officeIdsFromRequest($request))->pluck('name')->sort()->values()->all();
            abort_if($submittedOffices !== $user->assignedOfficeNames()->sort()->values()->all(), 403);
        }

        DB::transaction(function () use ($request, $user): void {
            $sendVerification = $request->has('user.notification_email')
                && $user->setNotificationEmail($request->input('user.notification_email'));

            $user
                ->fill($request->collect('user')->only(['name', 'email'])->toArray())
                ->save();

            if ($sendVerification) {
                $user->sendNotificationEmailVerification();
            }

            if ($request->user()->isAdministrator() && $request->boolean('user.offices_submitted')) {
                $officeIds = $this->officeIdsFromRequest($request);
                $user->offices()->sync($officeIds->all());
            }
        });

        Toast::success(__('User was saved.'));
    }

    public function deactivate(Request $request): void
    {
        $user = User::findOrFail($request->get('id'));
        abort_if($user->id === $request->user()?->id || $user->isAdministrator(), 403);

        if (! $user->isDeactivated()) {
            $user->deactivate($request->user());
        }

        Toast::success(__(':name was deactivated and can no longer sign in.', ['name' => $user->name]));
    }

    public function reactivate(Request $request): void
    {
        $user = User::findOrFail($request->get('id'));
        abort_if($user->id === $request->user()?->id || $user->isAdministrator(), 403);

        if ($user->isDeactivated()) {
            $user->reactivate($request->user());
        }

        Toast::success(__(':name can sign in again.', ['name' => $user->name]));
    }

    /**
     * "Deactivate selected" on the list (accounts are no longer deleted, so their document history keeps their names).
     */
    public function bulkDeactivate(Request $request): void
    {
        abort_unless($request->user()?->hasAccess('platform.systems.users'), 403);

        $selected = $request->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'distinct', Rule::exists(User::class, 'id')],
        ])['selected'];
        $users = User::query()->with('roles')->whereKey($selected)->get();

        if ($users->contains(fn (User $user): bool => $user->id === $request->user()->id || $user->isAdministrator())) {
            throw ValidationException::withMessages([
                'selected' => 'You cannot deactivate your own account or an administrator account.',
            ]);
        }

        DB::transaction(function () use ($users, $request): void {
            $users->reject(fn (User $user): bool => $user->isDeactivated())
                ->each(fn (User $user) => $user->deactivate($request->user()));
        });

        Toast::success($users->count().' '.str('user')->plural($users->count()).' deactivated.');
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
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
