<?php

namespace Tests\Feature\Storefront;

use App\Models\Brand;
use App\Models\Product;
use App\Support\ProductSearchIndex;
use App\Support\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $names, array $attributes = [], ?string $description = null): Product
    {
        $product = Product::create([
            'price' => '10.0000',
            'currency' => 'ILS',
            'stock_quantity' => 1,
            'is_active' => true,
            'is_featured' => false,
            ...$attributes,
        ]);

        foreach ($names as $locale => $name) {
            $product->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => "{$locale}-{$product->id}", 'description' => $description]);
        }

        return $product;
    }

    private function assertFinds(string $query, int $count): void
    {
        $this->get('/ar/products?search='.urlencode($query))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', $count));
    }

    public function test_arabic_pages_find_products_by_their_english_name(): void
    {
        $this->product(['ar' => 'فأرة لوجيتك', 'en' => 'Logitech Mouse']);

        $this->assertFinds('Logitech', 1);
        $this->assertFinds('logitech', 1);
        $this->assertFinds('mouse', 1);
    }

    public function test_singular_and_plural_arabic_find_the_same_products(): void
    {
        $this->product(['ar' => 'سماعة بلوتوث']);
        $this->product(['ar' => 'سماعات رأس']);

        $this->assertFinds('سماعة', 2);
        $this->assertFinds('سماعات', 2);
        $this->assertFinds('السماعات', 2);
    }

    public function test_spelling_variants_match(): void
    {
        $this->product(['ar' => 'فأرة ألعاب مؤشر']);

        $this->assertFinds('فاره العاب', 1);
        $this->assertFinds('فأرة', 1);
        $this->assertFinds('ماوس', 1);
    }

    public function test_common_synonyms_match(): void
    {
        $this->product(['ar' => 'كابل شحن']);

        $this->assertFinds('كيبل', 1);
        $this->assertFinds('cable', 1);
    }

    public function test_a_word_inside_another_word_is_not_a_match(): void
    {
        $this->product(['ar' => 'سماعة رأس لاسلكية']);
        $this->product(['ar' => 'كاميرا بحساس حركة وصفارة']);
        $this->product(['ar' => 'كابل شحن']);
        $this->product(['ar' => 'فأرة لاسلكية']);

        $this->assertFinds('كابل', 1);
        $this->assertFinds('mouse', 1);
        $this->assertFinds('فأرة', 1);
        $this->assertFinds('فارات', 1);
    }

    public function test_every_word_must_match(): void
    {
        $this->product(['ar' => 'شاحن سيارة']);
        $this->product(['ar' => 'شاحن حائط']);

        $this->assertFinds('شاحن سيارة', 1);
        $this->assertFinds('شاحن', 2);
    }

    public function test_brand_and_sku_are_searchable(): void
    {
        $brand = Brand::query()->forceCreate(['slug' => 'acme', 'name' => json_encode(['ar' => 'أكمي', 'en' => 'Acme'])]);
        $this->product(['ar' => 'منتج'], ['brand_id' => $brand->id, 'sku' => 'XY-900']);

        $this->assertFinds('Acme', 1);
        $this->assertFinds('أكمي', 1);
        $this->assertFinds('XY-900', 1);
    }

    public function test_a_name_match_comes_before_a_description_match(): void
    {
        $this->product(['ar' => 'حقيبة'], [], 'تناسب كل سماعة');
        $this->product(['ar' => 'سماعة']);

        $this->get('/ar/products?search='.urlencode('سماعة'))->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'سماعة'));
    }

    public function test_hidden_products_are_never_found(): void
    {
        $this->product(['ar' => 'سماعة'], ['is_active' => false]);

        $this->assertFinds('سماعة', 0);
    }

    public function test_symbols_alone_find_nothing_and_do_not_act_as_wildcards(): void
    {
        $this->product(['ar' => 'سماعة']);

        $this->assertFinds('%', 0);
        $this->assertFinds("' OR 1=1 --", 0);
    }

    public function test_renaming_a_product_updates_search_at_once(): void
    {
        $product = $this->product(['ar' => 'اسم قديم']);

        $product->translations()->where('locale', 'ar')->first()->update(['name' => 'سماعة جديدة']);

        $this->assertFinds('جديده', 1);
    }

    public function test_reindexing_covers_existing_products(): void
    {
        $product = $this->product(['ar' => 'سماعة']);
        Product::query()->whereKey($product->id)->toBase()->update(['search_name' => null, 'search_body' => null]);

        $this->assertFinds('سماعة', 0);
        $this->assertSame(1, ProductSearchIndex::rebuild());
        $this->assertFinds('سماعة', 1);
    }

    public function test_suggestions_return_a_short_list_for_two_letters_or_more(): void
    {
        foreach (range(1, 8) as $index) {
            $this->product(['ar' => "سماعة {$index}"]);
        }

        $this->getJson('/ar/search/suggest?q=سما')->assertOk()->assertJsonCount(6, 'products');
        $this->getJson('/ar/search/suggest?q=س')->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_text_folding_covers_arabic_and_hebrew_forms(): void
    {
        $this->assertSame('اسعار', SearchText::normalize('أَسعار'));
        $this->assertSame('שלומ', SearchText::normalize('שלום'));
        $this->assertSame('123', SearchText::normalize('١٢٣'));
    }
}
