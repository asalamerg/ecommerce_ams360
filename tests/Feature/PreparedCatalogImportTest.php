<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreparedCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_catalog_import_is_localized_idempotent_and_marks_prices_as_estimates(): void
    {
        Storage::fake('public');
        $bundle = sys_get_temp_dir().DIRECTORY_SEPARATOR.'catalog-bundle-'.bin2hex(random_bytes(6));
        mkdir($bundle.DIRECTORY_SEPARATOR.'cat', recursive: true);
        mkdir($bundle.DIRECTORY_SEPARATOR.'cut', recursive: true);

        file_put_contents($bundle.DIRECTORY_SEPARATOR.'cat'.DIRECTORY_SEPARATOR.'speakers.webp', 'category-image');
        file_put_contents($bundle.DIRECTORY_SEPARATOR.'cut'.DIRECTORY_SEPARATOR.'speaker.webp', 'product-image');
        file_put_contents($bundle.DIRECTORY_SEPARATOR.'rows.json', json_encode([
            'brands' => [[
                'slug' => 'fantech',
                'name' => ['ar' => 'فانتيك', 'en' => 'Fantech', 'he' => 'Fantech'],
                'sort_order' => 1,
            ]],
            'categories' => [[
                'slug' => 'speakers',
                'brand' => 'fantech',
                'name' => ['ar' => 'سماعات', 'en' => 'Speakers', 'he' => 'רמקולים'],
                'description' => ['ar' => 'قسم الصوت', 'en' => 'Audio category', 'he' => 'קטגוריית שמע'],
                'sort_order' => 100,
            ]],
            'products' => [[
                'slug' => 'sample-speaker',
                'category' => 'speakers',
                'name' => ['ar' => 'سماعة تجريبية', 'en' => 'Sample Speaker', 'he' => 'רמקול לדוגמה'],
                'description' => ['ar' => 'وصف السماعة', 'en' => 'Speaker description', 'he' => 'תיאור הרמקול'],
                'sku' => 'SP-001',
                'images' => ['cut/speaker.webp'],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->assertSame(0, Artisan::call('store:import-prepared-catalog', ['bundle' => $bundle]), Artisan::output());

            $product = Product::query()->with('translations', 'images')->firstOrFail();
            $this->assertSame('ILS', $product->currency);
            $this->assertSame('Fantech', Brand::query()->firstOrFail()->name['en']);
            $this->assertSame(Brand::query()->firstOrFail()->id, $product->brand_id);
            $this->assertSame(0, $product->stock_quantity);
            $this->assertTrue($product->price_is_estimate);
            $this->assertTrue($product->is_active);
            $this->assertSame('سماعة تجريبية', $product->translations->firstWhere('locale', 'ar')?->name);
            $this->assertCount(1, $product->images);
            $this->assertSame('catalog/products/sample-speaker/1.webp', $product->images->first()->path);
            $this->assertSame(
                'catalog/categories/speakers.webp',
                Category::query()->firstOrFail()->image_path,
            );
            Storage::disk('public')->assertExists('catalog/products/sample-speaker/1.webp');
            Storage::disk('public')->assertExists('catalog/categories/speakers.webp');

            $this->assertSame(0, Artisan::call('store:import-prepared-catalog', ['bundle' => $bundle]), Artisan::output());
            $this->assertDatabaseCount('products', 1);
            $this->assertDatabaseCount('categories', 1);
            $this->assertDatabaseCount('brands', 1);
            $this->assertDatabaseCount('product_images', 1);
        } finally {
            foreach (glob($bundle.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_dir($path)) {
                    foreach (glob($path.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                        unlink($file);
                    }
                    rmdir($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($bundle);
        }
    }
}
