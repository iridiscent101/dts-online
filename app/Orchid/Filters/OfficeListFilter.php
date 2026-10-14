<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Offices on the SDO list (staff routing) or the client list (public submission form).
 */
class OfficeListFilter extends Filter
{
    public function name(): string
    {
        return __('List');
    }

    public function parameters(): ?array
    {
        return ['office_list'];
    }

    public function run(Builder $builder): Builder
    {
        return match ($this->request->string('office_list')->toString()) {
            'sdo' => $builder->where('is_sdo', true),
            'client' => $builder->where('is_client', true),
            default => $builder,
        };
    }

    /**
     * @return Select[]
     */
    public function display(): iterable
    {
        return [
            Select::make('office_list')
                ->options(['sdo' => __('SDO list'), 'client' => __('Client list')])
                ->empty(__('All lists'))
                ->value($this->request->get('office_list'))
                ->title(__('List')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.($this->request->get('office_list') === 'client' ? __('Client list') : __('SDO list'));
    }
}
