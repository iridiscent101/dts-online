<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Models\User;
use App\Orchid\Layouts\User\UserFiltersLayout;
use App\Orchid\Layouts\User\UserListLayout;
use Illuminate\Http\Request;
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
        ];
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

    /** "Deactivate selected" keeps bulk actions reversible. */
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
}
