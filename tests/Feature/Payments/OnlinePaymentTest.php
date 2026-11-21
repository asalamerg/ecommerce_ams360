<?php

namespace Tests\Feature\Payments;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Payments\Gateways\FakeGateway;
use App\Payments\GatewayStatus;
use App\Payments\HmacSignature;
use App\Payments\PaymentManager;
use App\Payments\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.default' => 'fake']);
        $this->customer = User::factory()->create();
        $this->product = $this->makeProduct(['price' => '120.0000', 'stock_quantity' => 10]);
    }

    /**
     * Places an online order and returns it with its open payment.
     *
     * @return array{0: Order, 1: Payment, 2: string}
     */
    private function checkout(int $quantity = 2): array
    {
        Cart::query()->firstOrCreate(['user_id' => $this->customer->id])->items()->create(['product_id' => $this->product->id, 'quantity' => $quantity]);

        $response = $this->actingAs($this->customer)->post(route('checkout.store'), [
            'customer_name' => 'Sara', 'phone' => '0501234567', 'address' => 'Haifa',
            'accept_estimate' => true, 'payment_method' => 'online',
        ]);

        $order = Order::query()->latest('id')->firstOrFail();
        $payment = $order->payments()->latest('id')->firstOrFail();
        $response->assertRedirect();

        return [$order, $payment, (string) $response->headers->get('Location')];
    }

    private function webhook(string $body, ?string $signature = null)
    {
        return $this->call('POST', '/payments/webhook/fake', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $signature ?? HmacSignature::sign($body, (string) config('payments.gateways.fake.webhook_secret')),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function notice(Payment $payment, string $status, ?string $amount = null, string $event = 'evt_1'): string
    {
        return (string) json_encode(['id' => $event, 'reference' => $payment->provider_reference, 'status' => $status, 'amount' => $amount ?? (string) $payment->amount, 'currency' => 'ILS']);
    }

    public function test_checkout_opens_a_payment_for_the_saved_total_and_sends_the_customer_to_the_provider(): void
    {
        [$order, $payment, $location] = $this->checkout();

        $this->assertSame(['pending', 'pending', 'fake'], [$order->status, $order->payment_status, $order->payment_method]);
        $this->assertSame(Payment::PENDING, $payment->status);
        $this->assertSame('240.0000', (string) $payment->amount);
        $this->assertSame('ILS', $payment->currency);
        $this->assertStringContainsString('/payments/fake/'.$payment->provider_reference, $location);
        $this->assertSame(8, $this->product->fresh()->stock_quantity, 'Stock is held while the payment is open.');
    }

    public function test_a_signed_notification_confirmed_by_the_provider_marks_the_order_paid_once(): void
    {
        Notification::fake();
        $owner = $this->staff(UserRole::Owner);
        [$order, $payment] = $this->checkout();
        app(FakeGateway::class)->decide($payment->provider_reference, GatewayStatus::PAID);

        $this->webhook($this->notice($payment, 'paid'))->assertOk()->assertSeeText('paid');
        $this->webhook($this->notice($payment, 'paid'))->assertOk()->assertSeeText('duplicate');
        $this->webhook($this->notice($payment, 'paid', event: 'evt_2'))->assertOk()->assertSeeText('already paid');

        $order->refresh();
        $this->assertSame(['processing', 'paid'], [$order->status, $order->payment_status]);
        $this->assertSame($payment->provider_reference, $order->payment_reference);
        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'payment', 'to' => 'paid']);
        Notification::assertSentTo($owner, StaffAlert::class, fn (StaffAlert $alert): bool => $alert->kind === 'payment');
    }

    public function test_forged_or_stale_notifications_change_nothing(): void
    {
        [$order, $payment] = $this->checkout();
        $body = $this->notice($payment, 'paid');

        $this->webhook($body, 't='.time().',v1='.str_repeat('0', 64))->assertStatus(400);
        $this->webhook($body, HmacSignature::sign($body, 'a-guessed-secret'))->assertStatus(400);
        $this->webhook($body, HmacSignature::sign($body, (string) config('payments.gateways.fake.webhook_secret'), time() - 3600))->assertStatus(400);
        $this->webhook($body, '')->assertStatus(400);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_a_validly_signed_paid_notice_is_not_enough_when_the_provider_api_disagrees(): void
    {
        [$order, $payment] = $this->checkout();

        // The provider's status API still says "pending".
        $this->webhook($this->notice($payment, 'paid'))->assertOk()->assertSeeText('pending');

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_a_payment_for_a_different_amount_goes_to_review_and_is_not_marked_paid(): void
    {
        Notification::fake();
        $owner = $this->staff(UserRole::Owner);
        [$order, $payment] = $this->checkout();
        $state = Cache::get("fake-gateway:{$payment->provider_reference}");
        Cache::put("fake-gateway:{$payment->provider_reference}", [...$state, 'status' => 'paid', 'amount' => '1.00']);

        $this->webhook($this->notice($payment, 'paid', '1.00'))->assertOk()->assertSeeText('review');

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(Payment::REVIEW, $payment->fresh()->status);
        Notification::assertSentTo($owner, StaffAlert::class, fn (StaffAlert $alert): bool => $alert->kind === 'payment_review');
    }

    public function test_the_return_page_needs_the_secret_token_and_ignores_what_the_url_claims(): void
    {
        [$order, $payment] = $this->checkout();

        $this->get("/payments/return/{$payment->id}/wrong-token?status=paid")->assertNotFound();

        $return = Cache::get("fake-gateway:{$payment->provider_reference}")['return_url'];
        $this->get($return.'?status=paid&amount=0')->assertRedirect("/{$order->locale}/orders/{$order->reference}");
        $this->assertSame('pending', $order->fresh()->payment_status);

        app(FakeGateway::class)->decide($payment->provider_reference, GatewayStatus::PAID);
        $this->get($return)->assertRedirect();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_the_fake_provider_page_approves_and_declines_through_the_real_webhook(): void
    {
        [$order, $payment, $location] = $this->checkout();
        $this->get($location)->assertOk();

        $decide = url()->signedRoute('payments.fake.decide', ['reference' => $payment->provider_reference]);
        $this->post($decide, ['decision' => 'failed'])->assertRedirect();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payment_webhook_events', ['payment_id' => $payment->id, 'result' => 'failed']);

        // Paying again opens a new attempt; the failed one stays as it was.
        $this->actingAs($this->customer)->post("/en/orders/{$order->reference}/pay")->assertRedirect();
        $retry = $order->payments()->latest('id')->first();
        $this->assertNotSame($payment->id, $retry->id);

        $this->post(url()->signedRoute('payments.fake.decide', ['reference' => $retry->provider_reference]), ['decision' => 'paid'])->assertRedirect();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(Payment::FAILED, $payment->fresh()->status);

        $this->post($decide, ['decision' => 'paid'])->assertRedirect();
        $this->assertSame(Payment::REVIEW, $payment->fresh()->status, 'A second payment for a paid order needs a refund.');
    }

    public function test_fake_pages_need_a_valid_signature(): void
    {
        [, $payment] = $this->checkout();

        $this->get("/payments/fake/{$payment->provider_reference}")->assertForbidden();
        $this->post("/payments/fake/{$payment->provider_reference}", ['decision' => 'paid'])->assertForbidden();
    }

    public function test_the_test_gateway_cannot_be_used_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertFalse(app(PaymentManager::class)->enabled());
        $this->assertFalse(app(PaymentManager::class)->usable('fake'));
        $this->webhook('{}')->assertNotFound();

        $this->app['env'] = 'testing';
    }

    public function test_provider_notifications_and_csp_reports_skip_csrf_but_forms_do_not(): void
    {
        // Outside tests Laravel checks CSRF tokens; "testing" turns the check off.
        $this->app['env'] = 'local';

        // Reaches the signature check (400), not the CSRF check (419).
        $this->webhook('{}', 'bad')->assertStatus(400);
        $this->postJson('/csp-report', ['csp-report' => ['violated-directive' => 'script-src']])->assertSuccessful();
        $this->post(route('checkout.store'), ['customer_name' => 'x'])->assertStatus(419);

        $this->app['env'] = 'testing';
    }

    public function test_someone_else_cannot_start_a_payment_for_an_order(): void
    {
        [$order] = $this->checkout();
        // A different browser: it never placed the order.
        $this->flushSession();

        $this->actingAs(User::factory()->create())->post("/en/orders/{$order->reference}/pay")->assertNotFound();
    }

    public function test_unpaid_online_orders_are_released_unless_the_provider_says_paid(): void
    {
        [$unpaid] = $this->checkout(1);
        $unpaid->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $paidOrder = null;
        [$paidOrder, $paidPayment] = $this->checkout(1);
        $paidOrder->forceFill(['created_at' => now()->subMinutes(45)])->save();
        app(FakeGateway::class)->decide($paidPayment->provider_reference, GatewayStatus::PAID);

        $this->artisan('store:expire-pending-orders')->assertSuccessful();

        $this->assertSame('cancelled', $unpaid->fresh()->status);
        $this->assertSame(Payment::CANCELLED, $unpaid->payments()->first()->status);
        $this->assertSame(['processing', 'paid'], [$paidOrder->fresh()->status, $paidOrder->fresh()->payment_status]);
        $this->assertSame(9, $this->product->fresh()->stock_quantity, 'Only the expired order gave its stock back.');
    }

    public function test_a_payment_that_arrives_after_the_order_expired_needs_review(): void
    {
        [$order, $payment] = $this->checkout();
        $order->forceFill(['created_at' => now()->subHour()])->save();
        $this->artisan('store:expire-pending-orders');
        $this->assertSame('cancelled', $order->fresh()->status);

        // The cancelled attempt is closed, so a late "paid" for it is ignored...
        app(FakeGateway::class)->decide($payment->provider_reference, GatewayStatus::PAID);
        $this->webhook($this->notice($payment, 'paid'))->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_owners_refund_through_the_provider_after_confirming_their_password(): void
    {
        [$order, $payment] = $this->checkout();
        app(FakeGateway::class)->decide($payment->provider_reference, GatewayStatus::PAID);
        $this->webhook($this->notice($payment, 'paid'));

        $this->actingAs($this->staff(UserRole::OrderManager))->post("/admin/orders/{$order->id}/refund")->assertForbidden();
        $owner = $this->staff(UserRole::Owner);
        $this->flushSession();
        $this->actingAsWithoutConfirmation($owner)->post("/admin/orders/{$order->id}/refund")->assertRedirect(route('password.confirm'));
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->actingAs($owner)->post("/admin/orders/{$order->id}/refund")->assertRedirect()->assertSessionHas('success');

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(Payment::REFUNDED, $payment->fresh()->status);
    }

    public function test_a_provider_page_on_an_unexpected_site_is_refused(): void
    {
        $this->app->bind(FakeGateway::class, fn () => new class extends FakeGateway
        {
            public function start(Payment $payment, Order $order, string $returnUrl, string $webhookUrl): PaymentSession
            {
                return new PaymentSession('https://evil.example/pay', 'fake_'.Str::random(10));
            }
        });

        [$order] = $this->checkout();

        $this->assertSame(Payment::FAILED, $order->payments()->first()->status);
    }

    public function test_notifications_only_from_the_providers_addresses_when_it_publishes_them(): void
    {
        config(['payments.gateways.fake.webhook_ips' => ['203.0.113.5']]);
        [, $payment] = $this->checkout();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->webhook($this->notice($payment, 'paid'))->assertForbidden();
    }
}
