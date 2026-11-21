<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductPricing;
use App\Support\StorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class OffersTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function offerForm(array $overrides = []): array
    {
        return [
            'title_ar' => 'تخفيضات الشواحن', 'title_en' => 'Charger sale', 'title_he' => 'מבצע מטענים',
            'discount_type' => 'percentage', 'discount_value' => 20, 'is_active' => true,
            ...$overrides,
        ];
    }

    private function brand(string $slug = 'baseus'): int
    {
        return DB::table('brands')->insertGetId(['slug' => $slug, 'name' => json_encode(['en' => ucfirst($slug)]), 'sort_order' => 0, 'is_active' => true]);
    }

    public function test_an_offer_on_a_main_section_discounts_the_categories_under_it_and_reaches_the_order(): void
    {
        $chargers = $this->makeCategory('Chargers');
        $carChargers = $this->makeCategory('Car chargers');
        $carChargers->update(['parent_id' => $chargers->id]);
        $covered = $this->makeProduct(['price' => '100.0000', 'category_id' => $carChargers->id]);
        $other = $this->makeProduct(['price' => '100.0000']);

        $this->actingAs($this->staff())->post('/admin/modules/offers', $this->offerForm(['category_id' => $chargers->id]))
            ->assertSessionHasNoErrors();

        // Saving the offer changes prices at once.
        $this->assertSame('80.0000', $covered->fresh()->salePrice());
        $this->assertSame('100.0000', $other->fresh()->salePrice());
        $this->assertNotNull($covered->fresh()->offer_id);

        $customer = User::factory()->create();
        Cart::query()->create(['user_id' => $customer->id])->items()->create(['product_id' => $covered->id, 'quantity' => 1]);
        $this->actingAs($customer)->post(route('checkout.store'), [
            'customer_name' => 'Sara', 'phone' => '+972 50-123-4567', 'address' => 'Haifa', 'accept_estimate' => true,
        ])->assertRedirect();

        $item = Order::query()->with('items')->sole()->items->first();
        $this->assertSame('80.0000', $item->price);
        $this->assertSame('100.0000', $item->original_price);
    }

    public function test_the_lowest_price_wins_between_own_discount_and_offers(): void
    {
        $brand = $this->brand();
        $product = $this->makeProduct(['price' => '200.0000', 'brand_id' => $brand, 'discount_percent' => 30]);

        // 30% off (140) beats 50 off (150).
        $this->actingAs($this->staff())->post('/admin/modules/offers', $this->offerForm([
            'discount_type' => 'fixed', 'discount_value' => 50, 'brand_id' => $brand,
        ]))->assertSessionHasNoErrors();
        $this->assertSame('140.0000', $product->fresh()->salePrice());
        $this->assertNull($product->fresh()->offer_id);

        // Without the own discount the offer applies.
        $product->update(['discount_percent' => 0]);
        $this->assertSame('150.0000', $product->fresh()->salePrice());

        // The product form says which offer sets its price.
        $this->actingAs($this->staff())->get("/admin/products/{$product->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('product.discount_percent', 0)
            ->where('product.running_offer.title', 'تخفيضات الشواحن')
            ->where('product.running_offer.sale_price', '150.0000'));
    }

    public function test_offers_start_and_end_on_time(): void
    {
        $product = $this->makeProduct(['price' => '100.0000']);

        $this->actingAs($this->staff())->post('/admin/modules/offers', $this->offerForm([
            'product_ids' => json_encode([$product->id]),
            'starts_at' => now()->addHour()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame('100.0000', $product->fresh()->salePrice());

        $this->travel(90)->minutes();
        $this->artisan('store:refresh-prices')->assertSuccessful();
        $this->assertSame('80.0000', $product->fresh()->salePrice());
        $this->get('/en/products?sale=1')->assertInertia(fn (Assert $page) => $page->has('products.data', 1));

        // Past its end the sale price stops counting even before the next refresh.
        $this->travel(2)->hours();
        $this->assertSame('100.0000', $product->fresh()->salePrice());
        $this->artisan('store:refresh-prices')->assertSuccessful();
        $this->assertNull($product->fresh()->sale_price);
    }

    public function test_an_offer_must_cover_something_and_a_percentage_is_capped(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/modules/offers', $this->offerForm())
            ->assertSessionHasErrors('category_id');
        $this->actingAs($admin)->post('/admin/modules/offers', $this->offerForm(['discount_value' => 95, 'brand_id' => $this->brand()]))
            ->assertSessionHasErrors('discount_value');
        // A fixed amount is not a percentage.
        $this->actingAs($admin)->post('/admin/modules/offers', $this->offerForm(['discount_type' => 'fixed', 'discount_value' => 95, 'brand_id' => $this->brand('anker')]))
            ->assertSessionHasNoErrors();
    }

    public function test_the_home_page_and_the_offer_page_show_a_running_offer(): void
    {
        $category = $this->makeCategory();
        $covered = $this->makeProduct(['category_id' => $category->id]);
        $this->makeProduct();

        $this->actingAs($this->staff())->post('/admin/modules/offers', $this->offerForm([
            'category_id' => $category->id,
            'ends_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
        ]));
        $offerId = (int) DB::table('offers')->value('id');
        auth()->logout();

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->has('offers', 1)
            ->where('offers.0.title', 'Charger sale')
            ->where('offers.0.discount_value', '20.0000'));

        $this->get("/en/offers/{$offerId}")->assertInertia(fn (Assert $page) => $page
            ->component('storefront/index')
            ->where('offerPage.id', $offerId)
            ->has('products.data', 1)
            ->where('products.data.0.id', $covered->id)
            ->where('seo.robots', 'noindex, follow'));

        $slug = $covered->translations->firstWhere('locale', 'en')->slug;
        $this->get("/en/products/{$slug}")->assertInertia(fn (Assert $page) => $page
            ->where('product.offer.id', $offerId)
            ->where('product.discount_percent', 20));

        // One saved before offers had to cover something is not shown.
        $empty = DB::table('offers')->insertGetId(['title' => json_encode(['en' => 'Empty']), 'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true]);
        StorefrontCache::flush();
        $this->get('/en')->assertInertia(fn (Assert $page) => $page->has('offers', 1));
        $this->get("/en/offers/{$empty}")->assertNotFound();

        // A hidden or deleted offer has no page and gives no discount.
        DB::table('offers')->update(['is_active' => false]);
        ProductPricing::refreshAll();
        $this->get("/en/offers/{$offerId}")->assertNotFound();
        $this->assertSame('250.0000', Product::query()->findOrFail($covered->id)->salePrice());
    }
}
