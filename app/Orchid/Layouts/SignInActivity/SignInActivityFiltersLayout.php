<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SignInActivity;

use App\Orchid\Filters\AccountActivityEventFilter;
use App\Orchid\Filters\AccountActivityFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class SignInActivityFiltersLayout extends Selection
{
    public $template = self::TEMPLATE_LINE;

    /**
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            AccountActivityFilter::class,
            AccountActivityEventFilter::class,
        ];
    }
}
