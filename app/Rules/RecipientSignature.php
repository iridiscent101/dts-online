<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A recipient's drawn signature: a PNG data URL, at most 100 KB decoded and 2000 × 1000 pixels.
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

            return;
        }

        $canvas = function_exists('imagecreatefromstring') ? @imagecreatefromstring($image) : false;
        if ($canvas === false || ! $this->hasVisibleStroke($canvas)) {
            $fail('Draw a signature with a visible stroke before releasing the document.');
        }
    }

    private function hasVisibleStroke(\GdImage $canvas): bool
    {
        $minimumX = imagesx($canvas);
        $minimumY = imagesy($canvas);
        $maximumX = 0;
        $maximumY = 0;

        for ($y = 0; $y < imagesy($canvas); $y++) {
            for ($x = 0; $x < imagesx($canvas); $x++) {
                $pixel = imagecolorat($canvas, $x, $y);
                if ($pixel === false) {
                    continue;
                }

                $color = imagecolorsforindex($canvas, $pixel);
                if ($color['alpha'] >= 96 || $color['red'] + $color['green'] + $color['blue'] >= 600) {
                    continue;
                }

                $minimumX = min($minimumX, $x);
                $minimumY = min($minimumY, $y);
                $maximumX = max($maximumX, $x);
                $maximumY = max($maximumY, $y);
                if ($maximumX - $minimumX > 4 || $maximumY - $minimumY > 4) {
                    return true;
                }
            }
        }

        return false;
    }
}
