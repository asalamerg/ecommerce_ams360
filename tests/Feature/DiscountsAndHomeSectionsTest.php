<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\StorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class DiscountsAndHomeSectionsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function productForm(array $overrides = []): array
    {
        return array_replace_recursive([
            'category_id' => null,
            'price' => '200',
            'currency' => 'ILS',
            'is_active' => true,
            'is_featured' => false,
            'price_is_estimate' => false,
            'translations' => ['ar' => ['name' => 'سماعة']],
        ], $overrides);
    }

    public function test_the_sale_price_rounds_down_to_the_agora_and_ignores_ended_discounts(): void
    {
        $product = $this->makeProduct(['price' => '99.9900', 'discount_percent' => 15]);
        $this->assertTrue($product->hasActiveDiscount());
        // 99.99 × 0.85 = 84.9915
        $this->assertSame('84.9900', $product->salePrice());

        $product->update(['discount_ends_at' => now()->subMinute()]);
        $this->assertFalse($product->fresh()->hasActiveDiscount());
        $this->assertSame('99.9900', $product->fresh()->salePrice());

        $product->update(['discount_ends_at' => now()->addDay()]);
        $this->assertSame('84.9900', $product->fresh()->salePrice());
    }

    public function test_the_card_cart_and_order_all_charge_the_discounted_price(): void
    {
        $customer = User::factory()->create();
        $product = $this->makeProduct(['price' => '200.0000', 'discount_percent' => 25]);

        $this->get('/en/products')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.price', '150.0000')
            ->where('products.data.0.original_price', '200.0000')
            ->where('products.data.0.discount_percent', 25));

        Cart::query()->create(['user_id' => $customer->id])->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($customer)->get('/en/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.subtotal', '300.0000')
            ->where('cart.items.0.line_total', '300.0000'));

        $this->actingAs($customer)->post(route('checkout.store'), [
            'customer_name' => 'Sara',
            'phone' => '+972 50-123-4567',
            'address' => 'Haifa',
            'accept_estimate' => true,
        ])->assertRedirect();

        $order = Order::query()->with('items')->sole();
        $this->assertSame('300.0000', $order->subtotal);
        $this->assertSame('150.0000', $order->items->first()->price);
        $this->assertSame('200.0000', $order->items->first()->original_price);
    }

    public function test_the_admin_sets_a_discount_with_an_end_date_and_it_is_capped(): void
    {
        $admin = $this->staff(UserRole::Administrator);

        $this->actingAs($admin)->post('/admin/products', $this->productForm(['discount_percent' => 95]))
            ->assertSessionHasErrors('discount_percent');

        $this->actingAs($admin)->post('/admin/products', $this->productForm([
            'discount_percent' => 20,
            'discount_ends_at' => now()->addDays(3)->toDateString(),
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $this->assertSame(20, $product->discount_percent);
        // The end date is a whole day: the offer runs to its last second.
        $this->assertSame(now()->addDays(3)->endOfDay()->toDateTimeString(), $product->discount_ends_at->toDateTimeString());

        // An ended discount opens in the form as none, so a save clears it.
        $product->forceFill(['discount_ends_at' => now()->subDay()])->save();
        $this->actingAs($admin)->get("/admin/products/{$product->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->where('product.discount_percent', 0)
            ->where('product.discount_ends_at', null));
    }

    public function test_a_bulk_discount_applies_to_the_selected_products_and_can_be_removed(): void
    {
        $admin = $this->staff(UserRole::Administrator);
        $first = $this->makeProduct();
        $second = $this->makeProduct();
        $untouched = $this->makeProduct();

        $this->actingAs($admin)->post('/admin/products/bulk', ['ids' => [$first->id, $second->id], 'action' => 'discount', 'discount_percent' => 0])
            ->assertSessionHasErrors('discount_percent');

        $this->actingAs($admin)->post('/admin/products/bulk', ['ids' => [$first->id, $second->id], 'action' => 'discount', 'discount_percent' => 30])
            ->assertSessionHasNoErrors();

        $this->assertSame(30, $first->fresh()->discount_percent);
        $this->assertSame(30, $second->fresh()->discount_percent);
        $this->assertSame(0, $untouched->fresh()->discount_percent);

        $this->actingAs($admin)->get('/admin/products?status=on_sale')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('counts.on_sale', 2));

        $this->actingAs($admin)->post('/admin/products/bulk', ['ids' => [$first->id], 'action' => 'remove_discount']);
        $this->assertSame(0, $first->fresh()->discount_percent);
    }

    public function test_saving_goes_back_to_the_list_it_came_from_with_a_message_and_the_row_marked(): void
    {
        $admin = $this->staff(UserRole::Administrator);

        $response = $this->actingAs($admin)->post('/admin/products', $this->productForm(['back' => '/admin/products?page=2&q=phone']));
        $product = Product::query()->sole();

        $response->assertRedirect('/admin/products?page=2&q=phone')
            ->assertSessionHas('highlight', $product->id)
            // The admin works in Arabic by default.
            ->assertSessionHas('success', 'تمت إضافة المنتج «سماعة» بنجاح.');

        // Only an address of the list itself is followed.
        $this->actingAs($admin)->put("/admin/products/{$product->id}", $this->productForm(['back' => 'https://evil.example/admin/products']))
            ->assertRedirect('/admin/products');
        $this->actingAs($admin)->put("/admin/products/{$product->id}", $this->productForm(['back' => '//evil.example']))
            ->assertRedirect('/admin/products');

        // "Save and keep editing" stays on the form.
        $this->actingAs($admin)->put("/admin/products/{$product->id}", $this->productForm(['after' => 'stay']))
            ->assertRedirect("/admin/products/{$product->id}/edit?back=%2Fadmin%2Fproducts");
    }

    public function test_save_and_add_another_opens_an_empty_form_keeping_the_category_and_brand(): void
    {
        $admin = $this->staff(UserRole::Administrator);
        $category = $this->makeCategory();

        $this->actingAs($admin)->post('/admin/products', $this->productForm(['category_id' => $category->id, 'after' => 'new']))
            ->assertRedirect("/admin/products/create?category_id={$category->id}&back=%2Fadmin%2Fproducts");

        $this->actingAs($admin)->get("/admin/products/create?category_id={$category->id}")->assertInertia(fn (Assert $page) => $page
            ->where('defaults.category_id', $category->id)
            ->where('product', null));

        $this->actingAs($admin)->post('/admin/categories', [
            'is_active' => true,
            'position' => 1,
            'translations' => ['ar' => ['name' => 'شواحن'], 'en' => ['name' => 'Chargers'], 'he' => ['name' => 'מטענים']],
        ])->assertRedirect('/admin/categories')->assertSessionHas('success');
    }

    public function test_the_home_page_shows_the_sections_the_admin_picked_with_offers_and_featured_products(): void
    {
        $phones = $this->makeCategory('Phones');
        $cables = $this->makeCategory('Cables');

        foreach (range(1, 4) as $index) {
            $this->makeProduct(['category_id' => $phones->id]);
        }

        $cable = $this->makeProduct(['category_id' => $cables->id, 'discount_percent' => 10, 'is_featured' => true]);

        // Nothing picked: the largest category with enough products.
        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->has('rails', 1)
            ->where('rails.0.slug', "cat-{$phones->id}-en")
            ->has('deals', 1)
            ->where('deals.0.id', $cable->id)
            ->has('featured', 1));

        // Picked: only it, even with a single product.
        $cables->update(['show_on_home' => true]);
        StorefrontCache::flush();

        $this->get('/en')->assertInertia(fn (Assert $page) => $page
            ->has('rails', 1)
            ->where('rails.0.slug', "cat-{$cables->id}-en")
            ->has('rails.0.products', 1));
    }

    public function test_the_listing_filters_offers_and_sorts_by_the_price_paid(): void
    {
        $plain = $this->makeProduct(['price' => '100.0000']);
        $discounted = $this->makeProduct(['price' => '150.0000', 'discount_percent' => 50]);

        $this->get('/en/products?sale=1')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $discounted->id)
            ->where('filters.sale', true));

        // 75 after the discount comes before 100.
        $this->get('/en/products?sort=price_asc')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.id', $discounted->id)
            ->where('products.data.1.id', $plain->id));

        $this->get('/en/products?price_max=80')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $discounted->id));
    }

    public function test_the_footer_shows_the_store_phone_and_whatsapp_until_settings_have_their_own(): void
    {
        Cache::flush();

        $this->get('/ar')->assertInertia(fn (Assert $page) => $page
            ->where('footer.phone', '+972 50-000-0000')
            ->where('footer.phone_link', '+972500000000')
            ->where('footer.whatsapp', 'https://wa.me/972500000000'));
    }

    public function test_the_interface_text_is_sent_once_per_language(): void
    {
        $first = $this->get('/ar', ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()]);
        $first->assertOk();
        $key = array_key_first($first->json('onceProps'));

        $this->assertStringStartsWith('ui.ar.', $key);
        $this->assertNotEmpty($first->json('props.ui.ar'));

        // The browser says it has it: it is not sent again.
        $again = $this->get('/ar/categories', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
            'X-Inertia-Except-Once-Props' => $key,
        ]);
        $this->assertNull($again->json('props.ui.ar'));

        // Another language is a new key, so its text comes along.
        $english = $this->get('/en/categories', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->inertiaVersion(),
            'X-Inertia-Except-Once-Props' => $key,
        ]);
        $this->assertNotEmpty($english->json('props.ui.en'));
    }

    private function inertiaVersion(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }
}
