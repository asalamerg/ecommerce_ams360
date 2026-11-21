<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Rules\TurnstileToken;
use App\Services\CartManager;
use App\Support\Delivery;
use App\Support\Loyalty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(private CartManager $carts)
    {
        //
    }

    public function show(Request $request, string $locale, PaymentManager $payments): Response
    {
        $user = $request->user();

        return Inertia::render('storefront/cart', [
            'customer' => $user instanceof User ? [
                'name' => $user->name,
                'phone' => $user->phone,
                // Filled in from the last order, so a returning customer types less.
                ...$this->lastDelivery($user),
            ] : null,
            'turnstileSiteKey' => TurnstileToken::enabled() ? config('services.turnstile.site_key') : null,
            'delivery' => [
                'zones' => Delivery::zones($locale),
                'branches' => Delivery::pickupBranches($locale),
            ],
            'onlinePayment' => $payments->enabled(),
            // Read fresh: the balance changes outside this session (orders, staff).
            'loyalty' => Loyalty::forCart($user instanceof User
                ? (int) User::query()->whereKey($user->id)->value('points_balance')
                : null),
        ]);
    }

    /**
     * @return array{address: string|null, shipping_zone_id: int|null}
     */
    private function lastDelivery(User $user): array
    {
        $order = Order::query()->where('user_id', $user->id)->where('delivery_method', 'delivery')->latest('id')
            ->first(['shipping_address', 'shipping_zone_id']);

        return [
            'address' => $order?->shipping_address['address'] ?? null,
            'shipping_zone_id' => $order?->shipping_zone_id,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.Product::MAX_ORDER_QUANTITY],
            'color_id' => ['nullable', 'integer'],
        ]);
        $quantity = (int) ($data['quantity'] ?? 1);
        $productId = (int) $data['product_id'];
        $colorId = isset($data['color_id']) ? (int) $data['color_id'] : null;

        $before = $this->currentQuantity($productId, $colorId);
        $after = $this->carts->add($productId, $quantity, $colorId);

        $this->warnWhenLimited($after, $before + $quantity);

        return back();
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Product::MAX_ORDER_QUANTITY],
            'color' => ['nullable', 'integer'],
        ]);

        $this->warnWhenLimited(
            $this->carts->update($product, (int) $data['quantity'], $this->colorFrom($request)),
            (int) $data['quantity'],
        );

        return back();
    }

    public function destroy(Request $request, int $product): RedirectResponse
    {
        $this->carts->remove($product, $this->colorFrom($request));

        return back();
    }

    /** Update and remove name the line's color in the query string, ?color=ID. */
    private function colorFrom(Request $request): ?int
    {
        $color = $request->query('color', $request->input('color'));

        return is_numeric($color) && (int) $color > 0 ? (int) $color : null;
    }

    private function currentQuantity(int $productId, ?int $colorId = null): int
    {
        return (int) $this->carts->current()?->items()->where('product_id', $productId)->where('color_id', $colorId)->value('quantity');
    }

    private function warnWhenLimited(int $final, int $wanted): void
    {
        if ($final < $wanted) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('ui.store.quantity_limited', ['count' => $final])]);
        }
    }
}
