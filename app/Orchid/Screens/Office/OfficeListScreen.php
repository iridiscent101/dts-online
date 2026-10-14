<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Office;

use App\Models\Office;
use App\Orchid\Layouts\Office\OfficeFiltersLayout;
use App\Orchid\Layouts\Office\OfficeListLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class OfficeListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'offices' => Office::filters(OfficeFiltersLayout::class)
                ->defaultSort('sort_order')
                ->paginate($this->perPage()),
        ];
    }

    public function name(): ?string
    {
        return 'Office Management';
    }

    public function description(): ?string
    {
        return 'Manage routing and personnel offices. Only unused offices can be deleted; deactivate offices in use.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.offices',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.systems.offices.create'),
        ];
    }

    /**
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::wrapper('orchid.admin-table-page', [
                'filters' => OfficeFiltersLayout::class,
                'pageSize' => Layout::view('orchid.table-page-size'),
                'table' => OfficeListLayout::class,
            ]),
        ];
    }

    /**
     * Row "Delete". Whether an office is in use is checked here, not per row on the list (that costs several queries a row).
     */
    public function remove(Request $request): void
    {
        abort_unless($request->user()?->hasAccess('platform.systems.offices'), 403);

        $office = Office::query()->findOrFail($request->integer('id'));

        if (! $office->canBeDeleted()) {
            Toast::warning(__(':office is used by documents or staff, so it cannot be deleted. Deactivate it instead.', ['office' => $office->name]));

            return;
        }

        $office->delete();

        Toast::info(__(':office was removed.', ['office' => $office->name]));
    }

    public function bulkRemove(Request $request): void
    {
        abort_unless($request->user()?->hasAccess('platform.systems.offices'), 403);

        $selected = $request->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'distinct', Rule::exists(Office::class, 'id')],
        ])['selected'];
        $offices = Office::query()->whereKey($selected)->get();

        if ($offices->contains(fn (Office $office): bool => ! $office->canBeDeleted())) {
            throw ValidationException::withMessages([
                'selected' => 'Only unused offices can be deleted. Deactivate an office used by documents or staff.',
            ]);
        }

        DB::transaction(function () use ($offices): void {
            foreach ($offices as $office) {
                $office->delete();
            }
        });

        Toast::info($offices->count().' offices were removed.');
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
