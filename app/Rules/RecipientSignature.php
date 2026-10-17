<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A recipient's drawn signature: a PNG data URL, at most 100 KB decoded and 2000 × 1000 pixels. Used by the Release
 * action at the counter and by the client signing a released document on the tracking page.
 */
class RecipientSignature implements ValidationRule
{
    public const PREFIX = 'data:image/png;base64,';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_starts_with($value, self::PREFIX)) {
            $fail('Draw a valid PNG signature before releasing the document.');

            return;
        }

        $image = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($image === false || strlen($image) > 100_000) {
            $fail('The signature image must be a PNG smaller than 100 KB.');

            return;
        }

        $dimensions = @getimagesizefromstring($image);
        if ($dimensions === false || ($dimensions['mime'] ?? null) !== 'image/png'
            || $dimensions[0] > 2000 || $dimensions[1] > 1000) {
            $fail('Draw a valid PNG signature no larger than 2000 by 1000 pixels.');
        }
    }
}
