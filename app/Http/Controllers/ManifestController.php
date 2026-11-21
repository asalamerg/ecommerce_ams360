<?php

namespace App\Http\Controllers;

use App\Services\ThemeManager;
use App\Support\StorefrontSeo;
use App\Support\Theme\ThemeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

/**
 * The web app manifest, so phones offer "Add to home screen" and open the
 * store full screen with its own icon. Colors follow the published theme, and
 * the PNG icons are drawn from the same design as the tab icon.
 */
class ManifestController extends Controller
{
    public const array ICON_SIZES = [192, 512];

    public function __invoke(ThemeManager $themes): JsonResponse
    {
        $locale = App::getLocale();
        $name = StorefrontSeo::siteName($locale);
        // The theme version in the address makes phones fetch new icons after a theme change.
        $version = substr(md5($this->color($themes, 'primary', '#F97316').$this->color($themes, 'onPrimary', '#FFFFFF')), 0, 8);

        $icons = [];

        foreach (self::ICON_SIZES as $size) {
            $icons[] = ['src' => "/icons/app-{$size}.png?v={$version}", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => "/icons/app-{$size}-maskable.png?v={$version}", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'maskable'];
        }

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'lang' => $locale,
            'dir' => in_array($locale, ['ar', 'he'], true) ? 'rtl' : 'ltr',
            'start_url' => "/{$locale}?source=app",
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $this->color($themes, 'surface', '#FFFFFF'),
            'theme_color' => $this->color($themes, 'topBar', '#000000'),
            'icons' => $icons,
        ], headers: [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * A lightning bolt on the theme's main color. "Maskable" fills the whole
     * square and keeps the bolt inside the middle 60%, because phones cut the
     * icon into their own shape (circle, squircle).
     */
    public function maskableIcon(ThemeManager $themes, string $size): Response
    {
        return $this->draw($themes, (int) $size, true);
    }

    public function icon(ThemeManager $themes, string $size): Response
    {
        return $this->draw($themes, (int) $size, false);
    }

    private function draw(ThemeManager $themes, int $size, bool $maskable): Response
    {
        abort_unless(in_array($size, self::ICON_SIZES, true), 404);

        $background = $this->color($themes, 'primary', '#F97316');
        $foreground = $this->color($themes, 'onPrimary', '#FFFFFF');

        // Base64, because the database cache store keeps text, not binary.
        $png = base64_decode((string) Cache::remember("app-icon.{$size}.{$maskable}.{$background}.{$foreground}", 86400, function () use ($size, $maskable, $background, $foreground): string {
            $image = imagecreatetruecolor($size, $size);
            imagesavealpha($image, true);
            imagealphablending($image, false);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagealphablending($image, true);

            $fill = $this->allocate($image, $background);
            $maskable
                ? imagefilledrectangle($image, 0, 0, $size, $size, $fill)
                : $this->roundedSquare($image, $size, (int) round($size * 0.22), $fill);

            // The tab icon's path "M18 3 7 18h7l-2 11 11-16h-7z" on a 32 unit grid.
            $scale = ($maskable ? 0.6 : 0.86) * $size / 32;
            $offset = ($size - 32 * $scale) / 2;
            $points = [];

            foreach ([[18, 3], [7, 18], [14, 18], [12, 29], [23, 13], [16, 13]] as [$x, $y]) {
                $points[] = (int) round($offset + $x * $scale);
                $points[] = (int) round($offset + $y * $scale);
            }

            imagefilledpolygon($image, $points, $this->allocate($image, $foreground));

            ob_start();
            imagepng($image, null, 9);

            return base64_encode((string) ob_get_clean());
        }));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    private function color(ThemeManager $themes, string $key, string $fallback): string
    {
        $value = $themes->liveTheme()['colors'][$key] ?? null;

        return ThemeCatalog::isHexColor($value) ? (string) $value : $fallback;
    }

    private function allocate(\GdImage $image, string $hex): int
    {
        $channel = fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

        return (int) imagecolorallocate($image, $channel(1), $channel(3), $channel(5));
    }

    private function roundedSquare(\GdImage $image, int $size, int $radius, int $color): void
    {
        imagefilledrectangle($image, $radius, 0, $size - $radius - 1, $size - 1, $color);
        imagefilledrectangle($image, 0, $radius, $size - 1, $size - $radius - 1, $color);

        foreach ([[$radius, $radius], [$size - $radius - 1, $radius], [$radius, $size - $radius - 1], [$size - $radius - 1, $size - $radius - 1]] as [$x, $y]) {
            imagefilledellipse($image, $x, $y, $radius * 2, $radius * 2, $color);
        }
    }
}
