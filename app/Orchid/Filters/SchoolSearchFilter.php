<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;

class SchoolSearchFilter extends Filter
{
    public function name(): string
    {
        return __('Search schools');
    }

    public function parameters(): ?array
    {
        return ['school_search'];
    }

    public function run(Builder $builder): Builder
    {
        $search = $this->request->string('school_search')->trim()->toString();

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
            Input::make('school_search')
                ->type('search')
                ->title(__('Search schools'))
                ->placeholder(__('School name'))
                ->maxlength(180)
                ->autocomplete('off')
                ->value($this->request->get('school_search')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.$this->request->string('school_search')->trim()->toString();
    }
}
