<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\School;

use App\Orchid\Filters\SchoolSearchFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class SchoolFiltersLayout extends Selection
{
    public $template = self::TEMPLATE_LINE;

    /**
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            SchoolSearchFilter::class,
        ];
    }
}
