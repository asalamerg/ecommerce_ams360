<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ProductTranslation;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keeps the heaviest public data (home page rows, the category list) ready, so
 * a visit costs a cache read instead of a dozen queries.
 *
 * Every entry carries the current catalog version in its key. Saving or deleting
 * anything shown on the storefront, including a stock change from an order,
 * moves the version on, so a stale entry is never read again and simply expires.
 */
class StorefrontCache
{
    private const string VERSION_KEY = 'storefront.version';

    /** Upper bound only: any catalog change takes effect at once. */
    private const int SECONDS = 600;

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public static function remember(string $key, Closure $build): mixed
    {
        $version = Cache::get(self::VERSION_KEY) ?? self::flush();

        return Cache::remember("storefront.{$version}.{$key}", self::SECONDS, $build);
    }

    public static function flush(): string
    {
        $version = Str::random(12);
        Cache::forever(self::VERSION_KEY, $version);

        return $version;
    }

    /**
     * Called once at boot: any change to these models moves the version on.
     */
    public static function watchModels(): void
    {
        $models = [
            Product::class, ProductTranslation::class, ProductImage::class, ProductColor::class,
            Category::class, CategoryTranslation::class, Brand::class,
        ];

        foreach ($models as $model) {
            $model::saved(fn () => self::flush());
            $model::deleted(fn () => self::flush());
        }
    }
}
