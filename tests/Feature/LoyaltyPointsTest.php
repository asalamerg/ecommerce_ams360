<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartManager;
use App\Services\PointsLedger;
use App\Support\WhatsAppOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function enable(array $settings = []): void
    {
        DB::table('loyalty_settings')->where('id', 1)->update([
            'is_enabled' => true,
            'spend_per_point' => 100,
            'point_value' => 1,
            'min_redeem_points' => 0,
            'max_redeem_percent' => 50,
            ...$settings,
        ]);
    }

    private function product(string $price = '1000.0000'): Product
    {
        $product = Product::create([
            'price' => $price,
            'currency' => 'ILS',
            'stock_quantity' => 20,
            'is_active' => true,
            'is_featured' => false,
        ]);
        $product->translations()->create(['locale' => 'ar', 'name' => 'جهاز', 'slug' => "device-{$product->id}"]);

        return $product;
    }

    private function giveBalance(int $points): void
    {
        app(PointsLedger::class)->record($this->customer->id, $points, PointsLedger::ADJUSTED, note: 'test');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function placeOrder(Product $product, int $quantity = 1, array $overrides = [])
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->customer->id]);
        $cart->items()->delete();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $this->actingAs($this->customer)->post(route('checkout.store'), [
            'customer_name' => 'Sara',
            'phone' => '0599'.random_int(100000, 999999),
            ...$overrides,
        ]);
    }

    private function moveOrderTo(Order $order, string $status): void
    {
        $staff = User::factory()->create();
        $staff->forceFill(['role' => UserRole::OrderManager])->save();

        $this->actingAs($staff)
            ->put("/admin/orders/{$order->id}", ['status' => $status, 'payment_status' => 'paid'])
            ->assertRedirect();
    }

    private function balance(): int
    {
        return (int) DB::table('users')->where('id', $this->customer->id)->value('points_balance');
    }

    public function test_the_programme_is_off_until_the_admin_turns_it_on(): void
    {
        $this->placeOrder($this->product());
        $this->moveOrderTo(Order::query()->sole(), 'completed');

        $this->assertSame(0, $this->balance());
        $this->actingAs($this->customer)->get('/ar/cart')->assertInertia(fn (Assert $page) => $page->where('loyalty', null));
    }

    public function test_a_completed_order_earns_points_once_by_the_admin_rule(): void
    {
        $this->enable();
        $this->placeOrder($this->product('1000.0000'));
        $order = Order::query()->sole();

        $this->moveOrderTo($order, 'processing');
        $this->assertSame(0, $this->balance());

        $this->moveOrderTo($order, 'completed');
        $this->moveOrderTo($order, 'shipped');
        $this->moveOrderTo($order, 'completed');

        // 1000 ₪ at 100 ₪ per point is 10 points, credited only once.
        $this->assertSame(10, $this->balance());
        $this->assertSame(10, $order->fresh()->points_earned);
        $this->assertDatabaseHas('point_transactions', ['order_id' => $order->id, 'type' => 'earned', 'points' => 10, 'balance_after' => 10]);
        $this->assertSame(1, DB::table('point_transactions')->where('type', 'earned')->count());
    }

    public function test_fractions_of_the_spending_step_earn_nothing_extra(): void
    {
        $this->enable(['spend_per_point' => 100]);
        $this->placeOrder($this->product('199.0000'));
        $this->moveOrderTo(Order::query()->sole(), 'completed');

        $this->assertSame(1, $this->balance());
    }

    public function test_points_pay_part_of_an_order_and_the_rest_earns_points(): void
    {
        $this->enable(['spend_per_point' => 10, 'point_value' => 1, 'max_redeem_percent' => 50]);
        $this->giveBalance(50);

        $this->placeOrder($this->product('100.0000'), 1, ['use_points' => 20])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(20, $order->points_used);
        $this->assertSame('20.0000', $order->points_discount);
        $this->assertSame('80.0000', $order->total);
        $this->assertSame(30, $this->balance());
        $this->assertDatabaseHas('point_transactions', ['order_id' => $order->id, 'type' => 'redeemed', 'points' => -20, 'balance_after' => 30]);

        // Earned on what was paid: 80 ₪ at 10 ₪ per point.
        $this->moveOrderTo($order, 'completed');
        $this->assertSame(38, $this->balance());
    }

    public function test_point_value_below_one_shekel_works(): void
    {
        // 100 points = 1 ₪.
        $this->enable(['point_value' => '0.01', 'max_redeem_percent' => 100]);
        $this->giveBalance(500);

        $this->placeOrder($this->product('100.0000'), 1, ['use_points' => 500])->assertSessionHasNoErrors();

        $this->assertSame('95.0000', Order::query()->sole()->total);
    }

    public function test_redeeming_is_checked_against_the_balance_and_the_admin_limits(): void
    {
        $this->enable(['min_redeem_points' => 10, 'max_redeem_percent' => 25]);
        $this->giveBalance(40);
        $product = $this->product('100.0000');

        $this->placeOrder($product, 1, ['use_points' => 41])->assertSessionHasErrors('use_points');
        $this->placeOrder($product, 1, ['use_points' => 5])->assertSessionHasErrors('use_points');
        // 25% of 100 ₪ is 25 points at 1 ₪ each.
        $this->placeOrder($product, 1, ['use_points' => 26])->assertSessionHasErrors('use_points');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(40, $this->balance());

        $this->placeOrder($product, 1, ['use_points' => 25])->assertSessionHasNoErrors();
        $this->assertSame(15, $this->balance());
    }

    public function test_points_after_a_coupon_are_limited_by_what_is_left_to_pay(): void
    {
        $this->enable(['max_redeem_percent' => 100]);
        $this->giveBalance(500);
        DB::table('coupons')->insert(['code' => 'HALF', 'discount_type' => 'percentage', 'discount_value' => 50, 'is_active' => true]);
        $this->actingAs($this->customer)->post(route('cart.coupon.store'), ['code' => 'HALF']);

        $product = $this->product('100.0000');

        $this->placeOrder($product, 1, ['use_points' => 51])->assertSessionHasErrors('use_points');
        $this->placeOrder($product, 1, ['use_points' => 50])->assertSessionHasNoErrors();

        $this->assertSame('0.0000', Order::query()->sole()->total);
    }

    public function test_guests_cannot_use_points(): void
    {
        $this->enable();
        $product = $this->product('100.0000');

        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page->where('loyalty.balance', null));

        $this->post(route('cart.items.store'), ['product_id' => $product->id]);
        $token = (string) Cart::query()->whereNull('user_id')->value('token');

        $this->withCookie(CartManager::COOKIE, $token)
            ->post(route('checkout.store'), ['customer_name' => 'Guest', 'phone' => '0599111222', 'use_points' => 30])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Order::query()->sole()->points_used);
    }

    public function test_cancelling_returns_used_points_and_takes_back_earned_ones_once(): void
    {
        $this->enable(['spend_per_point' => 10]);
        $this->giveBalance(30);
        $this->placeOrder($this->product('100.0000'), 1, ['use_points' => 20]);
        $order = Order::query()->sole();

        $this->moveOrderTo($order, 'completed');
        $this->assertSame(10 + 8, $this->balance());

        $this->moveOrderTo($order, 'cancelled');
        $this->moveOrderTo($order, 'cancelled');

        // Back to where the customer started.
        $this->assertSame(30, $this->balance());
        $this->assertDatabaseHas('point_transactions', ['order_id' => $order->id, 'type' => 'refunded', 'points' => 20]);
        $this->assertDatabaseHas('point_transactions', ['order_id' => $order->id, 'type' => 'reversed', 'points' => -8]);
    }

    public function test_an_expired_order_gives_its_points_back(): void
    {
        $this->enable();
        $this->giveBalance(30);
        $this->placeOrder($this->product('100.0000'), 1, ['use_points' => 30]);

        $this->travel(49)->hours();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();

        $this->assertSame(30, $this->balance());
    }

    public function test_taking_back_points_never_drops_the_balance_below_zero(): void
    {
        $this->enable(['spend_per_point' => 10]);
        $this->placeOrder($this->product('100.0000'));
        $order = Order::query()->sole();
        $this->moveOrderTo($order, 'completed');

        // The customer spent the 10 points elsewhere before the order was cancelled.
        app(PointsLedger::class)->record($this->customer->id, -6, PointsLedger::ADJUSTED, note: 'spent');
        $this->moveOrderTo($order, 'cancelled');

        $this->assertSame(0, $this->balance());
        $this->assertDatabaseHas('point_transactions', ['order_id' => $order->id, 'type' => 'reversed', 'points' => -4, 'balance_after' => 0]);
    }

    public function test_the_admin_sets_the_rules_and_only_administrators_may(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrator])->save();

        $this->actingAs($admin)->put('/admin/modules/loyalty/1', [
            'is_enabled' => true,
            'spend_per_point' => 50,
            'point_value' => '0.5',
            'min_redeem_points' => 20,
            'max_redeem_percent' => 30,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('loyalty_settings', ['id' => 1, 'is_enabled' => true, 'min_redeem_points' => 20, 'max_redeem_percent' => 30]);

        $this->actingAs($admin)->put('/admin/modules/loyalty/1', [
            'is_enabled' => true, 'spend_per_point' => 0, 'point_value' => 1, 'min_redeem_points' => 0, 'max_redeem_percent' => 101,
        ])->assertSessionHasErrors(['spend_per_point', 'max_redeem_percent']);

        foreach ([UserRole::ProductManager, UserRole::OrderManager, UserRole::Customer] as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();
            $this->actingAs($user)->put('/admin/modules/loyalty/1', ['is_enabled' => true])->assertForbidden();
        }
    }

    public function test_the_admin_can_add_or_deduct_points_with_a_reason(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Administrator])->save();
        $user = [
            'name' => $this->customer->name,
            'email' => $this->customer->email,
            'phone' => '',
            'preferred_locale' => 'ar',
            'role' => 'customer',
            'is_active' => true,
        ];

        $this->actingAs($admin)->put("/admin/modules/users/{$this->customer->id}", [...$user, 'points_adjustment' => 100])
            ->assertSessionHasErrors('points_note');

        $this->actingAs($admin)->put("/admin/modules/users/{$this->customer->id}", [...$user, 'points_adjustment' => 100, 'points_note' => 'Welcome gift'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/admin/modules/users/{$this->customer->id}", [...$user, 'points_adjustment' => -30, 'points_note' => 'Correction'])
            ->assertSessionHasNoErrors();

        $this->assertSame(70, $this->balance());
        $this->assertDatabaseHas('point_transactions', ['user_id' => $this->customer->id, 'type' => 'adjusted', 'points' => 100, 'actor_id' => $admin->id, 'note' => 'Welcome gift']);
        $this->assertDatabaseHas('store_admin_activities', ['section' => 'users', 'action' => 'points_adjusted', 'record_id' => $this->customer->id]);

        // Saving the profile without an adjustment changes nothing.
        $this->actingAs($admin)->put("/admin/modules/users/{$this->customer->id}", $user)->assertSessionHasNoErrors();
        $this->assertSame(70, $this->balance());

        $this->actingAs($admin)->get("/admin/modules/users/{$this->customer->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('values.points_balance', 70));
    }

    public function test_the_customer_sees_balance_history_and_what_an_order_will_earn(): void
    {
        $this->enable();
        $this->giveBalance(25);

        $this->actingAs($this->customer)->get('/ar/cart')->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.balance', 25)
            ->where('loyalty.max_redeem_percent', 50));

        $this->placeOrder($this->product('1000.0000'), 1, ['use_points' => 25]);
        $order = Order::query()->sole();

        $this->actingAs($this->customer)->get("/ar/orders/{$order->reference}")->assertInertia(fn (Assert $page) => $page
            ->where('order.points_used', 25)
            ->where('order.points_to_earn', 9));

        $this->actingAs($this->customer)->get('/ar/account')->assertInertia(fn (Assert $page) => $page
            ->where('points.balance', 0)
            ->has('points.history', 2)
            ->where('points.history.0.points', -25)
            ->where('points.history.0.reference', $order->reference));

        $this->assertStringContainsString(rawurlencode('خصم النقاط (25 نقطة)'), WhatsAppOrderMessage::url($order));
    }

    public function test_points_cannot_be_set_through_the_profile_or_checkout_fields(): void
    {
        $this->enable();

        $this->actingAs($this->customer)->put('/ar/account', [
            'name' => 'Sara', 'phone' => '', 'preferred_locale' => 'ar', 'points_balance' => 99999,
        ]);
        $this->placeOrder($this->product('100.0000'), 1, ['points_discount' => 100, 'points_earned' => 500]);

        $this->assertSame(0, $this->balance());
        $this->assertSame('100.0000', Order::query()->sole()->total);
        $this->assertSame(0, Order::query()->sole()->points_earned);
    }
}
