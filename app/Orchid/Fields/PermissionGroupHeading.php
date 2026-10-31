<?php

declare(strict_types=1);

namespace App\Orchid\Fields;

use Orchid\Screen\Field;

/**
 * A permission group's heading on the Edit role page, e.g. "Documents · 2 of 4 on". It submits nothing.
 */
class PermissionGroupHeading extends Field
{
    /**
     * @var string
     */
    protected $view = 'orchid.fields.permission-group-heading';
}
