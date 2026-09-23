<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Role;

use App\Orchid\Filters\RoleSearchFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class RoleFiltersLayout extends Selection
{
    public $template = self::TEMPLATE_LINE;

    /**
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            RoleSearchFilter::class,
        ];
    }
}
