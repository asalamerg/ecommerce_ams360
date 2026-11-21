<?php

namespace App\Support\Theme;

/**
 * Everything a store theme can be made of. Anything read back from storage or a
 * request goes through normalize(), so only strict #RRGGBB values and known font
 * and radius ids can ever reach a <style> tag.
 */
class ThemeCatalog
{
    public const array TOKENS = [
        'primary', 'primaryHover', 'onPrimary', 'secondary', 'muted', 'topBar',
        'headerBg', 'footer', 'background', 'surface', 'card', 'sale', 'urgent',
    ];

    public const array GROUPS = [
        'brand' => ['primary', 'primaryHover', 'onPrimary'],
        'layout' => ['secondary', 'muted', 'topBar', 'headerBg', 'footer'],
        'surfaces' => ['background', 'surface', 'card'],
        'status' => ['sale', 'urgent'],
    ];

    /** Font id => CSS family. Faces are self-hosted through vite.config.ts. */
    public const array FONTS = [
        'cairo' => 'Cairo',
        'tajawal' => 'Tajawal',
        'almarai' => 'Almarai',
        'ibmPlex' => 'IBM Plex Sans Arabic',
        'notoKufi' => 'Noto Kufi Arabic',
        'heebo' => 'Heebo',
        'system' => null,
    ];

    public const array RADII = [
        'rounded' => ['btn' => '9999px', 'card' => '1.25rem', 'input' => '0.5rem'],
        'medium' => ['btn' => '0.75rem', 'card' => '0.75rem', 'input' => '0.5rem'],
        'sharp' => ['btn' => '0.25rem', 'card' => '0.25rem', 'input' => '0.25rem'],
    ];

    /**
     * @return array<string, array<string, string>>
     */
    public static function presets(): array
    {
        return array_map(self::withDerived(...), [
            'default' => ['primary' => '#F5731C', 'primaryHover' => '#D65F0F', 'secondary' => '#1B2A4A', 'surface' => '#F4F5F7'],
            'ocean' => ['primary' => '#0284C7', 'primaryHover' => '#0369A1', 'secondary' => '#0C2D48', 'surface' => '#F0F7FC'],
            'emerald' => ['primary' => '#059669', 'primaryHover' => '#047857', 'secondary' => '#123524', 'surface' => '#F0FAF5'],
            'royal' => ['primary' => '#8B5CF6', 'primaryHover' => '#6D28D9', 'secondary' => '#23124A', 'surface' => '#F5F3FF'],
            'crimson' => ['primary' => '#E11D48', 'primaryHover' => '#BE123C', 'secondary' => '#2B1B24', 'surface' => '#FDF2F4', 'urgent' => '#B91C1C'],
            'gold' => ['primary' => '#D4A017', 'primaryHover' => '#A67C00', 'onPrimary' => '#111111', 'secondary' => '#111111', 'surface' => '#F7F5EF', 'sale' => '#B91C1C'],
            'midnight' => ['primary' => '#22D3EE', 'primaryHover' => '#06B6D4', 'onPrimary' => '#0B1120', 'secondary' => '#0B1120', 'topBar' => '#020617', 'footer' => '#020617', 'surface' => '#EEF2F7', 'sale' => '#E11D48'],
            'sunset' => ['primary' => '#EA580C', 'primaryHover' => '#C2410C', 'secondary' => '#3B1F2B', 'footer' => '#2A1520', 'surface' => '#FFF4EF', 'background' => '#FFFBF8', 'sale' => '#DB2777'],
            'teal' => ['primary' => '#0D9488', 'primaryHover' => '#0F766E', 'secondary' => '#134E4A', 'surface' => '#EFFAF8', 'sale' => '#EA580C'],
            'rose' => ['primary' => '#DB2777', 'primaryHover' => '#BE185D', 'secondary' => '#4A1D3A', 'surface' => '#FDF2F8', 'background' => '#FFFAFC', 'sale' => '#9333EA'],
            'indigo' => ['primary' => '#6366F1', 'primaryHover' => '#4F46E5', 'secondary' => '#1E1B4B', 'topBar' => '#312E81', 'surface' => '#EEF2FF', 'sale' => '#DC2626'],
            'lime' => ['primary' => '#84CC16', 'primaryHover' => '#65A30D', 'onPrimary' => '#1A1A1A', 'secondary' => '#1F2937', 'topBar' => '#111827', 'footer' => '#111827', 'surface' => '#F4F7EC', 'sale' => '#DC2626'],
            'coffee' => ['primary' => '#B45309', 'primaryHover' => '#92400E', 'secondary' => '#3E2A1E', 'surface' => '#F7F1EA', 'background' => '#FFFCF7', 'card' => '#FFFEFB', 'sale' => '#B91C1C'],
            'mono' => ['primary' => '#111827', 'primaryHover' => '#374151', 'secondary' => '#111827', 'topBar' => '#000000', 'footer' => '#000000', 'surface' => '#F3F4F6', 'sale' => '#DC2626'],
            'sky' => ['primary' => '#0EA5E9', 'primaryHover' => '#0284C7', 'onPrimary' => '#082F49', 'secondary' => '#0F2A43', 'topBar' => '#075985', 'surface' => '#F0F9FF', 'sale' => '#E11D48'],
            'plum' => ['primary' => '#A21CAF', 'primaryHover' => '#86198F', 'secondary' => '#3B0B3F', 'surface' => '#FBF3FC', 'sale' => '#E11D48'],
            'forest' => ['primary' => '#3F7D3A', 'primaryHover' => '#2F5F2C', 'secondary' => '#1E2F1C', 'surface' => '#F2F6EF', 'background' => '#FCFDFA', 'sale' => '#C2410C'],
            'graphite' => ['primary' => '#F59E0B', 'primaryHover' => '#D97706', 'onPrimary' => '#111827', 'secondary' => '#1F2933', 'topBar' => '#111827', 'footer' => '#111827', 'surface' => '#F1F3F5', 'sale' => '#DC2626'],
        ]);
    }

