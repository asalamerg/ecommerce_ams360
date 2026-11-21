<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Notifications\OrderStatusUpdated;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        $order = Order::query()->create([
            'reference' => Str::upper(Str::random(8)),
            'customer_name' => 'Sara Haddad',
            'phone' => '050-123-4567',
            'email' => 'sara@example.com',
            'shipping_address' => ['address' => 'Haifa'],
            'item_count' => 1,
            'subtotal' => '100',
            'discount_total' => '0',
            'total' => '100',
            'status' => 'pending',
            'payment_method' => 'whatsapp',
            'payment_status' => 'unpaid',
            'locale' => 'ar',
            ...$attributes,
        ]);
        $order->items()->create(['product_id' => null, 'name' => ['ar' => 'سماعة', 'en' => 'Headset'], 'price' => '100', 'quantity' => 1]);
        OrderEvent::record($order, OrderEvent::CREATED, to: 'pending');

        return $order;
    }

    public function test_only_order_staff_open_the_orders_pages(): void
    {
        $order = $this->order();

        foreach ([UserRole::Owner, UserRole::Administrator, UserRole::OrderManager] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/orders')->assertOk();
        }

        foreach ([UserRole::ProductManager, UserRole::InventoryManager, UserRole::SupportAgent, UserRole::Customer] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/orders')->assertForbidden();
            $this->actingAs($this->staff($role))->get("/admin/orders/{$order->id}")->assertForbidden();
            $this->actingAs($this->staff($role))->put("/admin/orders/{$order->id}", ['status' => 'cancelled'])->assertForbidden();
        }

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_list_filters_by_status_and_finds_phone_numbers_by_digits(): void
    {
        $sara = $this->order();
        $this->order(['customer_name' => 'Omar', 'phone' => '052-999-0000', 'status' => 'shipped']);
        $staff = $this->staff(UserRole::OrderManager);

        $this->actingAs($staff)->get('/admin/orders?status=pending')->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/index')
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $sara->id)
            ->where('counts.pending', 1)
            ->where('counts.shipped', 1));

        $this->actingAs($staff)->get('/admin/orders?q=0501234567')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.id', $sara->id));

        $this->actingAs($staff)->get('/admin/orders?q=Omar')->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)->where('orders.data.0.customer', 'Omar'));

        $this->actingAs($staff)->get('/admin/orders?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_a_status_change_is_recorded_and_the_customer_is_e_mailed_in_their_language(): void
    {
        Notification::fake();
        $order = $this->order();
        $staff = $this->staff(UserRole::OrderManager);

        $this->actingAs($staff)->put("/admin/orders/{$order->id}", ['status' => 'processing', 'payment_status' => 'paid'])->assertRedirect();

        $order->refresh();
        $this->assertSame(['processing', 'paid'], [$order->status, $order->payment_status]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'status', 'from' => 'pending', 'to' => 'processing', 'user_id' => $staff->id]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'payment_status', 'from' => 'unpaid', 'to' => 'paid']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'customer_emailed', 'to' => 'processing']);
        $this->assertDatabaseHas('store_admin_activities', ['section' => 'orders', 'record_id' => $order->id, 'user_id' => $staff->id]);

        Notification::assertSentOnDemand(OrderStatusUpdated::class, function (OrderStatusUpdated $notification, array $channels, AnonymousNotifiable $notifiable) use ($order): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'sara@example.com'
                && $notification->locale === 'ar'
                && str_contains($mail->subject, $order->reference)
                && str_contains((string) $mail->actionUrl, "/ar/track?reference={$order->reference}");
        });
    }

    public function test_staff_can_skip_the_customer_e_mail(): void
    {
        Notification::fake();
        $order = $this->order();

        $this->actingAs($this->staff())->put("/admin/orders/{$order->id}", ['status' => 'shipped', 'notify_customer' => false])->assertRedirect();

        Notification::assertNothingSent();
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_a_gateway_payment_cannot_be_marked_paid_by_hand(): void
    {
        $order = $this->order(['payment_method' => 'card', 'payment_status' => 'pending']);

        $this->actingAs($this->staff())->put("/admin/orders/{$order->id}", ['status' => 'pending', 'payment_status' => 'paid'])
            ->assertSessionHasErrors('payment_status');

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_bulk_status_changes_skip_orders_that_cannot_take_them(): void
    {
        Notification::fake();
        $first = $this->order();
        $second = $this->order();
        $cancelled = $this->order(['status' => 'cancelled']);

        $this->actingAs($this->staff())->post('/admin/orders/bulk-status', [
            'ids' => [$first->id, $second->id, $cancelled->id],
            'status' => 'processing',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('processing', $first->fresh()->status);
        $this->assertSame('processing', $second->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_notes_stay_internal_and_whatsapp_opens_a_message_to_the_customer(): void
    {
        $order = $this->order();
        $staff = $this->staff(UserRole::OrderManager);

        $this->actingAs($staff)->post("/admin/orders/{$order->id}/notes", ['note' => 'Customer asked for evening delivery'])->assertRedirect();

        $response = $this->actingAs($staff)->get("/admin/orders/{$order->id}/whatsapp");
        $this->assertStringStartsWith('https://wa.me/972501234567?text=', $response->headers->get('Location'));
        $this->assertStringContainsString(rawurlencode($order->reference), $response->headers->get('Location'));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'customer_whatsapp', 'user_id' => $staff->id]);

        $this->actingAs($staff)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/show')
            ->where('order.events.1.type', 'note')
            ->where('order.events.1.note', 'Customer asked for evening delivery')
            ->where('whatsappReady', true));

        $this->actingAs($staff)->get("/admin/orders/{$order->id}/invoice")->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/invoice')
            ->where('order.reference', $order->reference)
            ->where('order.items.0.name', 'سماعة'));
    }

    public function test_old_module_links_lead_to_the_new_pages(): void
    {
        $order = $this->order();

        $this->actingAs($this->staff())->get('/admin/modules/orders')->assertRedirect('/admin/orders');
        $this->actingAs($this->staff())->get("/admin/modules/orders/{$order->id}/edit")->assertRedirect("/admin/orders/{$order->id}");
    }

    public function test_a_guest_opens_their_order_with_its_number_and_phone_only(): void
    {
        $order = $this->order();

        $this->get("/en/orders/{$order->reference}")->assertNotFound();

        $this->post('/en/track', ['reference' => $order->reference, 'phone' => '052-000-0000'])
            ->assertSessionHasErrors('reference');

        $this->post('/en/track', ['reference' => strtolower($order->reference), 'phone' => '+972 50 123 4567'])
            ->assertRedirect("/en/orders/{$order->reference}");

        $this->get("/en/orders/{$order->reference}")->assertInertia(fn (Assert $page) => $page
            ->component('storefront/order')
            ->where('order.timeline.0.label', 'Order placed')
            ->missing('order.events'));
    }

    public function test_tracking_stops_after_repeated_wrong_guesses(): void
    {
        $order = $this->order();

        // The per-minute route limit is tested elsewhere; here only wrong guesses count.
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->post('/en/track', ['reference' => 'WRONG'.$attempt, 'phone' => '0501234567']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/en/track', ['reference' => $order->reference, 'phone' => '0501234567'])
            ->assertSessionHasErrors(['reference' => __('ui.order_updates.track_locked', ['minutes' => 60])]);
    }

    public function test_internal_notes_never_reach_the_customer_page(): void
    {
        $order = $this->order();
        OrderEvent::record($order, OrderEvent::NOTE, note: 'Customer is difficult');

        $this->withSession(['placed_orders' => [$order->reference]])
            ->get("/en/orders/{$order->reference}")
            ->assertDontSee('Customer is difficult');
    }

    public function test_a_guest_can_leave_an_e_mail_for_order_updates(): void
    {
        $product = $this->makeProduct();
        $token = (string) Str::uuid();
        Cart::query()->create(['token' => $token])->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->withCookie(CartManager::COOKIE, $token)->post(route('checkout.store'), [
            'customer_name' => 'Guest', 'phone' => '0501112222', 'email' => 'guest@example.com', 'accept_estimate' => true,
        ])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame('guest@example.com', $order->email);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'created']);
    }
}
