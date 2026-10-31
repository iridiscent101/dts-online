<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\HtmlString;

/**
 * One way to show a date and time in staff tables (Manila time, 12-hour clock): the date, with the time below it in
 * muted text. The dashboard queue and the document registry use the same look.
 */
final class DisplayDate
{
    public const TIMEZONE = 'Asia/Manila';

    public static function cell(?CarbonInterface $moment, string $empty = '—'): HtmlString
    {
        if ($moment === null) {
            return new HtmlString(e($empty));
        }

        $local = $moment->copy()->timezone(self::TIMEZONE);

        return new HtmlString(sprintf(
            '<time class="dts-date-cell" datetime="%s">%s<span class="dts-muted">%s</span></time>',
            e($local->toIso8601String()),
            e($local->format('M d, Y')),
            e($local->format('g:i A')),
        ));
    }
}
