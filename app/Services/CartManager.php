<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Support\CouponDiscount;
use App\Support\ProductCardData;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The current visitor's cart. Quantities are checked against the catalog on every
 * write and prices are read from it on every view, so nothing the browser sends
 * can change what a line costs. The request is read at call time because Laravel
 * reuses controllers, and a stored request would leak between visitors under Octane.
 */
class CartManager
{
    public const string COOKIE = 'store_cart';

    public const string COUPON_SESSION = 'cart_coupon';

    private const int COOKIE_MINUTES = 60 * 24 * 30;

    public function current(): ?Cart
    {
        $user = request()->user();

        if ($user instanceof User) {
            return Cart::query()->where('user_id', $user->id)->first();
        }

        $token = request()->cookie(self::COOKIE);

        return is_string($token) && $token !== ''
            ? Cart::query()->where('token', $token)->first()
            : null;
    }

    /**
     * Adds to the line and returns the quantity it ended up with, which is lower
     * than asked for when the product has a limit.
     *
     * A line is a product in one color. A product with colors needs one of its
     * own; a product without colors takes none. Colors share the product's stock,
     * so the limit counts every color line of the product together.
     */
    public function add(int $productId, int $quantity, ?int $colorId = null): int
    {
        return DB::transaction(function () use ($productId, $quantity, $colorId): int {
            $product = $this->purchasableProduct($productId);
            $colorId = $this->validColor($product, $colorId);
            $cart = $this->lockedCart();
            $line = $this->findLine($cart, $product->id, $colorId);
            $final = min(($line->quantity ?? 0) + $quantity, $this->roomFor($cart, $product, $colorId));

            $cart->items()->updateOrCreate(['product_id' => $product->id, 'color_id' => $colorId], ['quantity' => $final]);
            $cart->touch();

            return $final;
        });
    }

    public function update(int $productId, int $quantity, ?int $colorId = null): int
    {
        return DB::transaction(function () use ($productId, $quantity, $colorId): int {
            $product = $this->purchasableProduct($productId);
            $cart = $this->lockedCart();
            $final = max(1, min($quantity, $this->roomFor($cart, $product, $colorId)));

            $cart->items()->where('product_id', $product->id)->where('color_id', $colorId)->update(['quantity' => $final]);
            $cart->touch();

            return $final;
        });
    }

    public function remove(int $productId, ?int $colorId = null): void
    {
        $this->current()?->items()->where('product_id', $productId)->where('color_id', $colorId)->delete();
    }

    /**
     * The quantity this line may hold: the product's limit minus what its other
     * color lines already take.
     */
    private function roomFor(Cart $cart, Product $product, ?int $colorId): int
    {
        $others = (int) $cart->items()
            ->where('product_id', $product->id)
            ->where(fn ($query) => $colorId === null
                ? $query->whereNotNull('color_id')
                : $query->whereNull('color_id')->orWhere('color_id', '!=', $colorId))
            ->sum('quantity');

        return max(0, $product->maxCartQuantity() - $others);
    }

    private function findLine(Cart $cart, int $productId, ?int $colorId): ?CartItem
    {
        return $cart->items()->where('product_id', $productId)->where('color_id', $colorId)->first();
    }

    private function validColor(Product $product, ?int $colorId): ?int
    {
        $colors = $product->colors()->pluck('id');

        if ($colors->isEmpty()) {
            return null;
        }

        if ($colorId === null || ! $colors->contains($colorId)) {
            throw ValidationException::withMessages(['color_id' => __('ui.store.choose_color')]);
        }

        return $colorId;
    }

    /**
     * Moves a guest cart into the account that just signed in. Quantities add up,
     * and each line still respects its product's limit.
     */
    public function mergeGuestCart(User $user): void
    {
        $token = request()->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return;
        }

        DB::transaction(function () use ($token, $user): void {
            $guest = Cart::query()->where('token', $token)->whereNull('user_id')->lockForUpdate()->first();

            if ($guest === null) {
                return;
            }

            $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

            foreach ($guest->items()->with('product')->get() as $item) {
                $limit = $item->product?->maxCartQuantity() ?? 0;

                if ($limit === 0) {
                    continue;
                }

                $existing = $cart->items()->where('product_id', $item->product_id)->where('color_id', $item->color_id)->value('quantity') ?? 0;

                $cart->items()->updateOrCreate(
                    ['product_id' => $item->product_id, 'color_id' => $item->color_id],
                    ['quantity' => min($existing + $item->quantity, $limit)],
                );
            }

            $guest->delete();
        });

        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /**
     * Unavailable lines stay listed so the customer can see what changed, but they
     * count toward neither the total nor the badge.
     *
     * @return array<string, mixed>
     */
    public function summary(string $locale): array
    {
        $items = $this->current()?->items()
            ->with([
                'product.images',
                'product.brand',
                'product.colors',
                'color',
                'product.translations' => fn ($query) => $query->where('locale', $locale),
                'product.category.translations' => fn ($query) => $query->where('locale', $locale),
            ])
            ->oldest('id')
            ->get() ?? collect();

        $lines = $items
            ->filter(fn (CartItem $item): bool => $item->product !== null)
            ->map(fn (CartItem $item): array => $this->line($item, $locale))
            ->values();

        $available = $lines->where('available', true);
        /** @var numeric-string $subtotal */
        $subtotal = $available->reduce(fn (string $total, array $line): string => bcadd($total, $line['line_total'], 4), '0');
        $coupon = CouponDiscount::find((string) session(self::COUPON_SESSION));
        $usable = CouponDiscount::usable($coupon) ? $coupon : null;
        $discount = $usable ? CouponDiscount::amount($usable, $subtotal) : '0';

        return [
            'count' => (int) $available->sum('quantity'),
            'subtotal' => $subtotal,
            'coupon' => $usable?->code,
            'discount' => $discount,
            'total' => bcsub($subtotal, $discount, 4),
            'has_estimate' => $available->contains('price_is_estimate', true),
            'currency' => (string) ($lines->first()['currency'] ?? 'ILS'),
            'has_unconfirmed' => $available->contains('availability_unconfirmed', true),
            'items' => $lines->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CartItem $item, string $locale): array
    {
        /** @var Product $product */
        $product = $item->product;
        $card = ProductCardData::from($product, $locale);
        $quantity = min($item->quantity, max($product->maxCartQuantity(), 1));

        return [
            ...$card,
            // Unique per line: the same product can sit in the cart in two colors.
            'line_key' => $product->id.'-'.($item->color_id ?? 0),
            'color' => $item->color?->toCard($locale),
            'quantity' => $quantity,
            'available' => $product->isPurchasable(),
            'line_total' => bcmul($product->salePrice(), (string) $quantity, 4),
        ];
    }

    private function purchasableProduct(int $productId): Product
    {
        $product = Product::query()->lockForUpdate()->find($productId);

        if ($product === null || ! $product->isPurchasable()) {
            throw ValidationException::withMessages(['cart' => __('ui.store.unavailable')]);
        }

        return $product;
    }

    /**
     * Creates the cart on first use and locks it, so two tabs adding at once
     * cannot lose a line.
     */
    private function lockedCart(): Cart
    {
        $user = request()->user();

        if ($user instanceof User) {
            Cart::query()->firstOrCreate(['user_id' => $user->id]);

            return Cart::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        }

        $existing = $this->current();

        if ($existing !== null) {
            return Cart::query()->lockForUpdate()->findOrFail($existing->id);
        }

        $token = (string) Str::uuid();

        Cookie::queue(self::COOKIE, $token, self::COOKIE_MINUTES);

        return Cart::query()->create(['token' => $token]);
    }
}
