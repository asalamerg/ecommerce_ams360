<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Notifications\StaffAlertMessage;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells the right staff that something needs them: a new order, stock running
 * low, an order about to expire. Each alert goes to the panel's notification
 * list of every active staff member whose role covers it, and once to the
 * store's alert e-mail and Telegram chat when those are set.
 *
 * An alert must never break what triggered it, so failures are only logged.
 */
class StaffAlerts
{
    /**
     * @param  'orders'|'inventory'|'support'|'admin'  $permission
     * @param  array<string, string|int>  $params
     */
    public function send(string $kind, string $permission, string $title, array $params, string $url, bool $external = true): void
    {
        try {
            $staff = User::query()
                ->where('is_active', true)
                ->where('role', '!=', 'customer')
                ->get()
                ->filter(fn (User $user): bool => match ($permission) {
                    'orders' => $user->role->canManageOrders(),
                    'inventory' => $user->role->canManageInventory(),
                    'support' => $user->role->canManageSupport(),
                    'admin' => $user->role->canManageAdministration(),
                });

            Notification::send($staff, new StaffAlert($kind, $title, $params, $url));

            if ($external) {
                $this->sendOutside($title, $params, $url);
            }
        } catch (Throwable $exception) {
            Log::error('Staff alert failed', ['kind' => $kind, 'error' => $exception->getMessage()]);
            report($exception);
        }
    }

    public function orderPlaced(Order $order): void
    {
        $this->send('order', 'orders', 'New order :reference: :total ₪, :count items', [
            'reference' => $order->reference,
            'total' => self::money((string) $order->total),
            'count' => $order->item_count,
        ], self::orderUrl($order->id));

        $this->lowStockAfter($order);
    }

    public function orderExpiringSoon(Order $order, int $hoursLeft): void
    {
        $this->send('order_expiring', 'orders', 'Order :reference is cancelled automatically in :hours hours unless confirmed', [
            'reference' => $order->reference,
            'hours' => $hoursLeft,
        ], self::orderUrl($order->id));
    }

    /**
     * Once per product, when an order takes its stock to the low-stock line or
     * below; not again for every later order.
     */
    private function lowStockAfter(Order $order): void
    {
        $threshold = AdminNavigation::LOW_STOCK_THRESHOLD;

        $order->loadMissing('items');

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            if ($item->stock_deducted <= 0) {
                continue;
            }

            $product = Product::query()->with('translations')->find($item->product_id);

            if ($product === null) {
                continue;
            }

            $before = $product->stock_quantity + $item->stock_deducted;

            if ($product->stock_quantity <= $threshold && $before > $threshold) {
                $this->send('low_stock', 'inventory', $product->stock_quantity > 0 ? 'Low stock: :name has :count left' : 'Out of stock: :name', [
                    'name' => $product->translations->firstWhere('locale', config('app.locale'))->name
                        ?? $product->translations->first()->name ?? "#{$product->id}",
                    'count' => $product->stock_quantity,
                ], url('/admin/inventory'));
            }
        }
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private function sendOutside(string $title, array $params, string $url): void
    {
        $email = DB::table('site_settings')->where('id', 1)->value('notification_email');
        $chat = config('services.telegram.chat_id');
        $telegram = filled(config('services.telegram.bot_token')) && filled($chat);

        if (blank($email) && ! $telegram) {
            return;
        }

        $route = Notification::route('mail', filled($email) ? $email : null);

        if ($telegram) {
            $route->route('telegram', $chat);
        }

        $route->notify(new StaffAlertMessage($title, $params, $url));
    }

    public static function orderUrl(int $orderId): string
    {
        return url("/admin/orders/{$orderId}");
    }

    public static function money(string $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ','), '0'), '.');
    }
}
