<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A staff change to an order's status or payment status. ApplyOrderStatus does
 * the stock, coupon and points side; this records who changed what in the
 * order's history and the admin activity log, and e-mails the customer.
 *
 * Payments taken by a gateway can only be changed by the gateway's own
 * confirmation, never by hand: a "paid" typed by staff must not be able to
 * stand in for money that never arrived.
 */
class ChangeOrderStatus
{
    public function __construct(private ApplyOrderStatus $status)
    {
        //
    }

    /**
     * @return bool Whether anything changed.
     */
    public function execute(Order $order, string $status, ?string $paymentStatus, ?User $actor, bool $notifyCustomer = true): bool
    {
        $changed = DB::transaction(function () use ($order, $status, $paymentStatus, $actor): array {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $changes = [];

            if ($paymentStatus !== null && $paymentStatus !== $locked->payment_status) {
                if (! $locked->paymentIsManual()) {
                    throw ValidationException::withMessages(['payment_status' => __('This payment is confirmed by the payment provider and cannot be changed by hand.')]);
                }

                $changes['payment_status'] = [$locked->payment_status, $paymentStatus];
            }

            if ($status !== $locked->status) {
                $this->status->execute($locked->id, $status, $actor);
                $changes['status'] = [$locked->status, $status];
            }

            if ($changes === []) {
                return [];
            }

            $locked->update(array_map(fn (array $change): string => $change[1], $changes));

            foreach ($changes as $field => [$from, $to]) {
                OrderEvent::record($locked, $field === 'status' ? OrderEvent::STATUS : OrderEvent::PAYMENT, $from, $to, actor: $actor);
            }

            DB::table('store_admin_activities')->insert([
                'user_id' => $actor?->id,
                'section' => 'orders',
                'action' => 'updated',
                'record_id' => $locked->id,
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $changes;
        });

        if (isset($changed['status']) && $notifyCustomer) {
            $this->emailCustomer($order, $actor);
        }

        return $changed !== [];
    }

    private function emailCustomer(Order $order, ?User $actor): void
    {
        if (blank($order->email) || ! in_array($order->status, OrderStatusUpdated::STATUSES, true)) {
            return;
        }

        Notification::route('mail', $order->email)->notify(new OrderStatusUpdated($order));
        OrderEvent::record($order, OrderEvent::CUSTOMER_EMAILED, to: $order->status, actor: $actor);
    }
}
