<?php

namespace App\Support;

use App\Models\DocumentMovement;
use Illuminate\Support\HtmlString;

/**
 * The status wording people see. Stored statuses keep their names (workflow rules, filters and history use them);
 * only the label shown on screens and labels changes. Both waiting statuses show as "Pending", and the description
 * says which kind of waiting it is.
 */
final class StatusLabel
{
    public const PENDING = 'Pending';

    private const DESCRIPTIONS = [
        DocumentMovement::STATUS_AWAITING_RECEIPT => 'Not received yet',
        DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT => 'Waiting for paper copy',
    ];

    public static function for(?string $status): string
    {
        return array_key_exists((string) $status, self::DESCRIPTIONS) ? self::PENDING : (string) $status;
    }

    /**
     * Which kind of "Pending" a status is ("Not received yet", "Waiting for paper copy"); null for other statuses.
     */
    public static function description(?string $status): ?string
    {
        return self::DESCRIPTIONS[$status] ?? null;
    }

    /**
     * A hover description for a status badge, so "Pending" says which kind of waiting it is.
     */
    public static function titleAttribute(?string $status): HtmlString
    {
        $description = self::description($status);

        return new HtmlString($description === null ? '' : 'title="'.e(self::PENDING.': '.$description).'"');
    }
}
