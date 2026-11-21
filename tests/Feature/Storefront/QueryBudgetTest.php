<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

/**
 * Each public page reads the database a fixed number of times, however many
 * products it shows: a query per product (N+1) would make the store slow as
 * the catalog grows. The budgets leave a little room above today's counts.
 */
class QueryBudgetTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function catalog(int $products): Product
    {
        $category = $this->makeCategory();
        $brand = DB::table('brands')->insertGetId(['slug' => 'brand-'.$products, 'name' => json_encode(['en' => 'Baseus']), 'sort_order' => 0, 'is_active' => true]);
        $product = null;

        foreach (range(1, $products) as $index) {
            $product = $this->makeProduct(['category_id' => $category->id, 'brand_id' => $brand, 'discount_percent' => $index % 3 === 0 ? 10 : 0], "Item {$index}");
            $product->colors()->create(['hex' => '#000000', 'name' => ['ar' => 'أسود'], 'position' => 0]);
        }

        return $product;
    }

    /**
     * @return int Queries the request ran.
     */
    private function queriesFor(string $url): int
    {
        // The second visit is what customers mostly get: caches are warm.
        $this->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_listing_and_product_pages_do_not_grow_with_the_catalog(): void
    {
        $this->catalog(5);
        $small = ['/en' => $this->queriesFor('/en'), '/en/products' => $this->queriesFor('/en/products')];

        $last = $this->catalog(30);
        $slug = $last->translations->firstWhere('locale', 'en')->slug;

        $this->assertSame($small['/en'], $this->queriesFor('/en'), 'The home page queries grow with products.');
        $this->assertSame($small['/en/products'], $this->queriesFor('/en/products'), 'The listing queries grow with products.');
        $this->assertLessThanOrEqual(25, $this->queriesFor("/en/products/{$slug}"));
        $this->assertLessThanOrEqual(15, $this->queriesFor('/en/contact'));
    }
}
