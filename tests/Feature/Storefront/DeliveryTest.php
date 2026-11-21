<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Support\WhatsAppOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        $product = $this->makeProduct(['price' => '100.0000', 'stock_quantity' => 10]);
        Cart::query()->create(['user_id' => $this->customer->id])->items()->create(['product_id' => $product->id, 'quantity' => 2]);
    }

    private function zone(string $fee, ?string $freeOver = null, bool $active = true): int
    {
        return (int) DB::table('shipping_zones')->insertGetId([
            'name' => json_encode(['ar' => 'الشمال', 'en' => 'North', 'he' => 'צפון']),
            'fee' => $fee, 'free_over' => $freeOver, 'is_active' => $active, 'sort_order' => 0,
        ]);
    }

    private function branch(bool $pickup = true): int
    {
        return (int) DB::table('branches')->insertGetId([
            'name' => json_encode(['ar' => 'فرع حيفا', 'en' => 'Haifa branch', 'he' => 'סניף חיפה']),
            'address' => json_encode(['en' => 'Herzl 1']), 'is_active' => true, 'allows_pickup' => $pickup,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function checkout(array $details)
    {
        return $this->actingAs($this->customer)->post(route('checkout.store'), [
            'customer_name' => 'Sara', 'phone' => '0501234567', 'accept_estimate' => true, ...$details,
        ]);
    }

    public function test_the_zone_fee_is_added_by_the_server_whatever_the_browser_sends(): void
    {
        $zone = $this->zone('35');

        $this->checkout(['shipping_zone_id' => $zone, 'address' => 'Nazareth', 'shipping_fee' => 0, 'total' => 1])->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('35.0000', (string) $order->shipping_fee);
        $this->assertSame('235.0000', (string) $order->total);
        $this->assertSame(['delivery', $zone, 'الشمال'], [$order->delivery_method, $order->shipping_zone_id, $order->delivery_label]);
        $this->assertStringContainsString('35 ₪', WhatsAppOrderMessage::text($order->load('items')));
    }

    public function test_delivery_is_free_from_the_zone_threshold(): void
    {
        $this->checkout(['shipping_zone_id' => $this->zone('35', '200'), 'address' => 'Akko'])->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame('0.0000', (string) $order->shipping_fee);
        $this->assertSame('200.0000', (string) $order->total);
    }

    public function test_a_zone_and_an_address_are_required_once_zones_exist(): void
    {
        $zone = $this->zone('20');
        $inactive = $this->zone('0', active: false);

        $this->checkout(['address' => 'Haifa'])->assertSessionHasErrors('shipping_zone_id');
        $this->checkout(['shipping_zone_id' => $inactive, 'address' => 'Haifa'])->assertSessionHasErrors('shipping_zone_id');
        $this->checkout(['shipping_zone_id' => $zone])->assertSessionHasErrors('address');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_without_zones_checkout_works_as_before(): void
    {
        $this->checkout(['address' => 'Haifa'])->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame(['delivery', null, '0.0000', '200.0000'], [$order->delivery_method, $order->delivery_label, (string) $order->shipping_fee, (string) $order->total]);
    }

    public function test_pickup_needs_a_branch_that_allows_it_and_costs_nothing(): void
    {
        $this->zone('35');
        $branch = $this->branch();
        $closed = $this->branch(pickup: false);

        $this->checkout(['delivery_method' => 'pickup'])->assertSessionHasErrors('branch_id');
        $this->checkout(['delivery_method' => 'pickup', 'branch_id' => $closed])->assertSessionHasErrors('branch_id');
        $this->checkout(['delivery_method' => 'pickup', 'branch_id' => $branch])->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame(['pickup', $branch, '0.0000', '200.0000'], [$order->delivery_method, $order->branch_id, (string) $order->shipping_fee, (string) $order->total]);
    }

    public function test_the_cart_lists_zones_and_pickup_branches_in_the_visitors_language(): void
    {
        $this->zone('35', '300');
        $this->branch();
        $this->branch(pickup: false);

        $this->actingAs($this->customer)->get('/en/cart')->assertInertia(fn (Assert $page) => $page
            ->where('delivery.zones.0.name', 'North')
            ->where('delivery.zones.0.free_over', '300.0000')
            ->has('delivery.branches', 1)
            ->where('delivery.branches.0.name', 'Haifa branch')
            ->where('onlinePayment', false));
    }

    public function test_points_are_not_earned_on_the_delivery_fee(): void
    {
        DB::table('loyalty_settings')->updateOrInsert(['id' => 1], ['is_enabled' => true, 'spend_per_point' => 10, 'point_value' => 1, 'min_redeem_points' => 0, 'max_redeem_percent' => 50]);
        $this->checkout(['shipping_zone_id' => $this->zone('50'), 'address' => 'Haifa'])->assertRedirect();
        $order = Order::query()->firstOrFail();

        $this->actingAs($this->staff())->put("/admin/orders/{$order->id}", ['status' => 'completed', 'notify_customer' => false])->assertRedirect();

        $this->assertSame(20, $order->fresh()->points_earned);
    }

    public function test_online_payment_is_refused_when_no_gateway_is_set(): void
    {
        $this->checkout(['address' => 'Haifa', 'payment_method' => 'online'])->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('orders', 0);
    }
}
