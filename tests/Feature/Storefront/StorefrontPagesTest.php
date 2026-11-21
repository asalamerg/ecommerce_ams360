<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorefrontPagesTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug, int $products): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 1]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $category->translations()->create(['locale' => $locale, 'name' => "Category {$slug}", 'slug' => "{$slug}-{$locale}"]);
        }

        foreach (range(1, $products) as $index) {
            $this->product(['category_id' => $category->id, 'sku' => "{$slug}-{$index}"]);
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        $product = Product::create([
            'price' => '100.0000',
            'currency' => 'ILS',
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
            ...$attributes,
        ]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $product->translations()->create(['locale' => $locale, 'name' => 'Name '.$product->id, 'slug' => "item-{$product->id}-{$locale}"]);
        }

        return $product;
    }

    public function test_the_home_page_lists_rows_only_for_categories_with_enough_products(): void
    {
        $this->category('full', 4);
        $this->category('thin', 1);

        $this->get('/en')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('storefront/home')
            ->has('categories', 2)
            ->has('rails', 1)
            ->where('rails.0.slug', 'full-en')
            ->has('rails.0.products', 4)
            ->has('latest', 5));
    }

    public function test_home_rows_are_limited_per_category(): void
    {
        $this->category('big', 15);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page->has('rails.0.products', 12));
    }

    public function test_hidden_products_never_appear_in_home_rows(): void
    {
        $category = $this->category('mixed', 3);
        $this->product(['category_id' => $category->id, 'is_active' => false]);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->has('rails.0.products', 3)
            ->has('latest', 3));
    }

    public function test_the_home_query_count_does_not_grow_with_products(): void
    {
        $this->category('one', 12);

        DB::enableQueryLog();
        $this->get('/en');
        $small = count(DB::getQueryLog());

        $this->category('two', 12);
        $this->category('three', 12);
        cache()->flush();
        DB::flushQueryLog();

        $this->get('/en');
        $large = count(DB::getQueryLog());

        $this->assertLessThanOrEqual($small + 2, $large);
    }

    public function test_the_all_categories_page_opens(): void
    {
        $this->category('full', 1);

        $this->get('/ar/categories')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('storefront/categories')
            ->has('categories', 1));
    }

    public function test_products_can_be_sorted_by_price(): void
    {
        $this->product(['price' => '300.0000']);
        $this->product(['price' => '100.0000']);
        $this->product(['price' => '200.0000']);

        $this->get('/en/products?sort=price_asc')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price', '100.0000')
            ->where('sort', 'price_asc'));

        $this->get('/en/products?sort=price_desc')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price', '300.0000'));
    }

    public function test_an_unknown_sort_is_refused(): void
    {
        $this->from('/en/products')->get('/en/products?sort=drop')->assertSessionHasErrors('sort');
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->product();

        $this->get('/en/products?search=%25')->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    }

    public function test_the_product_page_offers_related_products_without_itself(): void
    {
        $category = $this->category('group', 3);
        $product = $category->products()->firstOrFail();
        $slug = $product->translations()->where('locale', 'en')->value('slug');

        $this->get("/en/products/{$slug}")->assertInertia(fn (Assert $page) => $page
            ->component('storefront/show')
            ->has('related', 2)
            ->where('product.purchasable', true));
    }

    public function test_the_footer_shows_admin_settings_and_only_published_pages(): void
    {
        DB::table('site_settings')->updateOrInsert(['id' => 1], ['phone' => '050-111', 'whatsapp' => '+972 50 111', 'facebook_url' => 'https://facebook.com/x']);
        DB::table('pages')->insert([
            ['slug' => 'about', 'title' => json_encode(['en' => 'About us']), 'content' => json_encode(['en' => 'Hi']), 'is_active' => true],
            ['slug' => 'draft', 'title' => json_encode(['en' => 'Draft']), 'content' => json_encode(['en' => 'No']), 'is_active' => false],
        ]);

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->where('footer.phone', '050-111')
            ->where('footer.whatsapp', 'https://wa.me/97250111')
            ->has('footer.pages', 1)
            ->where('footer.pages.0.title', 'About us'));
    }

    public function test_published_pages_open_and_unpublished_ones_do_not(): void
    {
        DB::table('pages')->insert([
            ['slug' => 'about', 'title' => json_encode(['en' => 'About']), 'content' => json_encode(['en' => 'Hi']), 'is_active' => true],
            ['slug' => 'draft', 'title' => json_encode(['en' => 'Draft']), 'content' => json_encode(['en' => 'No']), 'is_active' => false],
        ]);

        $this->get('/en/pages/about')->assertOk()->assertInertia(fn (Assert $page) => $page->where('page.content', 'Hi'));
        $this->get('/en/pages/draft')->assertNotFound();
    }

    public function test_cart_and_footer_are_not_sent_to_the_admin_panel_or_auth_pages(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('cart', null)->where('footer', null));
    }
}
