<?php

namespace App\Http\Controllers;

use App\Actions\Orders\PlaceOrder;
use App\Actions\Payments\StartPayment;
use App\Http\Requests\PlaceOrderRequest;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CartManager;
use App\Services\StaffAlerts;
use App\Support\CouponDiscount;
use App\Support\Loyalty;
use App\Support\WhatsAppOrderMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CheckoutController extends Controller
{
    /**
     * Only wrong codes count toward the limit, so a customer who mistypes once is
     * not punished, while guessing codes one after another is cut off quickly.
     */
    public function applyCoupon(Request $request, CartManager $carts): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);
        $key = 'coupon-failures:'.($request->user()?->id ?: $request->ip());
        $maxFailures = (int) config('store.coupon_failures_per_hour');

        if (RateLimiter::tooManyAttempts($key, $maxFailures)) {
            throw ValidationException::withMessages(['coupon' => __('ui.checkout.coupon_locked', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ])]);
        }

        $coupon = CouponDiscount::find($data['code']);

        if (! CouponDiscount::usable($coupon)) {
            RateLimiter::hit($key, 3600);

            throw ValidationException::withMessages(['coupon' => __('ui.checkout.coupon_invalid')]);
        }

        $request->session()->put(CartManager::COUPON_SESSION, $coupon->code);

        return back();
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $request->session()->forget(CartManager::COUPON_SESSION);

        return back();
    }

    public function store(PlaceOrderRequest $request, PlaceOrder $placeOrder, StaffAlerts $alerts, StartPayment $startPayment): SymfonyResponse
    {
        $user = $request->user();
        $coupon = $request->session()->get(CartManager::COUPON_SESSION);

        // Each order holds stock until it is confirmed or expires, so one number
        // cannot be used to reserve the whole catalog.
        $phoneKey = 'orders-per-phone:'.hash('sha256', $request->phoneDigits());

        if (RateLimiter::tooManyAttempts($phoneKey, (int) config('store.orders_per_phone_per_day'))) {
            throw ValidationException::withMessages(['phone' => __('ui.checkout.too_many_orders')]);
        }

        $order = $placeOrder->execute(
            $request->validated(),
            $user instanceof User ? $user : null,
            is_string($coupon) ? $coupon : null,
        );

        RateLimiter::hit($phoneKey, 86400);

        // After the order is committed, so staff never hear of an order that failed.
        $alerts->orderPlaced($order);

        $request->session()->forget(CartManager::COUPON_SESSION);
        $request->session()->push('placed_orders', $order->reference);
        $orderPage = to_route('storefront.orders.show', ['locale' => App::getLocale(), 'reference' => $order->reference]);

        if ($order->paymentIsManual()) {
            return $orderPage;
        }

        // The order exists either way; if the provider cannot be reached now,
        // the order page offers to pay again until the order expires.
        try {
            return Inertia::location($startPayment->execute($order));
        } catch (ValidationException $exception) {
            return $orderPage->with('error', $exception->validator->errors()->first());
        }
    }

    /**
     * Only the account that owns an order, or the browser session that placed it,
     * can open it. The reference alone is not enough.
     */
    public function show(Request $request, string $locale, string $reference): Response
    {
        $order = Order::query()->with(['items', 'events' => fn ($events) => $events->whereIn('type', OrderEvent::PUBLIC)->orderBy('id')])
            ->where('reference', $reference)->first();
        $user = $request->user();

        $allowed = $order !== null && (
            ($user !== null && $order->user_id === $user->id)
            || in_array($reference, (array) $request->session()->get('placed_orders', []), true)
        );

        abort_unless($allowed, 404);

        return Inertia::render('storefront/order', [
            'order' => [
                'reference' => $order->reference,
                'status' => __(ucfirst(str_replace('_', ' ', $order->status))),
                'status_key' => $order->status,
                'payment_status' => $order->payment_status,
                'delivery' => $order->deliveryLabel(),
                'shipping_fee' => $order->shipping_fee,
                'timeline' => $order->events->map(fn (OrderEvent $event): array => [
                    'label' => $event->type === OrderEvent::CREATED
                        ? __('ui.order_updates.event_created')
                        : __(ucfirst(str_replace('_', ' ', (string) $event->to))),
                    'at' => $event->created_at?->toIso8601String(),
                ])->values(),
                'customer_name' => $order->customer_name,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount_total,
                'points_used' => $order->points_used,
                'points_discount' => $order->points_discount,
                // Earned when staff mark the order completed, see ApplyOrderStatus.
                'points_to_earn' => $order->user_id !== null && $order->status !== 'cancelled'
                    ? ($order->points_earned ?: Loyalty::pointsFor((string) $order->total))
                    : 0,
                'total' => $order->total,
                'has_estimate' => $order->items->contains('price_is_estimate', true),
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'name' => $item->name[$locale] ?? reset($item->name),
                    'color' => $item->color_name,
                    'quantity' => $item->quantity,
                    'line_total' => $item->lineTotal(),
                ])->values(),
                'whatsapp_url' => $order->paymentIsManual() ? WhatsAppOrderMessage::url($order) : null,
                'online' => ! $order->paymentIsManual(),
                'can_pay' => ! $order->paymentIsManual() && $order->status === 'pending'
                    && in_array($order->payment_status, ['unpaid', 'pending', 'failed'], true),
                'pay_url' => "/{$locale}/orders/{$order->reference}/pay",
                'expires_in_hours' => $order->status === 'pending' && $order->paymentIsManual() ? (int) config('store.pending_order_hours') : 0,
            ],
        ]);
    }
}
