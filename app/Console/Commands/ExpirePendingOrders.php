<?php

namespace App\Console\Commands;

use App\Actions\Orders\ExpirePendingOrders as ExpireOrders;
use App\Models\Order;
use App\Services\StaffAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ExpirePendingOrders extends Command
{
    /** Staff are reminded this many hours before an unconfirmed order is cancelled. */
    private const int REMIND_HOURS_BEFORE = 12;

    protected $signature = 'store:expire-pending-orders';

    protected $description = 'Cancel unconfirmed orders older than store.pending_order_hours and return their stock';

    public function handle(ExpireOrders $expire, StaffAlerts $alerts): int
    {
        $minutes = (int) config('payments.pending_minutes');

        if ($minutes > 0) {
            $this->info('Cancelled '.$expire->expireOnline(now()->subMinutes($minutes)).' unpaid online orders.');
        }

        $hours = (int) config('store.pending_order_hours');

        if ($hours <= 0) {
            $this->info('Automatic expiry is turned off.');

            return self::SUCCESS;
        }

        $this->info('Cancelled '.$expire->execute(now()->subHours($hours)).' unconfirmed orders.');
        $this->remind($alerts, $hours);

        return self::SUCCESS;
    }

    /**
     * One reminder per order, sent when it enters its last hours.
     */
    private function remind(StaffAlerts $alerts, int $hours): void
    {
        $remindAt = max(1, $hours - min(self::REMIND_HOURS_BEFORE, intdiv($hours, 2)));

        Order::query()
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->where(fn ($query) => $query->whereNull('payment_method')->orWhereIn('payment_method', Order::MANUAL_PAYMENT_METHODS))
            ->where('created_at', '<', now()->subHours($remindAt))
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('id')
            ->each(function (Order $order) use ($alerts, $hours): void {
                if (Cache::add("order-expiry-reminder:{$order->id}", true, now()->addDays(3))) {
                    $left = max(1, (int) ceil(now()->diffInMinutes($order->created_at->addHours($hours)) / 60));
                    $alerts->orderExpiringSoon($order, $left);
                }
            });
    }
}
