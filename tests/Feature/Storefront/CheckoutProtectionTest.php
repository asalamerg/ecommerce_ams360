<?php

namespace Tests\Feature\Storefront;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Rules\TurnstileToken;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CheckoutProtectionTest extends TestCase
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
            'accept_estimate' => true,
            ...$overrides,
        ]);
    }

    private function coupon(): void
    {
        DB::table('coupons')->insert([
            'code' => 'SAVE10',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => true,
        ]);
    }

    public function test_unconfirmed_orders_expire_and_give_back_stock_and_coupon(): void
    {
        $this->coupon();
        $product = $this->product(['stock_quantity' => 5]);
        $this->inCart($product, 2);
        $this->post(route('cart.coupon.store'), ['code' => 'SAVE10']);
        $this->checkout()->assertRedirect();
        $order = Order::query()->sole();

        $this->assertSame(3, $product->fresh()->stock_quantity);

        $this->travel(47)->hours();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();
        $this->assertSame('pending', $order->fresh()->status);

        $this->travel(2)->hours();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('coupons', ['code' => 'SAVE10', 'used_count' => 0]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'direction' => 'increase', 'user_id' => null]);
        $this->assertDatabaseHas('store_admin_activities', ['section' => 'orders', 'action' => 'expired', 'record_id' => $order->id]);
        $this->assertSame(1, DB::table('stock_movements')->where('direction', 'increase')->count());
    }

    public function test_orders_confirmed_or_paid_by_staff_never_expire(): void
    {
        $product = $this->product(['stock_quantity' => 10]);

        $this->inCart($product, 1);
        $this->checkout();
        $this->inCart($product, 1);
        $this->checkout(['phone' => '0599000001']);
        [$confirmed, $paid] = Order::query()->orderBy('id')->get()->all();

        $confirmed->update(['status' => 'processing']);
        $paid->update(['payment_status' => 'paid']);

        $this->travel(5)->days();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();

        $this->assertSame('processing', $confirmed->fresh()->status);
        $this->assertSame('pending', $paid->fresh()->status);
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_expiry_can_be_turned_off(): void
    {
        config(['store.pending_order_hours' => 0]);
        $this->inCart($this->product());
        $this->checkout();

        $this->travel(30)->days();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();

        $this->assertSame('pending', Order::query()->sole()->status);
    }

    public function test_the_expiry_is_scheduled_hourly(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('store:expire-pending-orders')->assertSuccessful();
    }

    public function test_the_confirmation_page_tells_the_customer_about_the_deadline(): void
    {
        $this->inCart($this->product());
        $this->checkout();
        $order = Order::query()->sole();

        $this->get("/ar/orders/{$order->reference}")
            ->assertInertia(fn (Assert $page) => $page->where('order.expires_in_hours', 48));
    }

    public function test_a_filled_honeypot_is_refused(): void
    {
        $product = $this->product();
        $this->inCart($product);

        $this->checkout(['website' => 'https://spam.example'])->assertSessionHasErrors('website');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_one_phone_number_cannot_place_unlimited_orders(): void
    {
        config(['store.orders_per_phone_per_day' => 2]);
        $product = $this->product(['stock_quantity' => 20]);

        foreach (range(1, 2) as $attempt) {
            $this->inCart($product, 1);
            $this->checkout()->assertSessionHasNoErrors();
        }

        // Same digits written differently are still the same number.
        $this->inCart($product, 1);
        $this->checkout(['phone' => '+97250 123 4567'])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('orders', 2);

        $this->checkout(['phone' => '0599000002'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_wrong_coupon_codes_lock_coupon_entry(): void
    {
        config(['store.coupon_failures_per_hour' => 3]);
        $this->coupon();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('cart.coupon.store'), ['code' => "GUESS{$attempt}"])
                ->assertSessionHasErrors(['coupon' => __('ui.checkout.coupon_invalid')]);
        }

        // Even the right code is refused once locked, so guessing gains nothing.
        $this->post(route('cart.coupon.store'), ['code' => 'SAVE10'])->assertSessionHasErrors('coupon');
        $this->assertNull(session(CartManager::COUPON_SESSION));

        $this->travel(61)->minutes();
        $this->post(route('cart.coupon.store'), ['code' => 'SAVE10'])->assertSessionHasNoErrors();
    }

    public function test_correct_coupon_codes_do_not_count_toward_the_lock(): void
    {
        config(['store.coupon_failures_per_hour' => 2]);
        $this->coupon();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('cart.coupon.store'), ['code' => 'SAVE10'])->assertSessionHasNoErrors();
        }
    }

    public function test_turnstile_is_off_until_both_keys_are_set(): void
    {
        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', null));

        $this->inCart($this->product());
        $this->checkout()->assertSessionHasNoErrors();
    }

    public function test_turnstile_tokens_are_checked_with_cloudflare_when_enabled(): void
    {
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
        Http::fake([TurnstileToken::VERIFY_URL => Http::sequence()
            ->push(['success' => false])
            ->push(['success' => true]),
        ]);
        $this->inCart($this->product());

        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', 'site-key'));

        $this->checkout()->assertSessionHasErrors('turnstile_token');
        $this->checkout(['turnstile_token' => 'bad'])->assertSessionHasErrors('turnstile_token');
        $this->assertDatabaseCount('orders', 0);

        $this->checkout(['turnstile_token' => 'good'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('orders', 1);

        Http::assertSent(fn (ClientRequest $request) => $request['secret'] === 'secret-key' && $request['response'] === 'good');
    }

    public function test_turnstile_fails_closed_when_cloudflare_is_unreachable(): void
    {
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
        Http::fake([TurnstileToken::VERIFY_URL => Http::failedConnection()]);
        $this->inCart($this->product());

        $this->checkout(['turnstile_token' => 'token'])->assertSessionHasErrors('turnstile_token');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_staff_can_still_cancel_by_hand_after_the_actor_change(): void
    {
        $product = $this->product(['stock_quantity' => 5]);
        $this->inCart($product, 2);
        $this->checkout();
        $order = Order::query()->sole();

        $staff = User::factory()->create();
        $staff->forceFill(['role' => UserRole::OrderManager])->save();

        $this->actingAs($staff)->put("/admin/orders/{$order->id}", ['status' => 'cancelled', 'payment_status' => 'unpaid'])->assertRedirect();

        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'direction' => 'increase', 'user_id' => $staff->id]);
    }
}
