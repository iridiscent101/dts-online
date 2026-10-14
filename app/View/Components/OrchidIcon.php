<?php

namespace App\View\Components;

use Orchid\Icons\IconComponent;
use Orchid\Icons\IconFinder;

/**
 * Orchid's icon without a forwarded id. Blade passes a parent component's attributes into child components, so an
 * icon inside an Orchid link or button copied that control's id and every such page had duplicate ids (WCAG 4.1.1).
 * No view gives an icon its own id.
 */
class OrchidIcon extends IconComponent
{
    public function __construct(
        IconFinder $finder,
        string $path,
        ?string $id = null,
        ?string $class = null,
        ?string $width = null,
        ?string $height = null,
        ?string $role = 'img',
        ?string $fill = 'currentColor',
    ) {
        parent::__construct($finder, $path, null, $class, $width, $height, $role, $fill);
    }
}
