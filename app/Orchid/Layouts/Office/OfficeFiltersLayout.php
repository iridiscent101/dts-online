<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Office;

use App\Orchid\Filters\OfficeListFilter;
use App\Orchid\Filters\OfficeSearchFilter;
use App\Orchid\Filters\OfficeStatusFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class OfficeFiltersLayout extends Selection
{
    public $template = self::TEMPLATE_LINE;

    /**
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            OfficeSearchFilter::class,
            OfficeStatusFilter::class,
            OfficeListFilter::class,
        ];
    }
}
