<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each product's searchable text current, in all three languages at once,
 * so a Latin brand name finds a product on the Arabic store and the other way round.
 */
class ProductSearchIndex
{
    public static function refresh(Product $product): void
    {
        $product->load(['translations', 'brand', 'category.translations']);

        $brand = $product->brand?->getAttribute('name');
        $brandNames = is_string($brand) ? (json_decode($brand, true) ?: [$brand]) : (array) $brand;

        $name = [
            $product->sku,
            ...$product->translations->pluck('name')->all(),
            ...array_values((array) $brandNames),
            // Other spellings staff entered, such as باسيوس for بيسوس.
            ...self::aliases($product->getAttribute('aliases')),
            ...self::aliases($product->brand?->getAttribute('aliases')),
        ];

        $body = [
            ...$product->translations->pluck('description')->all(),
            ...($product->category?->translations->pluck('name')->all() ?? []),
        ];

        DB::table('products')->where('id', $product->id)->update([
            'search_name' => self::folded($name),
            'search_body' => self::folded($body),
        ]);
    }

    public static function rebuild(): int
    {
        $count = 0;

        Product::query()->chunkById(100, function ($products) use (&$count): void {
            foreach ($products as $product) {
                self::refresh($product);
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return list<string>
     */
    public static function aliases(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\n,،]+/u', (string) $value) ?: []), fn (string $alias): bool => $alias !== ''));
    }

    /**
     * The leading space lets a word-start pattern match the very first word.
     *
     * @param  array<int, mixed>  $parts
     */
    private static function folded(array $parts): string
    {
        return ' '.SearchText::normalize(implode(' ', array_filter($parts, 'is_string')));
    }
}
