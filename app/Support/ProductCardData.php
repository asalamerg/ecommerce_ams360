<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/**
 * The fields a product card needs. Expects images, brand, translations and
 * category.translations loaded, with translations limited to one locale.
 */
class ProductCardData
{
    public const array LOAD = ['images', 'brand', 'colors'];

    /**
     * @return array<string, mixed>
     */
    public static function from(Product $product, string $locale): array
    {
        $translation = $product->translations->first();
        $images = $product->images;

        return [
            'id' => $product->id,
            'name' => $translation?->name,
            'slug' => $translation?->slug,
            // The price to pay; original_price is set only while a discount runs.
            'price' => $product->salePrice(),
            'original_price' => $product->hasActiveDiscount() ? $product->price : null,
            'discount_percent' => $product->discountShown(),
            'discount_ends_at' => $product->hasActiveDiscount() ? $product->sale_ends_at?->toIso8601String() : null,
            'currency' => $product->currency,
            'stock_quantity' => $product->stock_quantity,
            'price_is_estimate' => $product->price_is_estimate,
            'availability_unconfirmed' => $product->availabilityUnconfirmed(),
            'purchasable' => $product->isPurchasable(),
            'max_quantity' => $product->maxCartQuantity(),
            // Cards show the small copy; the product page loads the full images.
            'image_url' => self::thumbnailUrl($images->first()),
            'second_image_url' => self::thumbnailUrl($images->get(1)),
            'category' => $product->category?->translations->first()?->name,
            'brand' => self::localized($product->brand?->name, $locale),
            'brand_slug' => $product->brand?->is_active ? $product->brand->slug : null,
            'colors' => $product->colors->map(fn (ProductColor $color): array => $color->toCard($locale))->values()->all(),
            // A small "new" badge on the card for two weeks.
            'is_new' => $product->created_at !== null && $product->created_at->greaterThan(now()->subDays(14)),
        ];
    }

    public static function thumbnailUrl(?ProductImage $image): ?string
    {
        return $image === null ? null : Storage::disk('public')->url($image->thumb_path ?? $image->path);
    }

    public static function localized(mixed $value, string $locale): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($value)) {
            return null;
        }

        // The visitor's language, else the store's main language (Arabic), else any.
        foreach (array_unique([$locale, 'ar', 'en', 'he']) as $candidate) {
            if (is_string($value[$candidate] ?? null) && trim($value[$candidate]) !== '') {
                return $value[$candidate];
            }
        }

        return null;
    }
}
