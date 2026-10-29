<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\TD;

trait HasTableSelectionColumn
{
    /** $isDeactivated marks rows (users) that "Delete selected" may remove. */
    protected function selectColumn(string $label, ?Closure $cannotSelect = null, ?Closure $isDeactivated = null): TD
    {
        return TD::make('__select', '<input type="checkbox" class="dts-table-checkbox" data-table-select-all aria-label="'.e(__('Select all rows on this page')).'">')
            ->align(TD::ALIGN_CENTER)
            ->width('48px')
            ->cantHide()
            ->render(fn (Model $row) => view('orchid.cells.table-row-select', [
                'value' => $row->getKey(),
                'label' => __($label),
                'disabled' => $cannotSelect?->__invoke($row) ?? false,
                'deactivated' => $isDeactivated?->__invoke($row) ?? false,
            ]));
    }

    protected function numberColumn(): TD
    {
        return TD::make('__number', '#')
            ->align(TD::ALIGN_CENTER)
            ->width('56px')
            ->cantHide()
            ->render(fn (Model $row, ?object $loop = null): int => $this->tableRowNumber($loop));
    }

    private function tableRowNumber(?object $loop): int
    {
        $page = max(1, request()->integer('page', 1));
        $perPage = request()->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        return (($page - 1) * $perPage) + (int) ($loop?->iteration ?? 1);
    }
}
