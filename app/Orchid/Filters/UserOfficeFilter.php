<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use App\Models\Office;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

class UserOfficeFilter extends Filter
{
    public function name(): string
    {
        return __('Office');
    }

    public function parameters(): ?array
    {
        return ['user_office'];
    }

    public function run(Builder $builder): Builder
    {
        $office = $this->request->string('user_office')->trim()->toString();

        if ($office === '') {
            return $builder;
        }

        // Staff are linked to offices through the office list; older accounts may only have the office column.
        return $builder->where(fn (Builder $query): Builder => $query
            ->whereHas('offices', fn (Builder $offices): Builder => $offices->where('name', $office))
            ->orWhere('office', $office));
    }

    /**
     * @return Select[]
     */
    public function display(): iterable
    {
        return [
            Select::make('user_office')
                ->options(Office::query()->orderBy('name')->pluck('name', 'name')->all())
                ->empty(__('All offices'))
                ->value($this->request->get('user_office'))
                ->title(__('Office')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.$this->request->string('user_office')->trim()->toString();
    }
}
