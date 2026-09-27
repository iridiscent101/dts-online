<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;

class RoleSearchFilter extends Filter
{
    public function name(): string
    {
        return __('Search roles');
    }

    public function parameters(): ?array
    {
        return ['role_search'];
    }

    public function run(Builder $builder): Builder
    {
        $search = $this->request->string('role_search')->trim()->toString();

        if ($search === '') {
            return $builder;
        }

        return $builder->where(function (Builder $query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
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
            Input::make('role_search')
                ->type('search')
                ->title(__('Search roles'))
                ->placeholder(__('Role name or description'))
                ->maxlength(150)
                ->autocomplete('off')
                ->value($this->request->get('role_search')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.$this->request->string('role_search')->trim()->toString();
    }
}
