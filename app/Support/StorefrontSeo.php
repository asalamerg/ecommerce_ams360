<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Search and sharing metadata for public pages: title, description, canonical
 * link, language alternates, Open Graph and schema.org data.
 *
 * It is sent as the `seo` page prop. app.blade.php prints it into the first HTML
 * response, so crawlers and link previews (WhatsApp, Facebook) see it without
 * running JavaScript, and resources/js/lib/seo.ts keeps it current on client-side
 * visits. Pages without it are marked noindex.
 *
 * Every URL is built from APP_URL, never from the request's Host header, so a
 * forged header cannot plant another domain in a canonical link or the sitemap.
 */
class StorefrontSeo
{
    public const array LOCALES = ['ar', 'en', 'he'];

    public const string DEFAULT_LOCALE = 'ar';

    private const array OG_LOCALES = ['ar' => 'ar_AR', 'en' => 'en_US', 'he' => 'he_IL'];

    private const int DESCRIPTION_LENGTH = 160;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $route, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($route, $parameters, false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function home(string $locale): array
    {
        $site = self::siteName($locale);
        $settings = self::settings();
        $url = self::url('storefront.home', ['locale' => $locale]);

        return self::page(
            locale: $locale,
            title: __('seo.home_title', [], $locale),
            description: __('seo.home_description', ['site' => $site], $locale),
            canonical: $url,
            alternates: self::alternates(fn (string $other): string => self::url('storefront.home', ['locale' => $other])),
            schema: [
                array_filter([
                    '@type' => 'Organization',
                    '@id' => $url.'#organization',
                    'name' => $site,
                    'url' => $url,
                    'logo' => self::logo(),
                    'telephone' => $settings->phone ?? null,
                    'sameAs' => array_values(array_filter([$settings->facebook_url ?? null, $settings->instagram_url ?? null])) ?: null,
                ]),
                [
                    '@type' => 'WebSite',
                    'name' => $site,
                    'url' => $url,
                    'inLanguage' => $locale,
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => [
                            '@type' => 'EntryPoint',
                            'urlTemplate' => self::url('storefront.products.index', ['locale' => $locale]).'?search={search_term_string}',
                        ],
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
            ],
        );
    }

    /**
     * Search results and query-string filters are left out of the index: they
     * repeat other pages and anyone could create endless URLs with them.
     *
     * @return array<string, mixed>
     */
    public static function products(string $locale, string $search, int $page, bool $filtered = false): array
    {
        $title = $search !== ''
            ? __('seo.search_title', ['search' => Str::limit($search, 60)], $locale)
            : __('seo.products_title', [], $locale);

        return self::page(
            locale: $locale,
            title: self::numbered($title, $page, $locale),
            description: __('seo.products_description', ['site' => self::siteName($locale)], $locale),
            canonical: self::paged(self::url('storefront.products.index', ['locale' => $locale]), $page),
            alternates: self::alternates(fn (string $other): string => self::paged(self::url('storefront.products.index', ['locale' => $other]), $page)),
            index: $search === '' && ! $filtered,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function categories(string $locale): array
    {
        $url = self::url('storefront.categories.index', ['locale' => $locale]);

        return self::page(
            locale: $locale,
            title: __('seo.categories_title', [], $locale),
            description: __('seo.categories_description', ['site' => self::siteName($locale)], $locale),
            canonical: $url,
            alternates: self::alternates(fn (string $other): string => self::url('storefront.categories.index', ['locale' => $other])),
            schema: [self::breadcrumbs($locale, [[__('seo.categories_title', [], $locale), $url]])],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(string $locale, Category $category, int $page, string $search, bool $filtered = false): array
    {
        /** @var Collection<string, CategoryTranslation> $translations */
        $translations = $category->translations()->get()->keyBy('locale');
        $translation = $translations->get($locale);
        $name = (string) $translation?->name;
        $url = fn (string $other): ?string => $translations->has($other)
            ? self::paged(self::url('storefront.categories.show', ['locale' => $other, 'slug' => $translations->get($other)?->slug]), $page)
            : null;

        return self::page(
            locale: $locale,
            title: self::numbered($translation?->seo_title ?: $name, $page, $locale),
            description: $translation?->seo_description
                ?: $translation?->description
                ?: __('seo.category_description', ['name' => $name, 'site' => self::siteName($locale)], $locale),
            canonical: (string) $url($locale),
            alternates: self::alternates($url),
            image: $category->image_path ? self::absolute(Storage::disk('public')->url($category->image_path)) : null,
            schema: [self::breadcrumbs($locale, array_values(array_filter([
                [__('seo.categories_title', [], $locale), self::url('storefront.categories.index', ['locale' => $locale])],
                self::parentCrumb($category, $locale),
                [$name, (string) $url($locale)],
            ])))],
            index: $search === '' && ! $filtered,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function brands(string $locale): array
    {
        $url = self::url('storefront.brands.index', ['locale' => $locale]);

        return self::page(
            locale: $locale,
            title: __('seo.brands_title', [], $locale),
            description: __('seo.brands_description', ['site' => self::siteName($locale)], $locale),
            canonical: $url,
            alternates: self::alternates(fn (string $other): string => self::url('storefront.brands.index', ['locale' => $other])),
            schema: [self::breadcrumbs($locale, [[__('seo.brands_title', [], $locale), $url]])],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(string $locale, Brand $brand, int $page, string $search, bool $filtered = false): array
    {
        $name = BrandDirectory::name($brand, $locale);
        $url = fn (string $other): string => self::paged(self::url('storefront.brands.show', ['locale' => $other, 'slug' => $brand->slug]), $page);

        return self::page(
            locale: $locale,
            title: self::numbered($name, $page, $locale),
            description: BrandDirectory::description($brand, $locale)
                ?: __('seo.brand_description', ['name' => $name, 'site' => self::siteName($locale)], $locale),
            canonical: $url($locale),
            alternates: self::alternates($url),
            image: filled($brand->logo_url) ? self::absolute(BrandDirectory::url((string) $brand->logo_url)) : null,
            schema: [self::breadcrumbs($locale, [
                [__('seo.brands_title', [], $locale), self::url('storefront.brands.index', ['locale' => $locale])],
                [$name, $url($locale)],
            ])],
            index: $search === '' && ! $filtered,
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parentCrumb(Category $category, string $locale): ?array
    {
        $parent = $category->parent?->translations()->where('locale', $locale)->first();

        return $parent === null ? null : [(string) $parent->name, self::url('storefront.categories.show', ['locale' => $locale, 'slug' => $parent->slug])];
    }

    /**
     * Expects images, brand and category.translations (for this locale) loaded.
     *
     * @return array<string, mixed>
     */
    public static function product(string $locale, Product $product, int $reviewCount = 0, float $averageRating = 0): array
    {
        /** @var Collection<string, ProductTranslation> $translations */
        $translations = $product->translations()->get()->keyBy('locale');
        $translation = $translations->get($locale);
        $name = (string) $translation?->name;
        $site = self::siteName($locale);
        $url = fn (string $other): ?string => $translations->has($other)
            ? self::url('storefront.products.show', ['locale' => $other, 'slug' => $translations->get($other)?->slug])
            : null;
        $canonical = (string) $url($locale);
        $images = $product->images->map(fn ($image): string => self::imageUrl($image->path))->values()->all();
        $brand = ProductCardData::localized($product->brand?->name, $locale);
        $category = $product->category?->translations->first();

        $crumbs = [[__('seo.products_title', [], $locale), self::url('storefront.products.index', ['locale' => $locale])]];

        if ($category !== null) {
            $crumbs[] = [$category->name, self::url('storefront.categories.show', ['locale' => $locale, 'slug' => $category->slug])];
        }

        $crumbs[] = [$name, $canonical];

        return self::page(
            locale: $locale,
            title: $translation?->seo_title ?: $name,
            description: $translation?->seo_description
                ?: $translation?->description
                ?: __('seo.product_description', ['name' => $name, 'site' => $site], $locale),
            canonical: $canonical,
            alternates: self::alternates($url),
            type: 'product',
            image: $images[0] ?? null,
            schema: [
                array_filter([
                    '@type' => 'Product',
                    'name' => $name,
                    'url' => $canonical,
                    'image' => $images ?: null,
                    'description' => self::clean($translation?->description),
                    'sku' => $product->sku,
                    'brand' => $brand ? ['@type' => 'Brand', 'name' => $brand] : null,
                    'category' => $category?->name,
                    'offers' => self::offer($product, $canonical, $site),
                    // Only approved reviews count; shown as stars in search results.
                    'aggregateRating' => $reviewCount > 0 ? [
                        '@type' => 'AggregateRating',
                        'ratingValue' => round($averageRating, 1),
                        'reviewCount' => $reviewCount,
                    ] : null,
                ]),
                self::breadcrumbs($locale, $crumbs),
            ],
        );
    }

    /**
     * A customer service page (contact, repairs, SIM plans): one address per
     * language, no query string.
     *
     * @return array<string, mixed>
     */
    public static function servicePage(string $locale, string $route, string $title, string $description): array
    {
        $url = self::url($route, ['locale' => $locale]);

        return self::page(
            locale: $locale,
            title: $title,
            description: $description,
            canonical: $url,
            alternates: self::alternates(fn (string $other): string => self::url($route, ['locale' => $other])),
            schema: [self::breadcrumbs($locale, [[$title, $url]])],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function contentPage(string $locale, string $slug, string $title, ?string $content): array
    {
        return self::page(
            locale: $locale,
            title: $title,
            description: $content ?: $title,
            canonical: self::url('storefront.pages.show', ['locale' => $locale, 'slug' => $slug]),
            alternates: self::alternates(fn (string $other): string => self::url('storefront.pages.show', ['locale' => $other, 'slug' => $slug])),
        );
    }

    public static function siteName(string $locale): string
    {
        return ProductCardData::localized(self::settings()->site_name ?? null, $locale) ?? (string) config('app.name');
    }

    /**
     * @param  array<string, string>  $alternates
     * @param  list<array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    private static function page(
        string $locale,
        string $title,
        ?string $description,
        string $canonical,
        array $alternates,
        string $type = 'website',
        ?string $image = null,
        array $schema = [],
        bool $index = true,
    ): array {
        return [
            'title' => $title,
            'description' => self::clean($description),
            'canonical' => $canonical,
            'robots' => $index ? 'index, follow, max-image-preview:large' : 'noindex, follow',
            'alternates' => $alternates,
            'site_name' => self::siteName($locale),
            'type' => $type,
            'image' => $image ?? self::logo(),
            'locale' => self::OG_LOCALES[$locale] ?? self::OG_LOCALES[self::DEFAULT_LOCALE],
            'schema' => $schema === [] ? null : ['@context' => 'https://schema.org', '@graph' => $schema],
        ];
    }

    /**
     * Only a confirmed price is published. Google shows offer prices in results,
     * and an estimate shown there would be a promise the store did not make.
     *
     * @return array<string, mixed>|null
     */
    private static function offer(Product $product, string $url, string $site): ?array
    {
        if ($product->price_is_estimate || (float) $product->price <= 0) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'url' => $url,
            'price' => number_format((float) $product->salePrice(), 2, '.', ''),
            'priceCurrency' => $product->currency,
            ...($product->hasActiveDiscount() && $product->sale_ends_at !== null
                ? ['priceValidUntil' => $product->sale_ends_at->toDateString()]
                : []),
            'availability' => $product->stock_quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => ['@type' => 'Organization', 'name' => $site],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items  Name and URL, after the home page.
     * @return array<string, mixed>
     */
    private static function breadcrumbs(string $locale, array $items): array
    {
        $items = [[self::siteName($locale), self::url('storefront.home', ['locale' => $locale])], ...$items];

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item[0],
                'item' => $item[1],
            ], $items, array_keys($items)),
        ];
    }

    /**
     * @param  callable(string): ?string  $url
     * @return array<string, string>
     */
    private static function alternates(callable $url): array
    {
        $alternates = [];

        foreach (self::LOCALES as $locale) {
            $link = $url($locale);

            if ($link !== null) {
                $alternates[$locale] = $link;
            }
        }

        if (isset($alternates[self::DEFAULT_LOCALE])) {
            $alternates['x-default'] = $alternates[self::DEFAULT_LOCALE];
        }

        return $alternates;
    }

    /** The full address of a stored image, for crawlers and the sitemap. */
    public static function imageUrl(string $path): string
    {
        return self::absolute(Storage::disk('public')->url($path));
    }

    /**
     * Crawlers and link previews need full URLs; the site itself uses
     * root-relative ones.
     */
    private static function absolute(string $url): string
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? rtrim((string) config('app.url'), '/').$url
            : $url;
    }

    private static function paged(string $url, int $page): string
    {
        return $page > 1 ? $url.'?page='.$page : $url;
    }

    private static function numbered(string $title, int $page, string $locale): string
    {
        return $page > 1 ? $title.' - '.__('seo.page_number', ['page' => $page], $locale) : $title;
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

        return $text === '' ? null : Str::limit($text, self::DESCRIPTION_LENGTH, '…');
    }

    private static function logo(): ?string
    {
        $logo = self::settings()->logo_url ?? null;

        if (! is_string($logo) || (! str_starts_with($logo, 'https://') && ! str_starts_with($logo, '/storage/'))) {
            return null;
        }

        return self::absolute($logo);
    }

    /**
     * Cached as an array: the cache refuses to unserialize objects, even stdClass
     * (see serializable_classes in config/cache.php).
     */
    private static function settings(): object
    {
        $settings = Cache::remember('store.seo.settings', 60, fn (): array => (array) (DB::table('site_settings')
            ->where('id', 1)
            ->first(['site_name', 'logo_url', 'phone', 'facebook_url', 'instagram_url']) ?? []));

        return (object) $settings;
    }
}