    /**
     * @return array{preset: string, colors: array<string, string>, font: string, radius: string, darkMode: bool, logoFavicon: bool}
     */
    public static function defaultTheme(): array
    {
        return [
            'preset' => 'default',
            'colors' => self::presets()['default'],
            'font' => 'cairo',
            'radius' => 'rounded',
            'darkMode' => false,
            'logoFavicon' => false,
        ];
    }

    /**
     * @return array{preset: string, colors: array<string, string>, font: string, radius: string, darkMode: bool, logoFavicon: bool}
     */
    public static function normalize(mixed $raw): array
    {
        $default = self::defaultTheme();
        $theme = is_array($raw) ? $raw : [];
        $source = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];

        $colors = [];

        foreach (self::TOKENS as $token) {
            if (self::isHexColor($source[$token] ?? null)) {
                $colors[$token] = strtoupper($source[$token]);
            }
        }

        $font = is_string($theme['font'] ?? null) && array_key_exists($theme['font'], self::FONTS)
            ? $theme['font']
            : $default['font'];
        $radius = is_string($theme['radius'] ?? null) && array_key_exists($theme['radius'], self::RADII)
            ? $theme['radius']
            : $default['radius'];
        $preset = $theme['preset'] ?? null;

        return [
            'preset' => is_string($preset) && $preset !== '' ? mb_substr($preset, 0, 40) : $default['preset'],
            'colors' => self::withDerived([...$default['colors'], ...array_fill_keys(self::derivedTokens(), null), ...$colors]),
            'font' => $font,
            'radius' => $radius,
            'darkMode' => ($theme['darkMode'] ?? false) === true,
            'logoFavicon' => ($theme['logoFavicon'] ?? false) === true,
        ];
    }

    public static function isHexColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1;
    }

    /**
     * @param  array<string, string|null>  $colors
     * @return array<string, string>
     */
    private static function withDerived(array $colors): array
    {
        $defined = array_filter($colors, fn (?string $color): bool => $color !== null);

        return [
            'onPrimary' => '#FFFFFF',
            'muted' => '#6B7280',
            'topBar' => $defined['secondary'],
            'headerBg' => '#FFFFFF',
            'footer' => $defined['secondary'],
            'background' => '#FFFFFF',
            'card' => '#FFFFFF',
            'sale' => $defined['primary'],
            'urgent' => '#EF4444',
            ...$defined,
        ];
    }

    /**
     * Tokens that follow the core colors unless the theme sets them.
     *
     * @return list<string>
     */
    private static function derivedTokens(): array
    {
        return ['onPrimary', 'muted', 'topBar', 'headerBg', 'footer', 'background', 'card', 'sale', 'urgent'];
    }
}
