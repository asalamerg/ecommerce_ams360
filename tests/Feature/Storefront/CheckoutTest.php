<?php

namespace Tests\Feature\Storefront;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\WhatsAppOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        $this->actingAs($this->customer);
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

        $product->translations()->create(['locale' => 'ar', 'name' => 'سماعة', 'slug' => "a-{$product->id}"]);
        $product->translations()->create(['locale' => 'en', 'name' => 'Headset', 'slug' => "e-{$product->id}"]);

        return $product;
    }

    private function inCart(Product $product, int $quantity = 2): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->customer->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function checkout(array $overrides = [])
    {
        return $this->post(route('checkout.store'), [
            'customer_name' => 'Sara',
            'phone' => '+972 50-123-4567',
            'address' => 'Ramallah',
            'notes' => 'Call first',
            'accept_estimate' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function coupon(array $attributes = []): void
    {
        DB::table('coupons')->insert([
            'code' => 'SAVE10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_placing_an_order_saves_it_and_empties_the_cart(): void
    {
        $product = $this->product();
        $this->inCart($product);

        $this->checkout()->assertRedirect();

        $order = Order::query()->with('items')->sole();

        $this->assertSame('200.0000', $order->total);
        $this->assertSame($this->customer->id, $order->user_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame('whatsapp', $order->payment_method);
        $this->assertSame(2, $order->items->first()->quantity);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_order_prices_come_from_the_catalog_not_the_request(): void
    {
        $this->inCart($this->product());

        $this->checkout(['total' => '1', 'subtotal' => '1', 'discount_total' => '199', 'status' => 'completed']);

        $order = Order::query()->sole();

        $this->assertSame('200.0000', $order->total);
        $this->assertSame('pending', $order->status);
    }

    public function test_an_order_keeps_a_snapshot_when_the_catalog_changes(): void
    {
        $product = $this->product();
        $this->inCart($product);
        $this->checkout();

        $product->update(['price' => '999.0000']);
        $product->translations()->where('locale', 'ar')->update(['name' => 'تغيّر']);

        $item = Order::query()->sole()->items()->sole();

        $this->assertSame('100.0000', $item->price);
        $this->assertSame('سماعة', $item->name['ar']);
    }

    public function test_stock_is_deducted_and_recorded(): void
    {
        $product = $this->product(['stock_quantity' => 5]);
        $this->inCart($product, 2);

        $this->checkout();

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'quantity_delta' => -2, 'stock_after' => 3]);
    }

    public function test_the_last_units_cannot_be_sold_twice(): void
    {
        $product = $this->product(['stock_quantity' => 2]);
        $this->inCart($product, 2);
        $this->checkout();

        $other = User::factory()->create();
        $this->actingAs($other);
        $cart = Cart::query()->create(['user_id' => $other->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->checkout()->assertSessionHasErrors('cart');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_submitting_twice_creates_one_order(): void
    {
        $this->inCart($this->product());

        $this->checkout();
        $this->checkout()->assertSessionHasErrors('cart');

        $this->assertSame(1, Order::query()->count());
    }

    public function test_an_empty_cart_cannot_be_ordered(): void
    {
        $this->checkout()->assertSessionHasErrors('cart');
    }

    public function test_a_hidden_product_blocks_the_order_until_it_is_removed(): void
    {
        $product = $this->product();
        $this->inCart($product);
        $product->update(['is_active' => false]);

        $this->checkout()->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_estimated_price_needs_the_customers_consent(): void
    {
        $this->inCart($this->product(['stock_quantity' => 0, 'price_is_estimate' => true]));

        $this->checkout(['accept_estimate' => false])->assertSessionHasErrors('accept_estimate');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unconfirmed_availability_does_not_touch_stock(): void
    {
        $product = $this->product(['stock_quantity' => 0, 'price_is_estimate' => true]);
        $this->inCart($product);

        $this->checkout()->assertRedirect();

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(0, Order::query()->sole()->items()->sole()->stock_deducted);
    }

    public function test_bad_details_are_refused(): void
    {
        $this->inCart($this->product());

        $this->checkout(['customer_name' => '', 'phone' => 'abc'])->assertSessionHasErrors(['customer_name', 'phone']);
    }

    public function test_a_coupon_discounts_the_order_and_counts_a_use(): void
    {
        $this->coupon();
        $this->inCart($this->product());

        $this->post(route('cart.coupon.store'), ['code' => 'save10'])->assertSessionHasNoErrors();
        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.discount', '20.0000')
            ->where('cart.total', '180.0000'));

        $this->checkout();

        $order = Order::query()->sole();

        $this->assertSame('180.0000', $order->total);
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertDatabaseHas('coupons', ['code' => 'SAVE10', 'used_count' => 1]);
    }

    public function test_expired_inactive_and_used_up_coupons_are_refused(): void
    {
        $this->coupon(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->coupon(['code' => 'DONE', 'max_uses' => 1, 'used_count' => 1]);

        foreach (['OLD', 'OFF', 'DONE', 'NOPE'] as $code) {
            $this->post(route('cart.coupon.store'), ['code' => $code])->assertSessionHasErrors('coupon');
        }
    }

    public function test_a_coupon_can_never_make_an_order_negative_or_free_beyond_its_total(): void
    {
        $this->coupon(['code' => 'HUGE', 'discount_value' => 500]);
        $this->inCart($this->product());
        $this->post(route('cart.coupon.store'), ['code' => 'HUGE']);

        $this->checkout();

        $this->assertSame('0.0000', Order::query()->sole()->total);
    }

    public function test_a_coupon_that_runs_out_before_ordering_is_reported(): void
    {
        $this->coupon(['max_uses' => 1]);
        $this->inCart($this->product());
        $this->post(route('cart.coupon.store'), ['code' => 'SAVE10']);

        DB::table('coupons')->update(['used_count' => 1]);

        $this->checkout()->assertSessionHasErrors('coupon');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_whatsapp_message_is_complete_after_the_cart_is_emptied(): void
    {
        DB::table('site_settings')->updateOrInsert(['id' => 1], ['whatsapp' => '+972 50-111-2222']);
        $this->get('/ar');
        $this->inCart($this->product());
        $this->checkout();

        $order = Order::query()->sole();
        $text = WhatsAppOrderMessage::text($order);

        foreach (['سماعة', 'x2', '200', $order->reference, 'Sara', 'Ramallah', 'Call first'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $this->assertStringStartsWith('https://wa.me/972501112222?text=', WhatsAppOrderMessage::url($order));
    }

    public function test_the_fallback_number_is_used_until_one_is_set(): void
    {
        config(['store.whatsapp' => '+972 50 999']);
        DB::table('site_settings')->where('id', 1)->update(['whatsapp' => null]);
        $this->inCart($this->product());
        $this->checkout();

        $this->assertStringStartsWith('https://wa.me/97250999?', WhatsAppOrderMessage::url(Order::query()->sole()));
    }

    public function test_the_confirmation_page_opens_for_the_owner_only(): void
    {
        $this->inCart($this->product());
        $this->checkout();
        $reference = Order::query()->sole()->reference;

        $this->get("/ar/orders/{$reference}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('storefront/order')
            ->where('order.reference', $reference));

        $this->flushSession();
        $this->actingAs(User::factory()->create())->get("/ar/orders/{$reference}")->assertNotFound();
    }

    public function test_a_guest_sees_their_own_order_only_in_the_session_that_placed_it(): void
    {
        $product = $this->product();
        $this->inCart($product);
        $this->checkout();
        $reference = Order::query()->sole()->reference;

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get("/ar/orders/{$reference}")->assertNotFound();
    }

    public function test_customers_see_their_orders_on_the_account_page(): void
    {
        $this->inCart($this->product());
        $this->checkout();

        $this->get('/ar/account')->assertInertia(fn (Assert $page) => $page->has('orders', 1));
    }

    public function test_checkout_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->checkout()->assertRedirect();
        }

        $this->checkout()->assertTooManyRequests();
    }

    public function test_cancelling_restores_stock_and_the_coupon_exactly_once(): void
    {
        $this->coupon();
        $product = $this->product(['stock_quantity' => 5]);
        $this->inCart($product, 2);
        $this->post(route('cart.coupon.store'), ['code' => 'SAVE10']);
        $this->checkout();
        $order = Order::query()->sole();

        $staff = User::factory()->create();
        $staff->forceFill(['role' => UserRole::OrderManager])->save();
        $payload = ['status' => 'cancelled', 'payment_status' => 'unpaid'];

        $this->actingAs($staff)->put("/admin/orders/{$order->id}", $payload)->assertRedirect();
        $this->actingAs($staff)->put("/admin/orders/{$order->id}", $payload)->assertRedirect();

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('coupons', ['code' => 'SAVE10', 'used_count' => 0]);
    }

    public function test_a_cancelled_order_cannot_be_reopened(): void
    {
        $this->inCart($this->product());
        $this->checkout();
        $order = Order::query()->sole();

        $staff = User::factory()->create();
        $staff->forceFill(['role' => UserRole::OrderManager])->save();

        $this->actingAs($staff)->put("/admin/orders/{$order->id}", ['status' => 'cancelled', 'payment_status' => 'unpaid']);
        $this->actingAs($staff)->put("/admin/orders/{$order->id}", ['status' => 'pending', 'payment_status' => 'unpaid'])->assertStatus(422);

        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
