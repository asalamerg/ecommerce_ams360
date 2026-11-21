<?php

namespace App\Models;

use App\Support\ProductCardData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A timed promotion from the admin's Offers screen: a percentage or a fixed
 * amount off chosen products, a whole category (a main section includes its
 * categories) or a whole brand. ProductPricing turns running offers into
 * product sale prices. Rows are written by StoreModuleController.
 *
 * @property int $id
 * @property array<string, string|null>|null $title
 * @property array<string, string|null>|null $description
 * @property string|null $image_url
 * @property string $discount_type percentage|fixed
 * @property numeric-string $discount_value
 * @property array<int, int|string>|null $product_ids Chosen product ids, as stored.
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 */
class Offer extends Model
{
    /**
     * Active, started, and not yet ended.
     *
     * @param  Builder<Offer>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $now = now();

        $query->where('is_active', true)
            ->where(fn (Builder $starts) => $starts->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * Whether the offer covers a product.
     *
     * @param  array<int, int|null>  $parents  Category id to its main section id.
     */
    public function covers(Product $product, array $parents): bool
    {
        if (in_array($product->id, $this->productIds(), true)) {
            return true;
        }

        if ($this->category_id !== null && $product->category_id !== null
            && ($this->category_id === $product->category_id || $this->category_id === ($parents[$product->category_id] ?? null))) {
            return true;
        }

        return $this->brand_id !== null && $this->brand_id === $product->brand_id;
    }

    /**
     * An offer saved before it had to choose products, a category or a brand
     * covers nothing: it is not shown in the store.
     */
    public function coversAnything(): bool
    {
        return $this->productIds() !== [] || $this->category_id !== null || $this->brand_id !== null;
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return array_values(array_map('intval', $this->product_ids ?? []));
    }

    public function titleIn(string $locale): ?string
    {
        return ProductCardData::localized($this->title, $locale);
    }

    public function descriptionIn(string $locale): ?string
    {
        return ProductCardData::localized($this->description, $locale);
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'description' => 'array',
            'product_ids' => 'array',
            'discount_value' => 'decimal:4',
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
