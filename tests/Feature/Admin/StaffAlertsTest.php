<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\StaffAlert;
use App\Notifications\StaffAlertMessage;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class StaffAlertsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function placeOrder(int $stock = 20, int $quantity = 1): Order
    {
        $customer = User::factory()->create();
        $product = $this->makeProduct(['stock_quantity' => $stock]);
        Cart::query()->create(['user_id' => $customer->id])->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($customer)->post(route('checkout.store'), [
            'customer_name' => 'Sara',
            'phone' => '+972 50-123-4567',
            'address' => 'Ramallah',
            'accept_estimate' => true,
        ])->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_a_new_order_notifies_staff_who_handle_orders_only(): void
    {
        Notification::fake();
        $owner = $this->staff(UserRole::Owner);
        $orders = $this->staff(UserRole::OrderManager);
        $products = $this->staff(UserRole::ProductManager);
        $disabled = $this->staff(UserRole::OrderManager);
        $disabled->forceFill(['is_active' => false])->save();

        $order = $this->placeOrder();

        Notification::assertSentTo([$owner, $orders], StaffAlert::class, fn (StaffAlert $alert): bool => $alert->kind === 'order'
            && $alert->params['reference'] === $order->reference
            && str_ends_with($alert->url, "/admin/orders/{$order->id}"));
        Notification::assertNotSentTo([$products, $disabled], StaffAlert::class);
    }

    public function test_e_mail_and_telegram_get_the_alert_without_customer_contact_details(): void
    {
        Notification::fake();
        DB::table('site_settings')->updateOrInsert(['id' => 1], ['notification_email' => 'orders@shop.test']);
        config(['services.telegram.bot_token' => 'secret-token', 'services.telegram.chat_id' => '-100123']);
        $this->staff();

        $order = $this->placeOrder();

        Notification::assertSentOnDemand(StaffAlertMessage::class, function (StaffAlertMessage $message, array $channels, AnonymousNotifiable $notifiable) use ($order): bool {
            $text = $message->toTelegram($notifiable);

            return $notifiable->routes['mail'] === 'orders@shop.test'
                && $notifiable->routes['telegram'] === '-100123'
                && $channels === ['mail', TelegramChannel::class]
                && str_contains($text, $order->reference)
                && ! str_contains($text, '123-4567')
                && ! str_contains($text, 'Ramallah');
        });
    }

    public function test_nothing_is_sent_outside_the_panel_when_no_channel_is_set(): void
    {
        Notification::fake();
        $this->staff();

        $this->placeOrder();

        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertCount(1);
    }

    public function test_the_telegram_channel_posts_plain_text_to_the_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['services.telegram.bot_token' => 'secret-token', 'services.telegram.chat_id' => '-100123']);

        Notification::route('telegram', '-100123')->notifyNow(new StaffAlertMessage('New order :reference: :total ₪, :count items', [
            'reference' => 'ABCD1234', 'total' => '250', 'count' => 2,
        ], 'https://shop.test/admin/orders/1'));

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.telegram.org/botsecret-token/sendMessage'
            && $request['chat_id'] === '-100123'
            && str_contains($request['text'], 'ABCD1234')
            && ! isset($request['parse_mode']));
    }

    public function test_low_stock_is_announced_once_when_an_order_crosses_the_line(): void
    {
        Notification::fake();
        $inventory = $this->staff(UserRole::InventoryManager);

        $this->placeOrder(stock: 7, quantity: 3);

        Notification::assertSentTo($inventory, StaffAlert::class, fn (StaffAlert $alert): bool => $alert->kind === 'low_stock'
            && $alert->params['count'] === 4);
    }

    public function test_opening_a_notification_marks_it_read_and_stays_inside_the_admin(): void
    {
        $owner = $this->staff();
        $owner->notify(new StaffAlert('order', 'New order :reference: :total ₪, :count items', ['reference' => 'X1', 'total' => '1', 'count' => 1], url('/admin/orders/5')));
        $owner->notify(new StaffAlert('order', 'Evil', [], 'https://evil.example/admin/steal'));
        $good = $owner->notifications()->get()->firstWhere('data.title', '!=', 'Evil');
        $evil = $owner->notifications()->get()->firstWhere('data.title', 'Evil');

        $this->actingAs($owner)->get("/admin/notifications/{$good->id}")->assertRedirect('/admin/orders/5');
        $this->assertNotNull($good->fresh()->read_at);

        $this->actingAs($owner)->get("/admin/notifications/{$evil->id}")->assertRedirect('/admin/steal');

        $this->actingAs($this->staff())->get("/admin/notifications/{$good->id}")->assertNotFound();
    }

    public function test_the_admin_shell_lists_notifications_and_can_mark_all_read(): void
    {
        $owner = $this->staff();
        $owner->notify(new StaffAlert('order', 'New order :reference: :total ₪, :count items', ['reference' => 'AB12', 'total' => '99', 'count' => 2], url('/admin')));

        $this->actingAs($owner)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('admin.notifications.unread', 1)
            ->where('admin.notifications.items.0.title', 'طلب جديد AB12: 99 ₪، 2 قطعة'));

        $this->actingAs($owner)->post('/admin/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    public function test_staff_are_reminded_once_before_an_order_expires(): void
    {
        Notification::fake();
        config(['store.pending_order_hours' => 48]);
        $owner = $this->staff();
        $order = $this->placeOrder();
        Notification::fake();

        $order->forceFill(['created_at' => now()->subHours(40)])->save();

        $this->artisan('store:expire-pending-orders')->assertSuccessful();
        $this->artisan('store:expire-pending-orders')->assertSuccessful();

        Notification::assertSentToTimes($owner, StaffAlert::class, 1);
        Notification::assertSentTo($owner, StaffAlert::class, fn (StaffAlert $alert): bool => $alert->kind === 'order_expiring' && $alert->params['hours'] === 8);
    }

    public function test_production_dashboard_warns_about_stopped_workers_and_missing_settings(): void
    {
        $owner = $this->staff();
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);

        $this->actingAs($owner)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->has('systemWarnings', fn (Assert $warnings) => $warnings->each(fn (Assert $warning) => $warning->has('key')->has('message'))));

        $keys = collect(SystemHealth::warnings())->pluck('key');
        $this->assertTrue($keys->contains('scheduler'));
        $this->assertTrue($keys->contains('mail'));

        Cache::forever(SystemHealth::SCHEDULER_HEARTBEAT, time());
        Cache::forever(SystemHealth::QUEUE_HEARTBEAT, time());
        $keys = collect(SystemHealth::warnings())->pluck('key');
        $this->assertFalse($keys->contains('scheduler'));
        $this->assertFalse($keys->contains('queue'));

        $this->app['env'] = 'testing';
        $this->assertSame([], SystemHealth::warnings());
    }
}
