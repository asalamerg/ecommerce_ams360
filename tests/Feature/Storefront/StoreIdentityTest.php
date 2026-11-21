<?php

namespace Tests\Feature\Storefront;

use App\Support\StorefrontCache;
use App\Support\Theme\ThemeCatalog;
use App\Support\Theme\ThemeStylesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every copy of the store shows its own name, logo and icons, never another
 * shop's, and its buttons stay readable whatever colors the owner picks.
 */
class StoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_the_name_and_logo_from_the_store_settings(): void
    {
        DB::table('site_settings')->updateOrInsert(['id' => 1], [
            'site_name' => json_encode(['ar' => 'متجر النور', 'en' => 'Noor Store']),
            'logo_url' => '/storage/logo.webp',
        ]);
        StorefrontCache::flush();

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('store.name', 'Noor Store')
            ->where('store.logo', '/storage/logo.webp'));

        $this->get('/ar')
            ->assertInertia(fn (Assert $page) => $page->where('store.name', 'متجر النور'))
            ->assertSee('<title>', false)
            ->assertSee('متجر النور');
    }

    public function test_without_settings_the_app_name_stands_in_and_no_logo_is_shown(): void
    {
        DB::table('site_settings')->updateOrInsert(['id' => 1], ['site_name' => null, 'logo_url' => null]);
        StorefrontCache::flush();
        config(['app.name' => 'Corner Shop']);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('store.name', 'Corner Shop')
            ->where('store.logo', null));
    }

    public function test_the_not_found_page_shows_the_store_s_own_name(): void
    {
        DB::table('site_settings')->updateOrInsert(['id' => 1], ['site_name' => json_encode(['en' => 'Noor Store'])]);
        StorefrontCache::flush();

        $this->get('/en/products/nothing-here')->assertNotFound()->assertSee('Noor Store');
    }

    public function test_the_favicon_and_home_screen_icon_are_drawn_from_the_theme(): void
    {
        foreach (['/favicon.ico' => 48, '/apple-touch-icon.png' => 180] as $path => $size) {
            $response = $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');

            $this->assertSame([$size, $size], array_slice((array) getimagesizefromstring((string) $response->getContent()), 0, 2));
        }
    }

    public function test_every_preset_keeps_button_and_sale_text_at_the_aa_contrast_ratio(): void
    {
        foreach (ThemeCatalog::presets() as $name => $colors) {
            $css = ThemeStylesheet::css(['preset' => $name, 'colors' => $colors, 'font' => array_key_first(ThemeCatalog::FONTS), 'radius' => array_key_first(ThemeCatalog::RADII), 'darkMode' => false, 'logoFavicon' => false]);

            preg_match('/--brand-primary:(#[0-9A-Fa-f]{6})/', $css, $primary);
            preg_match('/--brand-sale:(#[0-9A-Fa-f]{6})/', $css, $sale);

            $this->assertGreaterThanOrEqual(4.5, ThemeStylesheet::contrastRatio($primary[1], $colors['onPrimary']), "{$name}: button text");
            $this->assertGreaterThanOrEqual(4.5, ThemeStylesheet::contrastRatio($sale[1], '#FFFFFF'), "{$name}: sale badge");
        }
    }

    public function test_a_color_that_already_reads_well_is_kept_exactly(): void
    {
        $this->assertSame('#111827', ThemeStylesheet::ensureContrast('#111827', ['#FFFFFF']));
    }
}
