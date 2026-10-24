<?php

declare(strict_types=1);

namespace App\Orchid\Screens\School;

use App\Models\School;
use App\Orchid\Layouts\School\SchoolFiltersLayout;
use App\Orchid\Layouts\School\SchoolListLayout;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class SchoolListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'schools' => School::filters(SchoolFiltersLayout::class)
                ->defaultSort('source_unit_id')
                ->paginate($this->perPage()),
        ];
    }

    public function name(): ?string
    {
        return 'School management';
    }

    public function description(): ?string
    {
        return 'School catalog used for document origin suggestions.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.schools',
        ];
    }

    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.systems.schools.create'),
        ];
    }

    /**
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::wrapper('orchid.admin-table-page', [
                'filters' => SchoolFiltersLayout::class,
                'pageSize' => Layout::view('orchid.table-page-size'),
                'table' => SchoolListLayout::class,
            ]),
        ];
    }

    public function remove(Request $request): void
    {
        abort_unless($request->user()?->hasAccess('platform.systems.schools'), 403);

        $school = School::query()->findOrFail($request->integer('id'));
        $school->delete();

        Toast::success(__(':school was removed.', ['school' => $school->name]));
    }

    public function bulkRemove(Request $request): void
    {
        abort_unless($request->user()?->hasAccess('platform.systems.schools'), 403);

        $selected = $request->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'distinct', Rule::exists(School::class, 'id')],
        ])['selected'];

        $removed = School::query()->whereKey($selected)->delete();

        Toast::success($removed.' schools were removed.');
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
