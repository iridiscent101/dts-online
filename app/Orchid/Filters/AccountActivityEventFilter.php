<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use App\Auth\AccountActivity;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Sign-in activity: show only one kind of entry (failed sign-ins, lockouts, ...).
 */
class AccountActivityEventFilter extends Filter
{
    public function name(): string
    {
        return __('What happened');
    }

    public function parameters(): ?array
    {
        return ['activity_event'];
    }

    public function run(Builder $builder): Builder
    {
        $event = $this->request->string('activity_event')->toString();

        return $builder->when(isset(AccountActivity::LABELS[$event]), fn (Builder $query): Builder => $query->where('event', $event));
    }

    /**
     * @return Select[]
     */
    public function display(): iterable
    {
        return [
            Select::make('activity_event')
                ->options(array_map(__(...), AccountActivity::LABELS))
                ->empty(__('Everything'))
                ->value($this->request->get('activity_event'))
                ->title(__('What happened')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.__(AccountActivity::LABELS[$this->request->string('activity_event')->toString()] ?? '');
    }
}
