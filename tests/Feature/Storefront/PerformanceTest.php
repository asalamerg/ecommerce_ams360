<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\ImageUploads;
use App\Support\ProductCardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function storeImage(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put($path, (string) ob_get_clean());
    }

    public function test_a_thumbnail_is_a_smaller_copy_next_to_the_image(): void
    {
        $this->storeImage('products/1/photo.webp', 1600, 800);

        $thumbnail = app(ImageUploads::class)->thumbnail('products/1/photo.webp');

        $this->assertSame('products/1/photo.w600.webp', $thumbnail);
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($thumbnail));
        $this->assertSame([600, 300], [$width, $height]);
    }

    public function test_small_images_need_no_thumbnail(): void
    {
        $this->storeImage('products/1/small.webp', 500, 500);

        $this->assertNull(app(ImageUploads::class)->thumbnail('products/1/small.webp'));
        $this->assertNull(app(ImageUploads::class)->thumbnail('products/1/missing.webp'));
    }

    public function test_cards_use_the_thumbnail_and_the_product_page_the_full_image(): void
    {
        $product = $this->makeProduct();
        $this->storeImage('catalog/products/a/1.webp', 1200, 1200);
        $product->images()->create(['path' => 'catalog/products/a/1.webp', 'position' => 0]);

        $this->artisan('store:make-thumbnails')->assertSuccessful();

        $image = $product->images()->first();
        $this->assertSame('catalog/products/a/1.w600.webp', $image->thumb_path);
        $this->assertSame('/storage/catalog/products/a/1.w600.webp', ProductCardData::thumbnailUrl($image));

        $slug = $product->translations->firstWhere('locale', 'en')->slug;
        $this->get("/en/products/{$slug}")->assertInertia(fn (Assert $page) => $page
            ->where('product.image_url', '/storage/catalog/products/a/1.w600.webp')
            ->where('product.images.0', '/storage/catalog/products/a/1.webp'));
    }

    public function test_deleting_a_product_removes_its_thumbnails_too(): void
    {
        $product = $this->makeProduct();
        $this->storeImage('products/9/a.webp', 1200, 1200);
        $product->images()->create(['path' => 'products/9/a.webp', 'thumb_path' => app(ImageUploads::class)->thumbnail('products/9/a.webp'), 'position' => 0]);

        $this->actingAs($this->staff())->delete("/admin/products/{$product->id}")->assertRedirect();

        Storage::disk('public')->assertMissing('products/9/a.webp');
        Storage::disk('public')->assertMissing('products/9/a.w600.webp');
    }

    /**
     * The array store used in tests keeps objects as they are. Real stores
     * serialize, and Laravel refuses to rebuild objects from the cache, so
     * anything cached must be plain arrays or the page breaks on the second visit.
     */
    public function test_cached_storefront_data_survives_a_real_cache_store(): void
    {
        config(['cache.default' => 'file']);
        Cache::flush();
        $category = $this->makeCategory();

        foreach (range(1, 3) as $index) {
            $this->makeProduct(['category_id' => $category->id, 'brand_id' => DB::table('brands')->insertGetId([
                'slug' => "brand-{$index}", 'name' => json_encode(['en' => "Brand {$index}"]), 'sort_order' => 0, 'is_active' => true,
            ])]);
        }

        // Each page twice: the second visit is served from the cache.
        foreach (['/en', '/en', '/en/products', '/en/products', '/en/categories', '/en/categories'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('categories', fn ($value) => array_is_list($value->all())));
        }

        foreach (['/en/brands', '/en/brands'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('brands', fn ($value) => array_is_list($value->all()) && count($value) === 3));
        }

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('rails.0.products.0.id', fn ($id) => is_int($id))
            ->where('categories.0.count', 3));
        $this->getJson('/en/brands/menu')->assertJsonPath('brands.0.count', 1);

        Cache::flush();
    }

    public function test_the_cached_home_page_shows_catalog_changes_at_once(): void
    {
        $first = $this->makeProduct([], 'First');

        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('latest.0.id', $first->id));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->get('/en')->assertOk();
        $this->assertLessThan(8, $queries, 'The second visit should come from the cache.');

        $second = $this->makeProduct([], 'Second');
        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('latest.0.id', $second->id));

        Product::query()->find($second->id)->update(['is_active' => false]);
        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('latest.0.id', $first->id));

        DB::table('hero_slides')->insert(['image_url' => 'https://example.com/a.webp', 'title' => json_encode(['en' => 'Sale']), 'sort_order' => 0, 'is_active' => true]);
        $this->actingAs($this->staff())->put('/admin/modules/hero-slides/'.DB::table('hero_slides')->value('id'), [
            'image_url' => 'https://example.com/a.webp', 'title_ar' => 'تخفيض', 'title_en' => 'Big sale', 'title_he' => 'מבצע', 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('slides.0.title', 'Big sale'));
    }
}
