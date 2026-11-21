<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Support\StorefrontCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Works out the price each product sells at now: the lowest of its own
 * discount and every running offer that covers it. The result is stored on the
 * product (sale_price, sale_ends_at, offer_id) so lists sort and filter by it.
 *
 * It is refreshed when a product's price, discount, category or brand changes
 * (Product::booted), when an offer is saved or deleted, and every minute by
 * `store:refresh-prices`, which starts and ends offers on time. The end time is
 * also checked when reading (Product::hasActiveDiscount), so a sale never runs
 * past its end, even between two refreshes.
 */
class ProductPricing
{
    /** @var array{minute: int, offers: Collection<int, Offer>, parents: array<int, int|null>}|null */
    private static ?array $memo = null;

    /**
     * Sets the sale fields on a product about to be saved.
     */
    public static function apply(Product $product): void
    {
        foreach (self::compute($product) as $column => $value) {
            $product->setAttribute($column, $value);
        }
    }

    /**
     * @return array{sale_price: numeric-string|null, sale_ends_at: Carbon|null, offer_id: int|null}
     */
    public static function compute(Product $product): array
    {
        $none = ['sale_price' => null, 'sale_ends_at' => null, 'offer_id' => null];
        $price = (string) $product->price;

        if (bccomp($price, '0', 4) <= 0) {
            return $none;
        }

        $best = null;

        if ($product->ownDiscountActive()) {
            $best = ['sale_price' => self::discounted($price, 'percentage', (string) $product->discount_percent), 'sale_ends_at' => $product->discount_ends_at, 'offer_id' => null];
        }

        ['offers' => $offers, 'parents' => $parents] = self::state();

        foreach ($offers as $offer) {
            if (! $offer->covers($product, $parents)) {
                continue;
            }

            $sale = self::discounted($price, $offer->discount_type, (string) $offer->discount_value);

            if ($sale !== null && ($best === null || $best['sale_price'] === null || bccomp($sale, $best['sale_price'], 4) < 0)) {
                $best = ['sale_price' => $sale, 'sale_ends_at' => $offer->ends_at, 'offer_id' => $offer->id];
            }
        }

        if ($best === null || $best['sale_price'] === null || bccomp($best['sale_price'], $price, 4) >= 0) {
            return $none;
        }

        return $best;
    }

    /**
     * Recomputes every product and writes only those that changed, without
     * firing model events. Returns how many changed.
     */
    public static function refreshAll(): int
    {
        self::forget();
        $changed = 0;

        Product::query()
            ->select(['id', 'price', 'category_id', 'brand_id', 'discount_percent', 'discount_ends_at', 'sale_price', 'sale_ends_at', 'offer_id'])
            ->chunkById(500, function (Collection $products) use (&$changed): void {
                foreach ($products as $product) {
                    $next = self::compute($product);

                    if (self::same($product, $next)) {
                        continue;
                    }

                    DB::table('products')->where('id', $product->id)->update([
                        'sale_price' => $next['sale_price'],
                        'sale_ends_at' => $next['sale_ends_at'],
                        'offer_id' => $next['offer_id'],
                    ]);
                    $changed++;
                }
            });

        if ($changed > 0) {
            StorefrontCache::flush();
        }

        return $changed;
    }

    /**
     * Narrows a product query to those an offer covers, for its page in the store.
     *
     * @param  Builder<Product>  $query
     */
    public static function restrictToOffer(Builder $query, Offer $offer): void
    {
        $categoryIds = $offer->category_id === null ? [] : [
            $offer->category_id,
            ...Category::query()->where('parent_id', $offer->category_id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        ];

        $query->where(function (Builder $covered) use ($offer, $categoryIds): void {
            $covered->whereKey($offer->productIds() ?: [0])
                ->when($categoryIds !== [], fn (Builder $category) => $category->orWhereIn('category_id', $categoryIds))
                ->when($offer->brand_id !== null, fn (Builder $brand) => $brand->orWhere('brand_id', $offer->brand_id));
        });
    }

    /**
     * Drops the remembered offers, after one is saved or deleted.
     */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * Running offers and the category tree, read once a minute at most per
     * process: a catalog import saves hundreds of products in a row.
     *
     * @return array{minute: int, offers: Collection<int, Offer>, parents: array<int, int|null>}
     */
    private static function state(): array
    {
        $minute = intdiv(now()->getTimestamp(), 60);

        if (self::$memo === null || self::$memo['minute'] !== $minute || app()->runningUnitTests()) {
            self::$memo = [
                'minute' => $minute,
                'offers' => Offer::query()->running()->get(),
                'parents' => Category::query()->pluck('parent_id', 'id')
                    ->map(fn (mixed $parent): ?int => $parent === null ? null : (int) $parent)
                    ->all(),
            ];
        }

        return self::$memo;
    }

    /**
     * The price after a discount, rounded down to the agora so the price shown
     * is never above the one charged. Null when nothing would be left to pay.
     *
     * @param  numeric-string  $price
     * @return numeric-string|null
     */
    private static function discounted(string $price, string $type, string $value): ?string
    {
        if (! is_numeric($value) || bccomp($value, '0', 4) <= 0) {
            return null;
        }

        if ($type === 'percentage') {
            // The same ceiling as a product's own discount.
            $percent = bccomp($value, (string) Product::MAX_DISCOUNT_PERCENT, 4) > 0 ? (string) Product::MAX_DISCOUNT_PERCENT : $value;
            $sale = bcdiv(bcmul($price, bcsub('100', $percent, 4), 6), '100', 2);
        } else {
            $sale = bcsub($price, $value, 2);
        }

        return bccomp($sale, '0', 2) > 0 ? bcadd($sale, '0', 4) : null;
    }

    /**
     * @param  array{sale_price: numeric-string|null, sale_ends_at: Carbon|null, offer_id: int|null}  $next
     */
    private static function same(Product $product, array $next): bool
    {
        $price = $product->sale_price === null ? null : (string) $product->sale_price;

        return ($price === null ? $next['sale_price'] === null : ($next['sale_price'] !== null && bccomp($price, $next['sale_price'], 4) === 0))
            && $product->sale_ends_at?->getTimestamp() === $next['sale_ends_at']?->getTimestamp()
            && $product->offer_id === $next['offer_id'];
    }
}
