<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the readability settings in resources/css/dts.css: staff (many of them older) read these screens all day,
 * so text must meet WCAG AA (4.5:1), table gridlines must stay visible (3:1), and body text must be 16px.
 */
class ColorContrastTest extends TestCase
{
    private const SURFACES = [
        'white' => '#ffffff',
        'cream' => '#f8f4e7',
        'surface' => '#faf8f2',
        'surface-2' => '#f1eee4',
        'zebra' => '#fcfbf8',
    ];

    public static function textTokens(): array
    {
        return [
            ['--dts-ink'], ['--dts-ink-2'], ['--dts-ink-3'],
            ['--dts-text-muted'], ['--dts-text-body'], ['--dts-text-subtle'],
        ];
    }

    #[DataProvider('textTokens')]
    public function test_text_colors_are_readable_on_every_surface(string $token): void
    {
        $color = $this->token($token);

        foreach (self::SURFACES as $name => $surface) {
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($color, $surface), "$token on $name is below 4.5:1");
        }
    }

    public function test_table_gridlines_are_visible_on_table_rows(): void
    {
        $grid = $this->token('--dts-grid');

        foreach (['white', 'zebra', 'cream', 'surface'] as $name) {
            $this->assertGreaterThanOrEqual(3.0, $this->contrast($grid, self::SURFACES[$name]), "--dts-grid on $name is below 3:1");
        }
    }

    public function test_form_field_borders_are_visible(): void
    {
        $this->assertGreaterThanOrEqual(3.0, $this->contrast($this->token('--dts-border-strong'), '#ffffff'));
    }

    public function test_staff_screens_use_a_16px_base_font(): void
    {
        $this->assertMatchesRegularExpression('/html:root\s*\{\s*font-size:\s*100%;/', $this->css());
    }

    public function test_public_pages_do_not_use_light_grey_text(): void
    {
        // slate-500 is under 4.5:1 on the cream page background; slate-400 placeholders are about 2.6:1.
        foreach (['document-tracking', 'document-receipt', 'kiosk', 'client-document-submission'] as $view) {
            $html = file_get_contents(dirname(__DIR__, 2)."/resources/views/$view.blade.php");

            $this->assertDoesNotMatchRegularExpression('/(?<![:\w-])text-slate-500\b/', $html, "$view uses text-slate-500");
            $this->assertStringNotContainsString('placeholder:text-slate-400', $html, "$view uses a slate-400 placeholder");
        }
    }

    private function css(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/resources/css/dts.css');
    }

    private function token(string $name): string
    {
        $this->assertSame(1, preg_match('/'.preg_quote($name, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $this->css(), $match), "$name not found");

        return $match[1];
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(function (int $offset) use ($hex): float {
            $value = hexdec(substr(ltrim($hex, '#'), $offset, 2)) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, [0, 2, 4]);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
