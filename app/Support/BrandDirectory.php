<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * The brands shown in the storefront's brand menu and on the brands page: only
 * active brands with something for sale, each with a picture. A brand without
 * an uploaded logo shows its newest product photo instead, so the menu is never
 * a wall of empty boxes. The name is always written out too, because a logo
 * alone is not recognised by everyone.
 */
class BrandDirectory
{
    /**
     * @return list<array{slug: string, name: string, keywords: string, logo_url: string|null, image_url: string|null, count: int}>
     */
    public static function cards(string $locale): array
    {
        return StorefrontCache::remember("brand-cards.{$locale}", function () use ($locale): array {
            $brands = Brand::query()
                ->where('is_active', true)
                ->whereHas('products', fn ($query) => $query->where('is_active', true))
                ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
                ->get();

            $photos = Product::query()
                ->where('is_active', true)
                ->whereIn('brand_id', $brands->modelKeys())
                ->whereHas('images')
                ->with(['images' => fn ($query) => $query->orderBy('position')])
                ->latest('id')
                ->get()
                ->unique('brand_id')
                ->mapWithKeys(fn (Product $product): array => [
                    $product->brand_id => ProductCardData::thumbnailUrl($product->images->first()),
                ]);

            $cards = $brands
                // The order set in the admin panel first, then the brands with most products.
                ->sortBy([['sort_order', 'asc'], ['products_count', 'desc']])
                ->map(fn (Brand $brand): array => [
                    'slug' => (string) $brand->slug,
                    'name' => self::name($brand, $locale),
                    // Every language's name, so "Baseus" finds بيسوس in the Arabic store.
                    'keywords' => implode(' ', array_filter([...array_values($brand->name ?? []), $brand->slug, ...ProductSearchIndex::aliases($brand->aliases)])),
                    'logo_url' => filled($brand->logo_url) ? self::url((string) $brand->logo_url) : null,
                    'image_url' => $photos->get($brand->id),
                    'count' => (int) $brand->products_count,
                ]);

            return array_values($cards->all());
        });
    }

    public static function name(Brand $brand, string $locale): string
    {
        return ProductCardData::localized($brand->name, $locale) ?? (string) $brand->slug;
    }

    public static function description(Brand $brand, string $locale): ?string
    {
        return ProductCardData::localized($brand->description, $locale);
    }

    /**
     * Uploads are stored as /storage/... and old ones as https links.
     */
    public static function url(string $value): string
    {
        return str_starts_with($value, 'https://') || str_starts_with($value, '/')
            ? $value
            : Storage::disk('public')->url($value);
    }
}
