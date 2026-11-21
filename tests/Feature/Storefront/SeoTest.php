<?php

namespace Tests\Feature\Storefront;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://shop.test']);
    }

    private function category(): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 1]);

        foreach (['ar' => 'هواتف', 'en' => 'Phones', 'he' => 'טלפונים'] as $locale => $name) {
            $category->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => "phones-{$locale}"]);
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = [], string $name = 'Galaxy S25'): Product
    {
        $product = Product::create([
            'price' => '3499.0000',
            'currency' => 'ILS',
            'stock_quantity' => 4,
            'is_active' => true,
            'is_featured' => false,
            'price_is_estimate' => false,
            'sku' => 'SM-S25',
            ...$attributes,
        ]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $product->translations()->create([
                'locale' => $locale,
                'name' => "{$name} {$locale}",
                'slug' => "galaxy-{$product->id}-{$locale}",
                'description' => "<p>A great   phone</p>\n<p>with a big screen</p>",
            ]);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $match));

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function node(array $schema, string $type): array
    {
        return collect($schema['@graph'])->firstWhere('@type', $type) ?? [];
    }

    public function test_a_product_page_carries_full_metadata_in_the_first_response(): void
    {
        $category = $this->category();
        $brand = Brand::create(['name' => ['ar' => 'سامسونج', 'en' => 'Samsung'], 'slug' => 'samsung', 'is_active' => true]);
        $product = $this->product(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $html = (string) $this->get("/en/products/galaxy-{$product->id}-en")->assertOk()->getContent();

        $this->assertStringContainsString('<title>Galaxy S25 en - ', $html);
        $this->assertStringContainsString('<meta name="description" content="A great phone with a big screen" data-seo>', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow, max-image-preview:large" data-seo>', $html);
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"https://shop.test/en/products/galaxy-{$product->id}-en\" data-seo>", $html);

        foreach (['ar', 'en', 'he'] as $locale) {
            $this->assertStringContainsString("hreflang=\"{$locale}\" href=\"https://shop.test/{$locale}/products/galaxy-{$product->id}-{$locale}\"", $html);
        }

        $this->assertStringContainsString("hreflang=\"x-default\" href=\"https://shop.test/ar/products/galaxy-{$product->id}-ar\"", $html);
        $this->assertStringContainsString('<meta property="og:type" content="product" data-seo>', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="en_US" data-seo>', $html);

        $schema = $this->jsonLd($html);
        $item = $this->node($schema, 'Product');

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Galaxy S25 en', $item['name']);
        $this->assertSame('SM-S25', $item['sku']);
        $this->assertSame('Samsung', $item['brand']['name']);
        $this->assertSame('Phones', $item['category']);
        $this->assertSame(['price' => '3499.00', 'priceCurrency' => 'ILS', 'availability' => 'https://schema.org/InStock'], array_intersect_key($item['offers'], array_flip(['price', 'priceCurrency', 'availability'])));

        $crumbs = array_column($this->node($schema, 'BreadcrumbList')['itemListElement'], 'name');
        $this->assertSame(['All products', 'Phones', 'Galaxy S25 en'], array_slice($crumbs, 1));
    }

    public function test_images_use_site_relative_urls_but_sharing_tags_use_full_ones(): void
    {
        $product = $this->product();
        $product->images()->create(['path' => 'products/1/photo.webp', 'position' => 0]);

        // A full URL built from APP_URL breaks under the same-origin CSP whenever
        // the page is opened on another host name (127.0.0.1 vs localhost, www).
        $this->get('/ar/products')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.image_url', '/storage/products/1/photo.webp'));

        $this->get("/ar/products/galaxy-{$product->id}-ar")->assertInertia(fn (Assert $page) => $page
            ->where('product.images.0', '/storage/products/1/photo.webp')
            ->where('seo.image', 'https://shop.test/storage/products/1/photo.webp')
            ->where('seo.schema.@graph.0.image.0', 'https://shop.test/storage/products/1/photo.webp'));
    }

    public function test_estimated_prices_are_not_published_as_offers(): void
    {
        $product = $this->product(['price_is_estimate' => true, 'stock_quantity' => 0]);
        $html = (string) $this->get("/ar/products/galaxy-{$product->id}-ar")->getContent();

        $this->assertArrayNotHasKey('offers', $this->node($this->jsonLd($html), 'Product'));
    }

    public function test_sold_out_products_are_marked_out_of_stock(): void
    {
        $product = $this->product(['stock_quantity' => 0]);
        $html = (string) $this->get("/ar/products/galaxy-{$product->id}-ar")->getContent();

        $this->assertSame('https://schema.org/OutOfStock', $this->node($this->jsonLd($html), 'Product')['offers']['availability']);
    }

    public function test_the_admin_seo_fields_win_over_the_defaults(): void
    {
        $product = $this->product();
        $product->translations()->where('locale', 'ar')->update(['seo_title' => 'عنوان مخصص', 'seo_description' => 'وصف مخصص']);
        $html = (string) $this->get("/ar/products/galaxy-{$product->id}-ar")->getContent();

        $this->assertStringContainsString('<title>عنوان مخصص - ', $html);
        $this->assertStringContainsString('<meta name="description" content="وصف مخصص" data-seo>', $html);
    }

    public function test_a_product_name_cannot_break_out_of_the_json_ld_script(): void
    {
        $product = $this->product(name: '</script><script>alert(1)</script>');
        $html = (string) $this->get("/en/products/galaxy-{$product->id}-en")->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame('</script><script>alert(1)</script> en', $this->node($this->jsonLd($html), 'Product')['name']);
    }

    public function test_a_forged_host_header_cannot_change_canonical_links(): void
    {
        $product = $this->product();

        $html = (string) $this->get("http://evil.example/ar/products/galaxy-{$product->id}-ar")->getContent();
        preg_match_all('#<[^>]+data-seo>(?:[^<]*</script>)?#', $html, $tags);
        $seoTags = implode("\n", $tags[0]);

        $this->assertGreaterThan(10, count($tags[0]));
        $this->assertStringNotContainsString('evil.example', $seoTags);
        $this->assertStringContainsString('href="https://shop.test/ar/products/', $seoTags);
    }

    public function test_the_home_page_describes_the_store_and_its_search(): void
    {
        DB::table('site_settings')->updateOrInsert(['id' => 1], [
            'site_name' => json_encode(['ar' => 'متجري', 'en' => 'Smart Phone']),
            'facebook_url' => 'https://facebook.com/myshop',
            'max_installments' => 1,
        ]);

        $html = (string) $this->get('/ar')->assertOk()->getContent();
        $schema = $this->jsonLd($html);

        $this->assertSame('متجري', $this->node($schema, 'Organization')['name']);
        $this->assertSame(['https://facebook.com/myshop'], $this->node($schema, 'Organization')['sameAs']);
        $this->assertSame('https://shop.test/ar/products?search={search_term_string}', $this->node($schema, 'WebSite')['potentialAction']['target']['urlTemplate']);
        $this->assertStringContainsString('<meta property="og:site_name" content="متجري" data-seo>', $html);
        $this->assertStringNotContainsString('<title>Smart Phone - ', $html);
    }

    public function test_pages_work_with_a_cache_that_serializes_its_values(): void
    {
        // The array store used in tests keeps objects as they are; the database
        // store used in production serializes them and refuses to restore objects.
        config(['cache.default' => 'database']);

        $this->get('/ar')->assertOk();
        $this->get('/ar')->assertOk()->assertInertia(fn (Assert $page) => $page->has('seo.site_name'));
    }

    public function test_category_pages_link_each_language_by_its_own_slug_and_number_their_pages(): void
    {
        $category = $this->category();

        foreach (range(1, 25) as $index) {
            $this->product(['category_id' => $category->id, 'sku' => "SKU-{$index}"]);
        }

        $html = (string) $this->get('/he/categories/phones-he?page=2&sort=price_asc')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://shop.test/he/categories/phones-he?page=2" data-seo>', $html);
        $this->assertStringContainsString('hreflang="ar" href="https://shop.test/ar/categories/phones-ar?page=2"', $html);
        $this->assertStringContainsString('<title>טלפונים - עמוד 2 - ', $html);
    }

    public function test_search_results_and_filtered_lists_stay_out_of_the_index(): void
    {
        $this->product();

        $this->get('/ar/products?search=galaxy')->assertInertia(fn (Assert $page) => $page->where('seo.robots', 'noindex, follow'));
        $this->get('/ar/products?category=phones-ar')->assertInertia(fn (Assert $page) => $page->where('seo.robots', 'noindex, follow'));
        $this->get('/ar/products')->assertInertia(fn (Assert $page) => $page->where('seo.robots', 'index, follow, max-image-preview:large'));
    }

    public function test_private_pages_are_never_indexed(): void
    {
        foreach (['/ar/cart', '/login', '/admin/login'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<meta name="robots" content="noindex" data-seo>', $html, $url);
            $this->assertStringNotContainsString('rel="canonical"', $html, $url);
        }
    }

    public function test_content_pages_have_metadata(): void
    {
        DB::table('pages')->insert([
            'slug' => 'returns',
            'title' => json_encode(['ar' => 'سياسة الإرجاع', 'en' => 'Returns']),
            'content' => json_encode(['en' => 'You can return any item within 14 days.']),
            'is_active' => true,
        ]);

        $this->get('/en/pages/returns')->assertInertia(fn (Assert $page) => $page
            ->where('seo.title', 'Returns')
            ->where('seo.description', 'You can return any item within 14 days.')
            ->where('seo.canonical', 'https://shop.test/en/pages/returns'));
    }

    public function test_the_sitemap_lists_every_public_page_in_every_language(): void
    {
        $category = $this->category();
        $product = $this->product(['category_id' => $category->id]);
        $hidden = $this->product(['is_active' => false, 'sku' => 'HIDDEN']);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]') ?: []);

        foreach (['ar', 'en', 'he'] as $locale) {
            $this->assertContains("https://shop.test/{$locale}", $locations);
            $this->assertContains("https://shop.test/{$locale}/products/galaxy-{$product->id}-{$locale}", $locations);
            $this->assertContains("https://shop.test/{$locale}/categories/phones-{$locale}", $locations);
            $this->assertNotContains("https://shop.test/{$locale}/products/galaxy-{$hidden->id}-{$locale}", $locations);
        }

        $this->assertStringContainsString('hreflang="x-default"', (string) $response->getContent());
    }

    public function test_robots_txt_points_to_the_sitemap_only_in_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false)->assertDontSee('Sitemap:');

        $this->app['env'] = 'production';

        $this->get('/robots.txt')
            ->assertSee('Sitemap: https://shop.test/sitemap.xml', false)
            ->assertSee('Disallow: /admin', false)
            ->assertSee('Disallow: /*/account', false);
    }
}
