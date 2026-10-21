<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use App\Auth\AccountActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;

/**
 * Sign-in activity: find entries by name, email or IP address, and by what happened.
 */
class AccountActivityFilter extends Filter
{
    public function name(): string
    {
        return __('Search sign-in activity');
    }

    public function parameters(): ?array
    {
        return ['activity_search', 'activity_event'];
    }

    public function run(Builder $builder): Builder
    {
        $search = $this->request->string('activity_search')->trim()->limit(150, '')->toString();
        $event = $this->request->string('activity_event')->toString();

        return $builder
            ->when(isset(AccountActivity::LABELS[$event]), fn (Builder $query): Builder => $query->where('event', $event))
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
     * @return iterable<Input|Select>
     */
    public function display(): iterable
    {
        return [
            Input::make('activity_search')
                ->type('search')
                ->title(__('Search'))
                ->placeholder(__('Name, email or IP address'))
                ->maxlength(150)
                ->autocomplete('off')
                ->value($this->request->get('activity_search')),

            Select::make('activity_event')
                ->options(array_map(__(...), AccountActivity::LABELS))
                ->empty(__('Everything'))
                ->value($this->request->get('activity_event'))
                ->title(__('What happened')),
        ];
    }

    public function value(): string
    {
        return $this->name();
    }
}
