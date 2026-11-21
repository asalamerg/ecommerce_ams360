<?php

namespace App\Support;

use App\Models\CategoryTranslation;
use App\Models\ProductTranslation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds where a product or category lives now when it is asked for under a
 * slug it does not have in this language (/en/products/{arabic-slug}, after
 * switching language).
 *
 * Every URL is built from APP_URL, like StorefrontSeo, never from the request.
 */
class MovedUrls
{
    public static function product(string $locale, string $slug): ?string
    {
        $productId = ProductTranslation::query()
            ->whereIn('slug', self::candidates($slug))
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->value('product_id');

        if ($productId === null) {
            return null;
        }

        $target = self::translatedSlug(ProductTranslation::query()->where('product_id', $productId), $locale);

        return $target === null ? null : StorefrontSeo::url('storefront.products.show', $target);
    }

    public static function category(string $locale, string $slug): ?string
    {
        $categoryId = CategoryTranslation::query()
            ->whereIn('slug', self::candidates($slug))
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->value('category_id');

        if ($categoryId === null) {
            return null;
        }

        $target = self::translatedSlug(CategoryTranslation::query()->where('category_id', $categoryId), $locale);

        return $target === null ? null : StorefrontSeo::url('storefront.categories.show', $target);
    }

    /**
     * Slugs here end in their language (iphone-15-ar, iphone-15-he), so the
     * slug without its language and each language's form are tried.
     *
     * @return list<string>
     */
    private static function candidates(string $slug): array
    {
        $slug = trim(rawurldecode($slug));
        $base = (string) preg_replace('/-(ar|en|he)$/', '', $slug);
        $candidates = [$slug, mb_strtolower($slug), $base];

        foreach (StorefrontSeo::LOCALES as $locale) {
            $candidates[] = "{$base}-{$locale}";
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * The slug in the visitor's language, or in the store's main language when
     * that one is missing.
     *
     * @param  Builder<ProductTranslation>|Builder<CategoryTranslation>  $translations
     * @return array{locale: string, slug: string}|null
     */
    private static function translatedSlug($translations, string $locale): ?array
    {
        $slugs = $translations->pluck('slug', 'locale');

        foreach ([$locale, StorefrontSeo::DEFAULT_LOCALE, ...StorefrontSeo::LOCALES] as $candidate) {
            if (filled($slugs->get($candidate))) {
                return ['locale' => $candidate, 'slug' => (string) $slugs->get($candidate)];
            }
        }

        return null;
    }
}
