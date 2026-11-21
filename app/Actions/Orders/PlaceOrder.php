<?php

namespace App\Actions\Orders;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Services\CartManager;
use App\Services\PointsLedger;
use App\Support\CouponDiscount;
use App\Support\Delivery;
use App\Support\Loyalty;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns the current cart into an order. Everything is decided here from the locked
 * catalog rows: nothing the browser sends can set a price, a discount or a total.
 * Because the cart row is locked and emptied in the same transaction, submitting
 * twice cannot create two orders.
 */
class PlaceOrder
{
    public function __construct(private CartManager $carts, private PointsLedger $points, private PaymentManager $payments)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function execute(array $details, ?User $user, ?string $couponCode): Order
    {
        return DB::transaction(function () use ($details, $user, $couponCode): Order {
            $cart = $this->lockedCart();
            $items = $cart->items()->with('color')->orderBy('id')->get();

            $products = Product::query()
                ->with('translations')
                ->whereIn('id', $items->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var numeric-string $subtotal */
            $subtotal = '0';
            $hasEstimate = false;

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                // Colors share the product's stock, so the limit counts all its lines.
                $productTotal = (int) $items->where('product_id', $item->product_id)->sum('quantity');

                if ($product === null || ! $product->isPurchasable() || $productTotal > $product->maxCartQuantity()) {
                    throw ValidationException::withMessages(['cart' => __('ui.checkout.line_changed')]);
                }

                $subtotal = bcadd($subtotal, bcmul($product->salePrice(), (string) $item->quantity, 4), 4);
                $hasEstimate = $hasEstimate || $product->price_is_estimate;
            }

            if ($hasEstimate && ! ($details['accept_estimate'] ?? false)) {
                throw ValidationException::withMessages(['accept_estimate' => __('ui.checkout.accept_required')]);
            }

            $coupon = $couponCode === null ? null : DB::table('coupons')->where('code', $couponCode)->lockForUpdate()->first();

            if ($couponCode !== null && ! CouponDiscount::usable($coupon)) {
                throw ValidationException::withMessages(['coupon' => __('ui.checkout.coupon_invalid')]);
            }

            $discount = $coupon ? CouponDiscount::amount($coupon, $subtotal) : '0';
            $afterCoupon = bcsub($subtotal, $discount, 4);
            $pointsUsed = $this->pointsToUse((int) ($details['use_points'] ?? 0), $user, $afterCoupon);
            $pointsDiscount = Loyalty::valueOf($pointsUsed);

            if (bccomp($pointsDiscount, $afterCoupon, 4) > 0) {
                $pointsDiscount = $afterCoupon;
            }

            // Points pay for goods only; delivery is added after them.
            $delivery = Delivery::resolve($details, $afterCoupon, App::getLocale());
            $online = ($details['payment_method'] ?? 'whatsapp') === 'online';

            if ($online && ! $this->payments->enabled()) {
                throw ValidationException::withMessages(['payment_method' => __('ui.payments.unavailable')]);
            }

            $order = Order::query()->create([
                'reference' => $this->newReference(),
                'user_id' => $user?->id,
                'customer_name' => $details['customer_name'],
                // A guest may leave an address for status e-mails; an account uses its own.
                'email' => $user->email ?? ($details['email'] ?? null),
                'phone' => $details['phone'],
                'shipping_address' => ['address' => $details['address'] ?? null],
                'notes' => $details['notes'] ?? null,
                'item_count' => (int) $items->sum('quantity'),
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'points_used' => $pointsUsed,
                'points_discount' => $pointsDiscount,
                'total' => bcadd(bcsub($afterCoupon, $pointsDiscount, 4), $delivery['shipping_fee'], 4),
                ...$delivery,
                'coupon_code' => $coupon?->code,
                'status' => 'pending',
                'payment_method' => $online ? $this->payments->defaultName() : 'whatsapp',
                // An online order waits for its payment; StartPayment opens it next.
                'payment_status' => $online ? 'pending' : 'unpaid',
                'locale' => App::getLocale(),
            ]);

            foreach ($items as $item) {
                /** @var Product $product */
                $product = $products->get($item->product_id);
                $deducted = $product->availabilityUnconfirmed() ? 0 : $item->quantity;

                $order->items()->create([
                    'product_id' => $product->id,
                    'name' => $product->translations->pluck('name', 'locale')->all(),
                    'price' => $product->salePrice(),
                    'original_price' => $product->hasActiveDiscount() ? $product->price : null,
                    'quantity' => $item->quantity,
                    'price_is_estimate' => $product->price_is_estimate,
                    'stock_deducted' => $deducted,
                    // In the language of the order, like the WhatsApp message.
                    'color_name' => $item->color?->nameIn(App::getLocale()),
                ]);

                if ($deducted > 0) {
                    $this->deductStock($product, $deducted, $order);
                }
            }

            if ($coupon) {
                DB::table('coupons')->where('id', $coupon->id)->increment('used_count');
            }

            if ($pointsUsed > 0 && $user !== null) {
                $this->points->record($user->id, -$pointsUsed, PointsLedger::REDEEMED, $order);
            }

            OrderEvent::record($order, OrderEvent::CREATED, to: 'pending');

            $cart->items()->delete();

            return $order;
        });
    }

    /**
     * Checks a points request against the locked balance and the admin's rules.
     * Guests and a switched-off programme simply use none.
     *
     * @param  numeric-string  $payable
     */
    private function pointsToUse(int $requested, ?User $user, string $payable): int
    {
        if ($requested <= 0 || $user === null || ! Loyalty::enabled()) {
            return 0;
        }

        $balance = (int) DB::table('users')->where('id', $user->id)->lockForUpdate()->value('points_balance');
        $minimum = Loyalty::settings()['min_redeem_points'];
        $maximum = Loyalty::maxRedeemable($balance, $payable);

        $error = match (true) {
            $requested > $balance => __('ui.points.not_enough', ['balance' => $balance]),
            $requested < $minimum => __('ui.points.minimum', ['count' => $minimum]),
            $requested > $maximum => __('ui.points.maximum', ['count' => $maximum]),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['use_points' => $error]);
        }

        return $requested;
    }

    private function lockedCart(): Cart
    {
        $current = $this->carts->current();
        $cart = $current ? Cart::query()->lockForUpdate()->find($current->id) : null;

        if ($cart === null || ! $cart->items()->exists()) {
            throw ValidationException::withMessages(['cart' => __('ui.store.cart_empty')]);
        }

        return $cart;
    }

    private function deductStock(Product $product, int $quantity, Order $order): void
    {
        $before = $product->stock_quantity;
        $after = $before - $quantity;

        $product->update(['stock_quantity' => $after]);

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => null,
            'direction' => 'decrease',
            'quantity_delta' => -$quantity,
            'stock_before' => $before,
            'stock_after' => $after,
            'reason' => "Order {$order->reference}",
            'created_at' => now(),
        ]);
    }

    private function newReference(): string
    {
        do {
            $reference = Str::upper(Str::random(8));
        } while (Order::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
