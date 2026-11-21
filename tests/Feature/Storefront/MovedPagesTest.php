<?php

namespace Tests\Feature\Storefront;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A product or category asked for under a slug it no longer has here, such as
 * one from another language, moves to its own address instead of "Not Found".
 */
class MovedPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://shop.test']);
    }

    private function product(string $base = 'galaxy-s25', bool $active = true): Product
    {
        $product = Product::create([
            'price' => '3499.0000', 'currency' => 'ILS', 'stock_quantity' => 4,
            'is_active' => $active, 'is_featured' => false, 'price_is_estimate' => false,
        ]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $product->translations()->create(['locale' => $locale, 'name' => "Galaxy {$locale}", 'slug' => "{$base}-{$locale}"]);
        }

        return $product;
    }

    private function category(): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 1]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $category->translations()->create(['locale' => $locale, 'name' => "Phones {$locale}", 'slug' => "phones-{$locale}"]);
        }

        return $category;
    }

    private function staff(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_a_slug_from_another_language_moves_to_this_language_s_slug(): void
    {
        $this->product();
        $this->category();

        $this->get('/en/products/galaxy-s25-ar')->assertStatus(301)->assertRedirect('https://shop.test/en/products/galaxy-s25-en');
        $this->get('/he/categories/phones-en')->assertStatus(301)->assertRedirect('https://shop.test/he/categories/phones-he');
        $this->get('/en/products/does-not-exist')->assertNotFound();
    }

    public function test_the_not_found_page_speaks_the_visitor_s_language_and_leads_back_into_the_store(): void
    {
        $this->get('/ar/products/does-not-exist')->assertNotFound()
            ->assertSee('الصفحة غير موجودة')
            ->assertSee('action="/ar/products"', false)
            ->assertSee('<meta name="robots" content="noindex">', false);

        $this->get('/en/no/such/page')->assertNotFound()->assertSee('Page not found')->assertSee('href="/en/categories"', false);
        $this->get('/he/no-such-page')->assertNotFound()->assertSee('הדף לא נמצא');
        $this->get('/no-such-page')->assertNotFound()->assertSee('lang="ar"', false);
    }

    public function test_the_sitemap_lists_product_photos(): void
    {
        $product = $this->product();
        $product->images()->create(['path' => 'catalog/products/galaxy/1.webp', 'position' => 0]);

        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);
        $this->assertStringContainsString('<image:loc>https://shop.test/storage/catalog/products/galaxy/1.webp</image:loc>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_catalog_staff_get_an_edit_link_on_store_pages_and_nobody_else_does(): void
    {
        $product = $this->product();
        $category = $this->category();

        $this->actingAs($this->staff(UserRole::ProductManager))
            ->get('/ar/products/galaxy-s25-ar')
            ->assertInertia(fn (Assert $page) => $page
                ->where('adminEdit.type', 'product')
                ->where('adminEdit.url', "/admin/products/{$product->id}/edit")
                ->where('auth.user.is_staff', true));

        $this->get('/ar/categories/phones-ar')
            ->assertInertia(fn (Assert $page) => $page->where('adminEdit.url', "/admin/categories/{$category->id}/edit"));

        $this->actingAs($this->staff(UserRole::OrderManager))
            ->get('/ar/products/galaxy-s25-ar')
            ->assertInertia(fn (Assert $page) => $page->where('adminEdit', null)->where('auth.user.is_staff', true));

        $this->actingAs($this->staff(UserRole::Customer))
            ->get('/ar/products/galaxy-s25-ar')
            ->assertInertia(fn (Assert $page) => $page->where('adminEdit', null));
    }
}
