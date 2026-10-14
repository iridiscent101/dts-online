<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;

class OfficeSearchFilter extends Filter
{
    public function name(): string
    {
        return __('Search offices');
    }

    public function parameters(): ?array
    {
        return ['office_search'];
    }

    public function run(Builder $builder): Builder
    {
        $search = $this->request->string('office_search')->trim()->toString();

        if ($search === '') {
            return $builder;
        }

        return $builder->where('name', 'like', "%{$search}%");
    }

    /**
     * @return Input[]
     */
    public function display(): iterable
    {
        return [
            Input::make('office_search')
                ->type('search')
                ->title(__('Search offices'))
                ->placeholder(__('Office name'))
                ->maxlength(150)
                ->autocomplete('off')
                ->value($this->request->get('office_search')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.$this->request->string('office_search')->trim()->toString();
    }
}
