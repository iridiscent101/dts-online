<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Finds accounts for access reviews: users without a role or an office cannot work in the system, and deactivated
 * accounts cannot sign in.
 */
class UserAccountFilter extends Filter
{
    private const OPTIONS = [
        'no_role' => 'No role',
        'no_office' => 'No office assigned',
        'active' => 'Active accounts',
        'deactivated' => 'Deactivated accounts',
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
            'no_office' => $builder->doesntHave('offices'),
            'active' => $builder->whereNull('deactivated_at'),
            'deactivated' => $builder->whereNotNull('deactivated_at'),
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
