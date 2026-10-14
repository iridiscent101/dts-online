<?php

declare(strict_types=1);

namespace App\Orchid\Fields;

use Orchid\Screen\Fields\CheckBox;

class PermissionCheckBox extends CheckBox
{
    /**
     * @var string
     */
    protected $view = 'orchid.fields.permission-checkbox';
}
