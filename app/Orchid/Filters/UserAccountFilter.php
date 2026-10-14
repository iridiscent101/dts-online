<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Finds incomplete accounts for access reviews: users without a role or an office cannot work in the system.
 */
class UserAccountFilter extends Filter
{
    private const OPTIONS = [
        'no_role' => 'No role',
        'no_office' => 'No office assigned',
    ];

    public function name(): string
    {
        return __('Account check');
    }

    public function parameters(): ?array
    {
        return ['user_account'];
    }

    public function run(Builder $builder): Builder
    {
        return match ($this->request->string('user_account')->toString()) {
            'no_role' => $builder->doesntHave('roles'),
            'no_office' => $builder->doesntHave('offices')
                ->where(fn (Builder $query): Builder => $query->whereNull('office')->orWhere('office', '')),
            default => $builder,
        };
    }

    /**
     * @return Select[]
     */
    public function display(): iterable
    {
        return [
            Select::make('user_account')
                ->options(array_map(__(...), self::OPTIONS))
                ->empty(__('All accounts'))
                ->value($this->request->get('user_account'))
                ->title(__('Account check')),
        ];
    }

    public function value(): string
    {
        return $this->name().': '.__(self::OPTIONS[$this->request->string('user_account')->toString()] ?? '');
    }
}
