<?php

namespace App\Orchid\Layouts\User;

use App\Orchid\Filters\RoleFilter;
use App\Orchid\Filters\UserSearchFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class UserFiltersLayout extends Selection
{
    public $template = self::TEMPLATE_LINE;

    /**
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            UserSearchFilter::class,
            RoleFilter::class,
        ];
    }
}
