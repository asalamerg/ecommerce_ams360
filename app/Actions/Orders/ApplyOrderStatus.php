<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PointsLedger;
use App\Support\Loyalty;
use Illuminate\Support\Facades\DB;

/**
 * Guards a staff status change. Cancelling gives back the stock and the coupon use
 * exactly once, and a cancelled order cannot be reopened because that stock may
 * already have been sold again. The actor is null when the system cancels an
 * order on its own, see ExpirePendingOrders.
 *
 * Loyalty points: a customer earns them when the order is first marked
 * completed, because a WhatsApp order is only paid and delivered by then.
 * Cancelling returns the points the order used and takes back any it earned.
 */
class ApplyOrderStatus
{
    public function __construct(private PointsLedger $points)
    {
        //
    }

    public function execute(int $orderId, string $status, ?User $actor): void
    {
        DB::transaction(function () use ($orderId, $status, $actor): void {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->status === 'cancelled' && $status !== 'cancelled') {
                abort(422, __('A cancelled order cannot be reopened.'));
            }

            if ($status === 'completed') {
                $this->awardPoints($order, $actor);
            }

            if ($status !== 'cancelled' || $order->status === 'cancelled') {
                return;
            }

            $this->returnPoints($order, $actor);

            foreach ($order->items as $item) {
                $this->restoreStock($item, $order, $actor);
            }

            if ($order->coupon_code !== null) {
                DB::table('coupons')
                    ->where('code', $order->coupon_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }
        });
    }

    /**
     * Once per order: points_earned stays set, so completing it again after a
     * status change adds nothing.
     */
    private function awardPoints(Order $order, ?User $actor): void
    {
        if ($order->user_id === null || $order->points_earned > 0) {
            return;
        }

        // Delivery fees earn nothing; only what was paid for goods.
        $earned = Loyalty::pointsFor(bcsub((string) $order->total, (string) $order->shipping_fee, 4));

        if ($earned > 0) {
            $this->points->record($order->user_id, $earned, PointsLedger::EARNED, $order, $actor);
            $order->update(['points_earned' => $earned]);
        }
    }

    private function returnPoints(Order $order, ?User $actor): void
    {
        if ($order->user_id === null) {
            return;
        }

        if ($order->points_used > 0) {
            $this->points->record($order->user_id, $order->points_used, PointsLedger::REFUNDED, $order, $actor);
        }

        if ($order->points_earned > 0) {
            $this->points->record($order->user_id, -$order->points_earned, PointsLedger::REVERSED, $order, $actor);
        }
    }

    private function restoreStock(OrderItem $item, Order $order, ?User $actor): void
    {
        $product = Product::query()->withTrashed()->lockForUpdate()->find($item->product_id);

        if ($product === null || $item->stock_deducted === 0) {
            return;
        }

        $before = $product->stock_quantity;
        $after = $before + $item->stock_deducted;

        $product->update(['stock_quantity' => $after]);

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $actor?->id,
            'direction' => 'increase',
            'quantity_delta' => $item->stock_deducted,
            'stock_before' => $before,
            'stock_after' => $after,
            'reason' => "Order {$order->reference} cancelled",
            'created_at' => now(),
        ]);

        $item->update(['stock_deducted' => 0]);
    }
}
