<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;

class UserSearchFilter extends Filter
{
    /**
     * The displayable name of the filter.
     */
    public function name(): string
    {
        return __('Search users');
    }

    /**
     * The array of matched parameters.
     */
    public function parameters(): ?array
    {
        return ['user_search'];
    }

    /**
     * Apply to a given Eloquent query builder.
     */
    public function run(Builder $builder): Builder
    {
        $search = $this->request->string('user_search')->trim()->toString();

        if ($search === '') {
            return $builder;
        }

        return $builder->where(function (Builder $query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    /**
     * Get the display fields.
     *
     * @return Input[]
     */
    public function display(): iterable
    {
        return [
            Input::make('user_search')
                ->type('search')
                ->title(__('Search users'))
                ->placeholder(__('Name or email address'))
                ->maxlength(150)
                ->autocomplete('off')
                ->value($this->request->get('user_search')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.$this->request->string('user_search')->trim()->toString();
    }
}
