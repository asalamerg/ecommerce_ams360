<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class BrandLogosTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function brand(string $slug, ?string $logo = null): int
    {
        return (int) DB::table('brands')->insertGetId(['slug' => $slug, 'name' => json_encode(['en' => ucfirst($slug), 'ar' => ucfirst($slug)]), 'logo_url' => $logo, 'sort_order' => 0, 'is_active' => true]);
    }

    /**
     * A 400x300 picture with a 120x60 mark in the middle, on white or transparent.
     */
    private function logoFile(bool $transparent = false): UploadedFile
    {
        $image = imagecreatetruecolor(400, 300);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 140, 120, 259, 179, imagecolorallocate($image, 200, 20, 20));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean());
    }

    public function test_the_page_lists_brands_without_a_logo_first(): void
    {
        $this->brand('anker', 'https://cdn.example.com/anker.png');
        $baseus = $this->brand('baseus');

        $this->actingAs($this->staff(UserRole::ProductManager))->get('/admin/brands/logos')->assertInertia(fn (Assert $page) => $page
            ->component('admin/brands/logos')
            ->where('withLogo', 1)
            ->where('brands.0.id', $baseus)
            ->where('brands.0.logo_url', null));

        $this->actingAs($this->staff(UserRole::OrderManager))->get('/admin/brands/logos')->assertForbidden();
    }

    public function test_an_uploaded_logo_loses_its_empty_margin(): void
    {
        foreach ([false, true] as $transparent) {
            $brand = $this->brand($transparent ? 'clear' : 'white');

            $this->actingAs($this->staff())->post("/admin/brands/{$brand}/logo", ['logo' => $this->logoFile($transparent)])
                ->assertRedirect()->assertSessionHasNoErrors();

            $url = (string) DB::table('brands')->where('id', $brand)->value('logo_url');
            $this->assertMatchesRegularExpression('#^/storage/uploads/brands/[A-Za-z0-9]{40}\.webp$#', $url);

            [$width, $height] = getimagesizefromstring(Storage::disk('public')->get(substr($url, strlen('/storage/'))));
            $this->assertEqualsWithDelta(120, $width, 4, 'The white or transparent border is trimmed.');
            $this->assertEqualsWithDelta(60, $height, 4);
        }

        $this->assertDatabaseHas('store_admin_activities', ['section' => 'brands', 'action' => 'logo_uploaded']);
    }

    public function test_a_dark_logo_on_a_transparent_background_is_kept_whole(): void
    {
        // Navy is close to the transparent black around it in color, not in alpha.
        $image = imagecreatetruecolor(500, 300);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 120, 110, 379, 189, imagecolorallocate($image, 15, 23, 42));
        ob_start();
        imagepng($image);
        $brand = $this->brand('navy');

        $this->actingAs($this->staff())->post("/admin/brands/{$brand}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', (string) ob_get_clean()),
        ])->assertSessionHasNoErrors();

        $path = substr((string) DB::table('brands')->where('id', $brand)->value('logo_url'), strlen('/storage/'));
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([260, 80], [$width, $height]);
    }

    public function test_a_replaced_or_removed_upload_is_deleted_but_outside_links_are_not(): void
    {
        $brand = $this->brand('baseus');
        $admin = $this->staff();

        $this->actingAs($admin)->post("/admin/brands/{$brand}/logo", ['logo' => $this->logoFile()]);
        $first = substr((string) DB::table('brands')->where('id', $brand)->value('logo_url'), strlen('/storage/'));

        $this->actingAs($admin)->post("/admin/brands/{$brand}/logo", ['logo' => $this->logoFile()]);
        Storage::disk('public')->assertMissing($first);

        $second = substr((string) DB::table('brands')->where('id', $brand)->value('logo_url'), strlen('/storage/'));
        $this->actingAs($admin)->delete("/admin/brands/{$brand}/logo")->assertRedirect();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull(DB::table('brands')->where('id', $brand)->value('logo_url'));
    }

    public function test_files_that_are_not_images_are_refused(): void
    {
        $brand = $this->brand('baseus');

        $this->actingAs($this->staff())->post("/admin/brands/{$brand}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', '<svg onload="alert(1)"></svg>'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(DB::table('brands')->where('id', $brand)->value('logo_url'));
    }

    public function test_a_new_logo_reaches_the_storefront_at_once_and_logos_lead_the_home_row(): void
    {
        $plain = $this->brand('plain');
        $branded = $this->brand('branded');
        $this->makeProduct(['brand_id' => $plain]);
        $this->makeProduct(['brand_id' => $plain]);
        $this->makeProduct(['brand_id' => $branded]);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('brands.0.slug', 'plain'));

        $this->actingAs($this->staff())->post("/admin/brands/{$branded}/logo", ['logo' => $this->logoFile()]);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('brands.0.slug', 'branded')
            ->where('brands.0.logo_url', fn (string $url) => str_starts_with($url, '/storage/uploads/brands/')));
    }
}
