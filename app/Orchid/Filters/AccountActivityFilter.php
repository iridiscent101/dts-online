<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;

/**
 * Sign-in activity: find entries by name, email or IP address. AccountActivityEventFilter narrows by what happened.
 */
class AccountActivityFilter extends Filter
{
    public function name(): string
    {
        return __('Search sign-in activity');
    }

    public function parameters(): ?array
    {
        return ['activity_search'];
    }

    public function run(Builder $builder): Builder
    {
        $search = $this->request->string('activity_search')->trim()->limit(150, '')->toString();

        return $builder
            ->when($search !== '', function (Builder $query) use ($search): void {
                $userIds = User::query()
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->pluck('id');

                $query->where(function (Builder $query) use ($search, $userIds): void {
                    $query->where('properties->email', 'like', "%{$search}%")
                        ->orWhere('properties->ip', 'like', "%{$search}%")
                        ->orWhere(fn (Builder $query): Builder => $query->where('subject_type', (new User)->getMorphClass())->whereIn('subject_id', $userIds))
                        ->orWhere(fn (Builder $query): Builder => $query->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $userIds));
                });
            });
    }

    /**
     * @return Input[]
     */
    public function display(): iterable
    {
        return [
            Input::make('activity_search')
                ->type('search')
                ->title(__('Search sign-in activity'))
                ->placeholder(__('Name, email or IP address'))
                ->maxlength(150)
                ->autocomplete('off')
                ->value($this->request->get('activity_search')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.$this->request->string('activity_search')->trim()->toString();
    }
}
