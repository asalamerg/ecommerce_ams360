<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class BrandsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function brand(string $slug, string $name, ?string $logo = null, bool $active = true, int $order = 0): int
    {
        return (int) DB::table('brands')->insertGetId([
            'slug' => $slug, 'name' => json_encode(['en' => $name, 'ar' => "{$name} ar"]),
            'description' => json_encode(['en' => "All about {$name}"]),
            'logo_url' => $logo, 'sort_order' => $order, 'is_active' => $active,
        ]);
    }

    public function test_the_menu_lists_brands_with_products_and_a_picture_for_each(): void
    {
        $baseus = $this->brand('baseus', 'Baseus', '/storage/uploads/brands/logo.webp');
        $anker = $this->brand('anker', 'Anker');
        $this->brand('empty', 'Empty');
        $hidden = $this->brand('hidden', 'Hidden', active: false);

        $this->makeProduct(['brand_id' => $baseus]);
        $this->makeProduct(['brand_id' => $anker])->images()->create(['path' => 'products/1/a.webp', 'thumb_path' => 'products/1/a.w600.webp', 'position' => 0]);
        $this->makeProduct(['brand_id' => $anker]);
        $this->makeProduct(['brand_id' => $hidden]);

        $this->getJson('/en/brands/menu')
            ->assertOk()
            ->assertJsonCount(2, 'brands')
            // Most products first when the admin order is equal.
            ->assertJsonPath('brands.0.slug', 'anker')
            ->assertJsonPath('brands.0.count', 2)
            ->assertJsonPath('brands.0.logo_url', null)
            ->assertJsonPath('brands.0.image_url', '/storage/products/1/a.w600.webp')
            ->assertJsonPath('brands.1.name', 'Baseus')
            ->assertJsonPath('brands.1.logo_url', '/storage/uploads/brands/logo.webp');

        $this->getJson('/ar/brands/menu')->assertJsonPath('brands.1.name', 'Baseus ar');
    }

    public function test_a_brand_page_lists_its_products_and_is_indexable(): void
    {
        $brand = $this->brand('baseus', 'Baseus');
        $mine = $this->makeProduct(['brand_id' => $brand]);
        $this->makeProduct();

        $this->get('/en/brands/baseus')->assertInertia(fn (Assert $page) => $page
            ->component('storefront/index')
            ->has('products.data', 1)
            ->where('products.data.0.id', $mine->id)
            ->where('products.data.0.brand_slug', 'baseus')
            ->where('brandPage.name', 'Baseus')
            ->where('brandPage.description', 'All about Baseus')
            ->where('seo.robots', 'index, follow, max-image-preview:large')
            ->where('seo.canonical', url('/en/brands/baseus')));

        $this->get('/en/brands/baseus?price_max=10')->assertInertia(fn (Assert $page) => $page->where('seo.robots', 'noindex, follow'));
        $this->get('/en/brands/missing')->assertNotFound();
    }

    public function test_hidden_brands_have_no_page(): void
    {
        $this->brand('hidden', 'Hidden', active: false);

        $this->get('/en/brands/hidden')->assertNotFound();
    }

    public function test_the_brands_page_and_the_sitemap_list_brand_pages(): void
    {
        $this->makeProduct(['brand_id' => $this->brand('baseus', 'Baseus')]);

        $this->get('/en/brands')->assertInertia(fn (Assert $page) => $page
            ->component('storefront/brands')
            ->where('brands.0.slug', 'baseus')
            ->where('seo.title', fn (string $title) => str_contains($title, 'Brands')));

        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/en/brands/baseus'), false)->assertSee(url('/he/brands'), false);
    }

    public function test_a_new_logo_reaches_the_menu_at_once(): void
    {
        $brand = $this->brand('baseus', 'Baseus');
        $this->makeProduct(['brand_id' => $brand]);
        $this->getJson('/en/brands/menu')->assertJsonPath('brands.0.logo_url', null);

        $this->actingAs($this->staff())->put("/admin/modules/brands/{$brand}", [
            'slug' => 'baseus', 'name_ar' => 'Baseus', 'name_en' => 'Baseus', 'name_he' => 'Baseus',
            'logo_url' => 'https://cdn.example.com/baseus.png', 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->getJson('/en/brands/menu')->assertJsonPath('brands.0.logo_url', 'https://cdn.example.com/baseus.png');
    }
}
