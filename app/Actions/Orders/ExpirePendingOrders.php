<?php

namespace App\Actions\Orders;

use App\Actions\Payments\ApplyGatewayStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Payments\PaymentManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancels WhatsApp orders that nobody confirmed in time, so stock taken by a
 * checkout cannot stay reserved forever. Without this, anyone could empty the
 * stock with orders they never intend to confirm.
 *
 * Only orders still pending and unpaid are touched. Each one is re-checked under
 * a row lock, so a staff member confirming it at the same moment always wins.
 */
class ExpirePendingOrders
{
    public function __construct(
        private ApplyOrderStatus $status,
        private PaymentManager $payments,
        private ApplyGatewayStatus $applyPayment,
    ) {
        //
    }

    /**
     * Orders waiting for an online payment are released much sooner than
     * WhatsApp orders. Before cancelling, the provider is asked once more, so
     * a payment that went through but whose notification was lost still
     * counts. If the provider cannot be reached, the order waits for the next run.
     *
     * @return int The number of orders cancelled.
     */
    public function expireOnline(CarbonInterface $placedBefore): int
    {
        $cancelled = 0;

        $candidates = Order::query()
            ->where('status', 'pending')
            ->whereNotIn('payment_method', Order::MANUAL_PAYMENT_METHODS)
            ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
            ->where('created_at', '<', $placedBefore)
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidates as $id) {
            $open = Payment::query()->where('order_id', $id)->whereIn('status', [Payment::CREATED, Payment::PENDING])->latest('id')->first();

            if ($open !== null && $this->payments->usable($open->gateway)) {
                try {
                    if ($this->applyPayment->execute($open, $this->payments->gateway($open->gateway)->status($open), 'expiry check') === 'paid') {
                        continue;
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }
            }

            $cancelled += DB::transaction(function () use ($id): int {
                $order = Order::query()->lockForUpdate()->whereKey($id)->first();

                if ($order === null || $order->status !== 'pending' || $order->payment_status === 'paid') {
                    return 0;
                }

                Payment::query()->where('order_id', $order->id)->whereIn('status', [Payment::CREATED, Payment::PENDING])
                    ->update(['status' => Payment::CANCELLED, 'failure_reason' => 'order expired']);

                $this->status->execute($order->id, 'cancelled', null);
                $order->update(['status' => 'cancelled']);
                OrderEvent::record($order, OrderEvent::STATUS, 'pending', 'cancelled', 'Not paid in time');

                return 1;
            });
        }

        return $cancelled;
    }

    /**
     * @return int The number of orders cancelled.
     */
    public function execute(CarbonInterface $placedBefore): int
    {
        $cancelled = 0;

        $candidates = Order::query()
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->where(fn ($query) => $query->whereNull('payment_method')->orWhereIn('payment_method', Order::MANUAL_PAYMENT_METHODS))
            ->where('created_at', '<', $placedBefore)
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidates as $id) {
            $cancelled += DB::transaction(function () use ($id, $placedBefore): int {
                $order = Order::query()->lockForUpdate()->whereKey($id)->first();

                if ($order === null
                    || $order->status !== 'pending'
                    || $order->payment_status !== 'unpaid'
                    || $order->created_at === null
                    || $order->created_at->greaterThanOrEqualTo($placedBefore)) {
                    return 0;
                }

                $this->status->execute($order->id, 'cancelled', null);
                $order->update(['status' => 'cancelled']);
                OrderEvent::record($order, OrderEvent::STATUS, 'pending', 'cancelled', 'Not confirmed in time');

                DB::table('store_admin_activities')->insert([
                    'user_id' => null,
                    'section' => 'orders',
                    'action' => 'expired',
                    'record_id' => $order->id,
                    'changes' => json_encode(['status' => 'cancelled', 'reason' => 'not confirmed in time'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return 1;
            });
        }

        return $cancelled;
    }
}
