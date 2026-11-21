<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImportPreparedCatalog extends Command
{
    protected $signature = 'store:import-prepared-catalog {bundle : Directory containing rows.json, cat/, and cut/}';

    protected $description = 'Import the prepared multilingual catalog and optimized WebP images';

    /**
     * @var array<string, int>
     */
    private const APPROXIMATE_PRICES = [
        'speakers' => 120,
        'bt-speakers' => 160,
        'headphones' => 100,
        'gaming-headsets' => 130,
        'earphones' => 45,
        'mice' => 75,
        'gaming' => 140,
        'keyboards' => 120,
        'mics' => 170,
        'cameras' => 200,
        'security-cameras' => 220,
        'doorbells' => 150,
        'networking' => 220,
        'ups' => 450,
        'power-banks' => 100,
        'tv' => 650,
        'cables' => 30,
        'remotes' => 30,
        'care' => 150,
        'gadgets' => 90,
        'car-holders' => 55,
        'car-chargers' => 60,
    ];

    /**
     * @var list<string>
     */
    private const PREMIUM_BRANDS = [
        'baseus',
        'cudy',
        'fantech',
        'hikvision',
        'jbl',
        'logitech',
        'razer',
        'vention',
    ];

    public function handle(): int
    {
        $bundle = realpath((string) $this->argument('bundle'));

        if (! $bundle || ! is_dir($bundle)) {
            $this->error('The prepared catalog directory does not exist.');

            return self::FAILURE;
        }

        $manifestPath = $bundle.DIRECTORY_SEPARATOR.'rows.json';

        if (! is_file($manifestPath)) {
            $this->error('The prepared catalog rows.json file is missing.');

            return self::FAILURE;
        }

        try {
            $rows = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $this->validateManifest($rows);
            $this->storeImages($bundle, $rows);

            $counts = DB::transaction(fn (): array => $this->importRows($rows));
        } catch (\JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$counts['brands']} brands, {$counts['categories']} categories, and {$counts['products']} products.");

        $this->call('store:make-thumbnails');
        $this->warn('All imported products are marked with approximate ILS prices and zero stock. Review prices and enter real stock before selling.');

        return self::SUCCESS;
    }

    private function validateManifest(mixed $rows): void
    {
        if (! is_array($rows) || ! isset($rows['categories'], $rows['products'])
            || ! is_array($rows['categories']) || ! is_array($rows['products'])
            || $rows['categories'] === [] || $rows['products'] === []) {
            throw new RuntimeException('The prepared catalog manifest has no valid categories or products.');
        }

        if (isset($rows['brands']) && ! is_array($rows['brands'])) {
            throw new RuntimeException('The prepared catalog brand list is invalid.');
        }

        $brandSlugs = [];
        foreach ($rows['brands'] ?? [] as $brand) {
            if (! is_array($brand) || ! $this->validSlug($brand['slug'] ?? null)) {
                throw new RuntimeException('The catalog contains a brand with an invalid slug.');
            }

            $this->validateTranslations($brand['name'] ?? null);
            $brandSlugs[] = $brand['slug'];
        }

        foreach ($rows['categories'] as $category) {
            if (! is_array($category) || ! $this->validSlug($category['slug'] ?? null)) {
                throw new RuntimeException('The catalog contains a category with an invalid slug.');
            }

            $this->validateTranslations($category['name'] ?? null);
            $this->validateTranslations($category['description'] ?? null);

            if (isset($rows['brands'], $category['brand'])
                && ! in_array($category['brand'], $brandSlugs, true)) {
                throw new RuntimeException("The brand for category {$category['slug']} was not found.");
            }

            if (! $this->assetPathFromBundle("{$category['slug']}.webp", 'cat')) {
                throw new RuntimeException("The optimized image for category {$category['slug']} is missing.");
            }
        }

        foreach ($rows['products'] as $product) {
            if (! is_array($product) || ! $this->validSlug($product['slug'] ?? null)
                || ! is_string($product['category'] ?? null)
                || ! is_array($product['images'] ?? null) || $product['images'] === []) {
                throw new RuntimeException('The catalog contains an invalid product row.');
            }

            $this->validateTranslations($product['name'] ?? null);
            $this->validateTranslations($product['description'] ?? null);

            foreach ($product['images'] as $image) {
                if (! is_string($image) || ! $this->assetPathFromBundle($image)) {
                    throw new RuntimeException("An optimized image for {$product['slug']} is missing.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rows
     */
    private function storeImages(string $bundle, array $rows): void
    {
        foreach ($rows['categories'] as $category) {
            $this->copyImage(
                $this->assetPath($bundle, "cat/{$category['slug']}.webp"),
                "catalog/categories/{$category['slug']}.webp",
            );
        }

        foreach ($rows['products'] as $product) {
            foreach ($product['images'] as $index => $image) {
                $this->copyImage(
                    $this->assetPath($bundle, $image),
                    'catalog/products/'.$product['slug'].'/'.($index + 1).'.webp',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rows
     * @return array{brands: int, categories: int, products: int}
     */
    private function importRows(array $rows): array
    {
        $brands = [];
        foreach ($rows['brands'] ?? [] as $row) {
            $brand = Brand::query()->firstOrNew(['slug' => $row['slug']]);
            $brand->fill([
                'name' => $row['name'],
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_active' => true,
            ]);
            $brand->save();
            $brands[$row['slug']] = $brand;
        }

        $categories = [];
        $categoryRows = [];

        foreach ($rows['categories'] as $row) {
            $category = $this->findCategory((string) $row['slug']);
            $category ??= new Category;
            $category->fill([
                'is_active' => true,
                'position' => (int) ($row['sort_order'] ?? 0) + (empty($row['brand']) ? 0 : 1000),
                'image_path' => "catalog/categories/{$row['slug']}.webp",
            ]);
            $category->save();
            $category->trashed() && $category->restore();

            foreach (['ar', 'en', 'he'] as $locale) {
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $row['name'][$locale],
                        'slug' => $locale === 'en' ? $row['slug'] : "{$row['slug']}-{$locale}",
                        'description' => $row['description'][$locale],
                    ],
                );
            }

            $categories[$row['slug']] = $category;
            $categoryRows[$row['slug']] = $row;
        }

        foreach ($rows['products'] as $row) {
            $category = $categories[$row['category']] ?? null;

            if (! $category) {
                throw new RuntimeException("The category for product {$row['slug']} was not found.");
            }

            $product = $this->findProduct((string) $row['slug']);
            $product ??= new Product;
            $isNewOrEstimated = ! $product->exists || $product->price_is_estimate;
            $product->fill([
                'category_id' => $category->id,
                'brand_id' => isset($categoryRows[$row['category']]['brand'])
                    ? ($brands[$categoryRows[$row['category']]['brand']]->id ?? null)
                    : null,
                'sku' => $row['sku'] ?: null,
                'price' => $isNewOrEstimated ? $this->estimatePrice($row, $categoryRows[$row['category']]) : $product->price,
                'currency' => 'ILS',
                'stock_quantity' => $product->exists ? $product->stock_quantity : 0,
                'is_active' => true,
                'is_featured' => false,
                'price_is_estimate' => $isNewOrEstimated,
            ]);
            $product->save();
            $product->trashed() && $product->restore();

            foreach (['ar', 'en', 'he'] as $locale) {
                $product->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $row['name'][$locale],
                        'slug' => $locale === 'en' ? $row['slug'] : "{$row['slug']}-{$locale}",
                        'description' => $row['description'][$locale],
                        'seo_title' => $row['name'][$locale],
                    ],
                );
            }

            foreach ($row['images'] as $index => $image) {
                $product->images()->updateOrCreate(
                    ['path' => 'catalog/products/'.$row['slug'].'/'.($index + 1).'.webp'],
                    ['position' => $index],
                );
            }
        }

        return [
            'brands' => count($brands),
            'categories' => count($categories),
            'products' => count($rows['products']),
        ];
    }

    private function findCategory(string $slug): ?Category
    {
        return Category::withTrashed()
            ->whereHas('translations', fn ($query) => $query->where('locale', 'en')->where('slug', $slug))
            ->first();
    }

    private function findProduct(string $slug): ?Product
    {
        return Product::withTrashed()
            ->whereHas('translations', fn ($query) => $query->where('locale', 'en')->where('slug', $slug))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $product
     */
    /**
     * @param  array{slug: string, brand?: string|null}  $category
     * @param  array{slug: string}  $product
     */
    private function estimatePrice(array $product, array $category): int
    {
        $brand = (string) ($category['brand'] ?? '');
        $type = $brand !== '' ? Str::after($category['slug'], "{$brand}-") : $category['slug'];

        $base = self::APPROXIMATE_PRICES[$type] ?? 100;
        $premium = in_array($brand, self::PREMIUM_BRANDS, true) ? 1.15 : 1;
        $variation = (hexdec(substr(hash('sha256', (string) $product['slug']), 0, 2)) % 31) - 15;

        return max(5, (int) (round(($base * $premium * (1 + ($variation / 100))) / 5) * 5));
    }

    private function validateTranslations(mixed $value): void
    {
        if (! is_array($value)
            || ! is_string($value['ar'] ?? null)
            || ! is_string($value['en'] ?? null)
            || ! is_string($value['he'] ?? null)) {
            throw new RuntimeException('Every prepared category and product must include Arabic, English, and Hebrew text.');
        }
    }

    private function validSlug(mixed $slug): bool
    {
        return is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    private function assetPathFromBundle(string $relative, string $subdirectory = ''): ?string
    {
        $bundle = realpath((string) $this->argument('bundle'));

        return $bundle ? $this->assetPath($bundle, trim($subdirectory.'/'.$relative, '/')) : null;
    }

    private function assetPath(string $bundle, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $path = realpath($bundle.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        $prefix = rtrim($bundle, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! $path || ! is_file($path)
            || ! str_starts_with(strtolower($path), strtolower($prefix))
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'webp') {
            return null;
        }

        return $path;
    }

    private function copyImage(?string $source, string $destination): void
    {
        if (! $source) {
            throw new RuntimeException("Unable to copy the prepared image to {$destination}.");
        }

        $contents = file_get_contents($source);

        if ($contents === false || ! Storage::disk('public')->put($destination, $contents)) {
            throw new RuntimeException("Unable to copy the prepared image to {$destination}.");
        }
    }
}
