<?php

namespace App\Models;

use App\Services\ProductPricing;
use App\Support\ProductSearchIndex;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property numeric-string $price The price before any discount.
 * @property int $discount_percent The product's own discount; 0 means none.
 * @property Carbon|null $discount_ends_at
 * @property numeric-string|null $sale_price The price to pay now, see ProductPricing.
 * @property Carbon|null $sale_ends_at
 * @property int|null $offer_id
 */
#[Fillable(['category_id', 'brand_id', 'sku', 'price', 'discount_percent', 'discount_ends_at', 'currency', 'stock_quantity', 'is_active', 'is_featured', 'price_is_estimate', 'aliases'])]
class Product extends Model
{
    use SoftDeletes;

    /** Most units of one product a single order can hold. */
    public const int MAX_ORDER_QUANTITY = 50;

    /** Limit while stock is not yet known, see availabilityUnconfirmed(). */
    public const int UNCONFIRMED_ORDER_QUANTITY = 5;

    /** The largest discount the admin can set, so a typo cannot give a product away. */
    public const int MAX_DISCOUNT_PERCENT = 90;

    protected static function booted(): void
    {
        // The sale price follows the price, the discount and what offers cover.
        static::saving(function (Product $product): void {
            if (! $product->exists || $product->isDirty(['price', 'discount_percent', 'discount_ends_at', 'category_id', 'brand_id'])) {
                ProductPricing::apply($product);
            }
        });
        static::saved(fn (Product $product) => ProductSearchIndex::refresh($product));
    }

    /**
     * Every word of the query must match. Products that match in the name, brand or
     * SKU come before those that only match in a description.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, string $search): void
    {
        $terms = SearchText::terms($search);

        if ($terms === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($terms as $spellings) {
            $query->where(function (Builder $word) use ($spellings): void {
                foreach ($spellings as $spelling) {
                    $word->orWhere('search_name', 'like', SearchText::pattern($spelling))
                        ->orWhere('search_body', 'like', SearchText::pattern($spelling));
                }
            });
        }

        $query->orderByRaw('CASE WHEN search_name LIKE ? THEN 0 ELSE 1 END', [SearchText::pattern($terms[0][0])]);
    }

    /**
     * The product's own discount, set in its form: above zero, and its end
     * date, if any, still ahead. Offers are separate, see ProductPricing.
     */
    public function ownDiscountActive(): bool
    {
        return $this->discount_percent > 0
            && ($this->discount_ends_at === null || $this->discount_ends_at->isFuture());
    }

    /**
     * A lower price is running now, from the product's own discount or an
     * offer. ProductPricing stores it; its end time is checked here, so an
     * offer that has just ended never charges the old sale price.
     */
    public function hasActiveDiscount(): bool
    {
        return $this->sale_price !== null
            && bccomp((string) $this->sale_price, (string) $this->price, 4) < 0
            && ($this->sale_ends_at === null || $this->sale_ends_at->isFuture());
    }

    /**
     * What the customer pays for one unit.
     *
     * @return numeric-string
     */
    public function salePrice(): string
    {
        $sale = $this->sale_price;

        return $sale !== null && $this->hasActiveDiscount() ? $sale : $this->price;
    }

    /**
     * The saving as a whole percentage, for the badge; 0 without a discount.
     */
    public function discountShown(): int
    {
        if (! $this->hasActiveDiscount() || (float) $this->price <= 0) {
            return 0;
        }

        return max(1, (int) round((1 - (float) $this->sale_price / (float) $this->price) * 100));
    }

    /**
     * Products with a lower price running now.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->whereNotNull('sale_price')
            ->whereColumn('sale_price', '<', 'price')
            ->where(fn (Builder $ends) => $ends->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>', now()));
    }

    /**
     * The price a customer pays, as SQL, so sorting and the price filter follow
     * the sale price. Its one binding is the current time.
     */
    private const string SALE_PRICE_SQL = '(CASE WHEN sale_price IS NOT NULL AND sale_price < price AND (sale_ends_at IS NULL OR sale_ends_at > ?) THEN sale_price ELSE price END)';

    /**
     * Products whose price to pay is at least, or at most, an amount. The amount
     * is cast: SQLite compares a bound string with an expression as text.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWherePricePaid(Builder $query, string $operator, float|int|string $amount): void
    {
        $sql = match ($operator) {
            '>=' => self::SALE_PRICE_SQL.' >= CAST(? AS DECIMAL(14,4))',
            '<=' => self::SALE_PRICE_SQL.' <= CAST(? AS DECIMAL(14,4))',
            default => throw new \InvalidArgumentException("Unsupported price comparison: {$operator}"),
        };

        $query->whereRaw($sql, [now()->toDateTimeString(), $amount]);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeOrderByPricePaid(Builder $query, string $direction = 'asc'): void
    {
        $direction === 'desc'
            ? $query->orderByRaw(self::SALE_PRICE_SQL.' desc', [now()->toDateTimeString()])
            : $query->orderByRaw(self::SALE_PRICE_SQL.' asc', [now()->toDateTimeString()]);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * Imported products start with an estimated price and no stock count, which
     * means stock has not been entered yet, not that the product has sold out.
     */
    public function availabilityUnconfirmed(): bool
    {
        return $this->stock_quantity <= 0 && $this->price_is_estimate;
    }

    public function isPurchasable(): bool
    {
        return $this->is_active
            && (float) $this->price > 0
            && ($this->stock_quantity > 0 || $this->availabilityUnconfirmed());
    }

    public function maxCartQuantity(): int
    {
        if (! $this->isPurchasable()) {
            return 0;
        }

        return $this->availabilityUnconfirmed()
            ? self::UNCONFIRMED_ORDER_QUANTITY
            : min($this->stock_quantity, self::MAX_ORDER_QUANTITY);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ProductColor, $this>
     */
    public function colors(): HasMany
    {
        return $this->hasMany(ProductColor::class)->orderBy('position')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'discount_percent' => 'integer',
            'discount_ends_at' => 'datetime',
            'sale_price' => 'decimal:4',
            'sale_ends_at' => 'datetime',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'price_is_estimate' => 'boolean',
        ];
    }
}
