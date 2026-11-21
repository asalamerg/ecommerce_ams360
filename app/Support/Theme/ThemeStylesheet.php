<?php

namespace App\Support\Theme;

/**
 * Turns a normalized theme into CSS variables. This is the only place colors are
 * derived, so the live store, the on-site preview and the editor's panel agree.
 */
class ThemeStylesheet
{
    private const string SYSTEM_FONTS = "'Segoe UI', Tahoma, Arial, sans-serif";

    private const string DARK_TEXT = '#111827';

    private const float MINIMUM_CONTRAST = 2.5;

    /**
     * @param  array{preset: string, colors: array<string, string>, font: string, radius: string, darkMode: bool, logoFavicon: bool}  $theme
     * @param  string  $darkSelector  Rule that holds the dark palette when the theme allows it.
     */
    public static function css(array $theme, string $selector = ':root', string $darkSelector = '.dark'): string
    {
        $radius = ThemeCatalog::RADII[$theme['radius']];
        $family = ThemeCatalog::FONTS[$theme['font']];
        $fonts = $family === null ? self::SYSTEM_FONTS : "'{$family}', 'Heebo', ".self::SYSTEM_FONTS;

        $base = [
            ...self::colorVariables($theme['colors'], false),
            '--theme-radius-btn' => $radius['btn'],
            '--theme-radius-card' => $radius['card'],
            '--theme-radius-input' => $radius['input'],
            '--radius' => $radius['input'],
            '--font-sans' => $fonts,
        ];

        $css = $selector.'{'.self::declarations($base).'font-family:var(--font-sans);}';

        if ($theme['darkMode']) {
            $dark = self::colorVariables(self::darkColors($theme['colors']), true);
            $css .= $darkSelector.'{'.self::declarations($dark).'color-scheme:dark;}';
        }

        return $css;
    }

    /**
     * Color pairs that are hard to read, so the editor can warn before publishing.
     * Text on the top bar and footer is chosen automatically and needs no warning.
     *
     * @param  array<string, string>  $colors
     * @return list<string>
     */
    public static function contrastWarnings(array $colors): array
    {
        $pairs = [
            'primary' => [$colors['primary'], $colors['onPrimary']],
            'primaryHover' => [$colors['primaryHover'], $colors['onPrimary']],
            'secondary' => [$colors['secondary'], $colors['background']],
            'muted' => [$colors['muted'], $colors['background']],
            'sale' => [$colors['sale'], '#FFFFFF'],
            'urgent' => [$colors['urgent'], $colors['card']],
        ];

        return array_keys(array_filter(
            $pairs,
            fn (array $pair): bool => self::contrastRatio($pair[0], $pair[1]) < self::MINIMUM_CONTRAST,
        ));
    }

    public static function contrastRatio(string $first, string $second): float
    {
        $lighter = max(self::luminance($first), self::luminance($second));
        $darker = min(self::luminance($first), self::luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Moves a color toward black or white until it reads at the WCAG AA ratio on
     * every background, so a light brand color still gives readable links.
     *
     * @param  non-empty-list<string>  $backgrounds
     */
    public static function ensureContrast(string $color, array $backgrounds, float $ratio = 4.5): string
    {
        $lightest = max(array_map(self::luminance(...), $backgrounds));
        $target = $lightest > 0.18 ? '#000000' : '#FFFFFF';

        foreach (range(0, 20) as $step) {
            $candidate = self::mix($color, $target, $step / 20);
            $readable = array_filter($backgrounds, fn (string $background): bool => self::contrastRatio($candidate, $background) >= $ratio);

            if (count($readable) === count($backgrounds)) {
                return $candidate;
            }
        }

        return $target;
    }

    public static function readableTextOn(string $background): string
    {
        return self::contrastRatio('#FFFFFF', $background) >= self::contrastRatio(self::DARK_TEXT, $background)
            ? '#FFFFFF'
            : self::DARK_TEXT;
    }

    /**
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    private static function colorVariables(array $colors, bool $dark): array
    {
        $line = self::mix($colors['background'], $dark ? '#FFFFFF' : '#000000', $dark ? 0.13 : 0.1);

        return [
            '--brand-primary' => $colors['primary'],
            '--brand-primary-hover' => $colors['primaryHover'],
            '--brand-on-primary' => $colors['onPrimary'],
            '--brand-secondary' => $colors['secondary'],
            '--brand-muted' => $colors['muted'],
            '--brand-topbar' => $colors['topBar'],
            '--brand-on-topbar' => self::readableTextOn($colors['topBar']),
            '--brand-header' => $colors['headerBg'],
            '--brand-footer' => $colors['footer'],
            '--brand-on-footer' => self::readableTextOn($colors['footer']),
            '--brand-background' => $colors['background'],
            '--brand-surface' => $colors['surface'],
            '--brand-card' => $colors['card'],
            '--brand-sale' => $colors['sale'],
            '--brand-urgent' => $colors['urgent'],
            '--brand-accent' => self::ensureContrast($colors['primary'], [$colors['card'], $colors['surface']]),
            '--brand-line' => $line,
            '--background' => $colors['background'],
            '--foreground' => $colors['secondary'],
            '--card' => $colors['card'],
            '--card-foreground' => $colors['secondary'],
            '--popover' => $colors['card'],
            '--popover-foreground' => $colors['secondary'],
            '--primary' => $colors['primary'],
            '--primary-foreground' => $colors['onPrimary'],
            '--muted-foreground' => $colors['muted'],
            '--border' => $line,
            '--input' => $line,
            '--ring' => $colors['primary'],
        ];
    }

    /**
     * Surfaces and text flip; brand, sale and the top bar stay.
     *
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    private static function darkColors(array $colors): array
    {
        return [
            ...$colors,
            'secondary' => '#E5E7EB',
            'muted' => '#9CA3AF',
            'headerBg' => '#0F1726',
            'background' => '#0B1220',
            'surface' => '#1A2436',
            'card' => '#121A2B',
            'urgent' => '#F87171',
        ];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private static function declarations(array $variables): string
    {
        $css = '';

        foreach ($variables as $name => $value) {
            $css .= "{$name}:{$value};";
        }

        return $css;
    }

    /**
     * @return array{int, int, int}
     */
    private static function channels(string $hex): array
    {
        $value = hexdec(substr($hex, 1));

        return [($value >> 16) & 255, ($value >> 8) & 255, $value & 255];
    }

    private static function mix(string $color, string $target, float $amount): string
    {
        $from = self::channels($color);
        $to = self::channels($target);
        $mixed = '#';

        foreach ([0, 1, 2] as $channel) {
            $mixed .= sprintf('%02X', (int) round($from[$channel] + ($to[$channel] - $from[$channel]) * $amount));
        }

        return $mixed;
    }

    private static function luminance(string $hex): float
    {
        $linear = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
