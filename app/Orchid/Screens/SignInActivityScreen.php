<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Auth\AccountActivity;
use App\Orchid\Filters\AccountActivityEventFilter;
use App\Orchid\Filters\AccountActivityFilter;
use App\Orchid\Layouts\SignInActivity\SignInActivityFiltersLayout;
use App\Orchid\Layouts\SignInActivity\SignInActivityListLayout;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Spatie\Activitylog\Models\Activity;

/**
 * Who signed in, failed to sign in, was locked out or deactivated, and from which computer (AccountActivity).
 * Laid out like the other admin lists: filter box, columns and rows-per-page toolbar, then the table.
 */
class SignInActivityScreen extends Screen
{
    public function query(): iterable
    {
        $query = Activity::query()
            ->where('log_name', AccountActivity::LOG_NAME)
            ->with(['causer', 'subject'])
            ->latest('id');

        return [
            'activities' => (new AccountActivityEventFilter)->filter((new AccountActivityFilter)->filter($query))->paginate($this->perPage())->withQueryString(),
        ];
    }

    public function name(): ?string
    {
        return 'Sign-in activity';
    }

    public function description(): ?string
    {
        return 'Sign-in and account activity, newest first. Entries are deleted after 3 months.';
    }

    public function permission(): ?iterable
    {
        return ['platform.systems.sign-in-activity'];
    }

    public function layout(): iterable
    {
        return [
            Layout::wrapper('orchid.admin-table-page', [
                'filters' => SignInActivityFiltersLayout::class,
                'pageSize' => Layout::view('orchid.table-page-size'),
                'table' => SignInActivityListLayout::class,
            ]),
        ];
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
