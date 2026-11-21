<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductColor;
use App\Rules\TurnstileToken;
use App\Services\CartManager;
use App\Services\StaffAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Small customer features around products and orders: reviews, back-in-stock
 * e-mails, favorites that follow the account, and ordering again.
 */
class CustomerFeaturesController extends Controller
{
    /**
     * Only a customer who received the product (a completed order) may review
     * it, once. Reviews wait for staff approval before anyone sees them.
     */
    public function review(Request $request, string $locale, Product $product, StaffAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();

        abort_unless(self::hasBought($user->id, $product->id), 403, __('ui.reviews.only_buyers'));

        DB::table('reviews')->updateOrInsert(
            ['product_id' => $product->id, 'user_id' => $user->id],
            [
                'customer_name' => mb_substr($user->name, 0, 120),
                'rating' => $data['rating'],
                'comment' => filled($data['comment'] ?? null) ? strip_tags((string) $data['comment']) : null,
                // An edited review is checked again.
                'is_approved' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $alerts->send('review', 'support', 'New review to moderate (:rating stars)', ['rating' => $data['rating']], url('/admin/modules/reviews'), external: false);

        return back()->with('success', __('ui.reviews.thanks'));
    }

    public function notifyWhenAvailable(Request $request, string $locale, Product $product): RedirectResponse
    {
        // Any address can be typed here, so the bot check keeps it from being used to send mail to strangers.
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            ...TurnstileToken::rules($request->ip()),
        ], ['turnstile_token.required' => __('ui.checkout.verification_required')]);

        abort_unless($product->is_active, 404);

        if ($product->isPurchasable()) {
            return back();
        }

        DB::table('stock_alerts')->updateOrInsert(
            ['product_id' => $product->id, 'email' => strtolower($data['email'])],
            ['user_id' => $request->user()?->id, 'locale' => $locale, 'notified_at' => null, 'updated_at' => now(), 'created_at' => now()],
        );

        return back()->with('success', __('ui.stock_alerts.saved'));
    }

    /**
     * Merges the browser's favorites with the account's and returns the result,
     * so the list is the same on every device the customer signs in on.
     */
    public function syncFavorites(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:60'],
            'ids.*' => ['integer', 'min:1'],
            'removed' => ['sometimes', 'array', 'max:60'],
            'removed.*' => ['integer', 'min:1'],
        ]);
        $userId = $request->user()->id;

        if (($data['removed'] ?? []) !== []) {
            DB::table('favorites')->where('user_id', $userId)->whereIn('product_id', $data['removed'])->delete();
        }

        $existing = Product::query()->whereKey($data['ids'])->pluck('id');
        DB::table('favorites')->insertOrIgnore($existing->map(fn (int $id): array => [
            'user_id' => $userId, 'product_id' => $id, 'created_at' => now(),
        ])->all());

        $ids = DB::table('favorites')->where('user_id', $userId)->orderByDesc('created_at')->limit(60)->pluck('product_id');

        return response()->json(['ids' => $ids->map(fn (mixed $id): int => (int) $id)->values()]);
    }

    /**
     * Puts the items of a past order back in the cart, at today's prices. Lines
     * that are no longer for sale, or whose color is gone, are left out and counted.
     */
    public function reorder(Request $request, string $locale, string $reference, CartManager $carts): RedirectResponse
    {
        $order = Order::query()->with('items')->where('reference', $reference)->firstOrFail();
        $user = $request->user();

        abort_unless(
            ($user !== null && $order->user_id === $user->id)
                || in_array($reference, (array) $request->session()->get('placed_orders', []), true),
            404,
        );

        $skipped = 0;

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $product = $item->product_id ? Product::query()->with('colors')->find($item->product_id) : null;
            $colorId = $product === null ? null : $this->colorId($product, $item->color_name);

            if ($product === null || ! $product->isPurchasable() || ($product->colors->isNotEmpty() && $colorId === null)) {
                $skipped++;

                continue;
            }

            $carts->add($product->id, $item->quantity, $colorId);
        }

        return to_route('storefront.cart', ['locale' => App::getLocale()])->with('success', $skipped > 0
            ? __('ui.reorder.partly', ['count' => $skipped])
            : __('ui.reorder.done'));
    }

    public static function hasBought(int $userId, int $productId): bool
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $userId)
            ->where('orders.status', 'completed')
            ->where('order_items.product_id', $productId)
            ->exists();
    }

    private function colorId(Product $product, ?string $name): ?int
    {
        if ($name === null || $product->colors->isEmpty()) {
            return null;
        }

        $color = $product->colors->first(fn (ProductColor $color): bool => in_array($name, array_filter([
            $color->nameIn('ar'), $color->nameIn('en'), $color->nameIn('he'),
        ]), true));

        return $color?->id;
    }
}
