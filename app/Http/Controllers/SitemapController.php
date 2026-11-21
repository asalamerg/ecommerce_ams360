<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontSeo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * sitemap.xml and robots.txt. Every page is listed once per language with links
 * to its other languages, so search engines show each visitor their own.
 * Product entries also list the product's photos, for image search.
 */
class SitemapController extends Controller
{
    private const int CACHE_SECONDS = 3600;

    /** The first photos are the ones that matter in image search. */
    private const int IMAGES_PER_PRODUCT = 5;

    public function sitemap(): Response
    {
        $xml = Cache::remember('store.sitemap', self::CACHE_SECONDS, fn (): string => $this->build());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    /**
     * Staging and local copies ask not to be crawled at all, so they never compete
     * with the real store in search results.
     */
    public function robots(): Response
    {
        $lines = app()->isProduction()
            ? [
                'User-agent: *',
                'Disallow: /admin',
                'Disallow: /checkout',
                'Disallow: /cart/',
                'Disallow: /*/cart',
                'Disallow: /*/account',
                'Disallow: /*/orders/',
                'Disallow: /*/search/',
                'Disallow: /dashboard',
                'Disallow: /settings',
                '',
                'Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml',
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function build(): string
    {
        $entries = [];

        $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.home', ['locale' => $locale]), null, '1.0');
        $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.products.index', ['locale' => $locale]), null, '0.8');
        $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.categories.index', ['locale' => $locale]), null, '0.7');

        Category::query()
            ->where('is_active', true)
            // A main section has its products in its children.
            ->where(fn ($query) => $query
                ->whereHas('products', fn ($products) => $products->where('is_active', true))
                ->orWhereHas('children.products', fn ($products) => $products->where('is_active', true)))
            ->with('translations')
            ->orderBy('id')
            ->each(function (Category $category) use (&$entries): void {
                $entries[] = $this->translated(
                    $category->translations->pluck('slug', 'locale')->all(),
                    'storefront.categories.show',
                    $category->updated_at?->toAtomString(),
                    '0.7',
                );
            });

        $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.brands.index', ['locale' => $locale]), null, '0.6');
        $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.contact', ['locale' => $locale]), null, '0.4');
        $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.repairs', ['locale' => $locale]), null, '0.4');

        if (DB::table('sim_plans')->where('is_active', true)->exists()) {
            $entries[] = $this->sameForAllLocales(fn (string $locale): string => StorefrontSeo::url('storefront.sim-plans', ['locale' => $locale]), null, '0.4');
        }

        Brand::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->each(function (Brand $brand) use (&$entries): void {
                $entries[] = $this->sameForAllLocales(
                    fn (string $locale): string => StorefrontSeo::url('storefront.brands.show', ['locale' => $locale, 'slug' => $brand->slug]),
                    $brand->updated_at?->toAtomString(),
                    '0.6',
                );
            });

        Product::query()
            ->where('is_active', true)
            ->with(['translations', 'images'])
            ->orderBy('id')
            ->each(function (Product $product) use (&$entries): void {
                $entries[] = $this->translated(
                    $product->translations->pluck('slug', 'locale')->all(),
                    'storefront.products.show',
                    $product->updated_at?->toAtomString(),
                    '0.9',
                    array_values($product->images->take(self::IMAGES_PER_PRODUCT)
                        ->map(fn ($image): string => StorefrontSeo::imageUrl($image->path))->all()),
                );
            });

        DB::table('pages')->where('is_active', true)->orderBy('id')->get(['slug', 'updated_at'])
            ->each(function (object $page) use (&$entries): void {
                $entries[] = $this->sameForAllLocales(
                    fn (string $locale): string => StorefrontSeo::url('storefront.pages.show', ['locale' => $locale, 'slug' => $page->slug]),
                    $page->updated_at ? date(DATE_ATOM, (int) strtotime((string) $page->updated_at)) : null,
                    '0.4',
                );
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        foreach (array_merge(...$entries) as $entry) {
            $xml .= $this->url($entry);
        }

        return $xml.'</urlset>'."\n";
    }

    /**
     * @param  callable(string): string  $url
     * @return list<array{loc: string, alternates: array<string, string>, lastmod: string|null, priority: string, images: list<string>}>
     */
    private function sameForAllLocales(callable $url, ?string $lastmod, string $priority): array
    {
        $alternates = [];

        foreach (StorefrontSeo::LOCALES as $locale) {
            $alternates[$locale] = $url($locale);
        }

        return $this->group($alternates, $lastmod, $priority);
    }

    /**
     * @param  array<string, string>  $slugs  Locale to slug.
     * @param  list<string>  $images
     * @return list<array{loc: string, alternates: array<string, string>, lastmod: string|null, priority: string, images: list<string>}>
     */
    private function translated(array $slugs, string $route, ?string $lastmod, string $priority, array $images = []): array
    {
        $alternates = [];

        foreach (StorefrontSeo::LOCALES as $locale) {
            if (isset($slugs[$locale])) {
                $alternates[$locale] = StorefrontSeo::url($route, ['locale' => $locale, 'slug' => $slugs[$locale]]);
            }
        }

        return $this->group($alternates, $lastmod, $priority, $images);
    }

    /**
     * @param  array<string, string>  $alternates
     * @param  list<string>  $images
     * @return list<array{loc: string, alternates: array<string, string>, lastmod: string|null, priority: string, images: list<string>}>
     */
    private function group(array $alternates, ?string $lastmod, string $priority, array $images = []): array
    {
        if (isset($alternates[StorefrontSeo::DEFAULT_LOCALE])) {
            $alternates['x-default'] = $alternates[StorefrontSeo::DEFAULT_LOCALE];
        }

        $entries = [];

        foreach (StorefrontSeo::LOCALES as $locale) {
            if (isset($alternates[$locale])) {
                $entries[] = ['loc' => $alternates[$locale], 'alternates' => $alternates, 'lastmod' => $lastmod, 'priority' => $priority, 'images' => $images];
            }
        }

        return $entries;
    }

    /**
     * @param  array{loc: string, alternates: array<string, string>, lastmod: string|null, priority: string, images: list<string>}  $entry
     */
    private function url(array $entry): string
    {
        $xml = '  <url>'."\n".'    <loc>'.$this->escape($entry['loc']).'</loc>'."\n";

        foreach ($entry['alternates'] as $hreflang => $href) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="'.$this->escape($hreflang).'" href="'.$this->escape($href).'"/>'."\n";
        }

        foreach ($entry['images'] as $image) {
            $xml .= '    <image:image><image:loc>'.$this->escape($image).'</image:loc></image:image>'.'
';
        }

        if ($entry['lastmod'] !== null) {
            $xml .= '    <lastmod>'.$this->escape($entry['lastmod']).'</lastmod>'."\n";
        }

        return $xml.'    <priority>'.$entry['priority'].'</priority>'."\n".'  </url>'."\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
