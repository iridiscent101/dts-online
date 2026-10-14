<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Inactive offices are hidden from assignment dropdowns, so this is where administrators find them again.
 */
class OfficeStatusFilter extends Filter
{
    public function name(): string
    {
        return __('Status');
    }

    public function parameters(): ?array
    {
        return ['office_status'];
    }

    public function run(Builder $builder): Builder
    {
        return match ($this->request->string('office_status')->toString()) {
            'active' => $builder->where('is_active', true),
            'inactive' => $builder->where('is_active', false),
            default => $builder,
        };
    }

    /**
     * @return Select[]
     */
    public function display(): iterable
    {
        return [
            Select::make('office_status')
                ->options(['active' => __('Active'), 'inactive' => __('Inactive')])
                ->empty(__('All statuses'))
                ->value($this->request->get('office_status'))
                ->title(__('Status')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.($this->request->get('office_status') === 'inactive' ? __('Inactive') : __('Active'));
    }
}
