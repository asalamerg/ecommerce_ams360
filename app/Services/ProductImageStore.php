<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Applies the image list chosen in the product form. New files are re-encoded by
 * ImageUploads before they are stored.
 */
class ProductImageStore
{
    public const int MAX_IMAGES = 8;

    /** Same as the browser's compression, see resources/js/lib/compress-image.ts. */
    public const int MAX_SIDE = 1600;

    private const string DISK = ImageUploads::DISK;

    public function __construct(private ImageUploads $uploads)
    {
        //
    }

    /**
     * @param  list<string>  $order  Final images in display order: "existing-{id}" or "new-{index}".
     * @param  array<int, UploadedFile>  $uploads
     */
    public function sync(Product $product, array $order, array $uploads): void
    {
        $existing = $product->images()->get()->keyBy('id');
        $kept = [];
        $position = 0;

        foreach (array_slice($order, 0, self::MAX_IMAGES) as $token) {
            [$kind, $key] = explode('-', $token, 2);

            if ($kind === 'existing' && $existing->has((int) $key)) {
                $image = $existing->get((int) $key);
                $image->update(['position' => $position++]);
                $kept[] = $image->id;
            }

            if ($kind === 'new' && isset($uploads[(int) $key])) {
                $path = $this->uploads->store($uploads[(int) $key], "products/{$product->id}", self::MAX_SIDE, "new_images.{$key}");

                $product->images()->create([
                    'path' => $path,
                    'thumb_path' => $this->uploads->thumbnail($path),
                    'position' => $position++,
                ]);
            }
        }

        $removed = $existing->except($kept);

        ProductImage::query()->whereKey($removed->modelKeys())->delete();

        // Files go only after the database has committed, and only when no other
        // product still uses them.
        DB::afterCommit(function () use ($removed): void {
            foreach ($removed as $image) {
                if (! ProductImage::query()->where('path', $image->path)->exists()) {
                    Storage::disk(self::DISK)->delete(array_values(array_filter([$image->path, $image->thumb_path])));
                }
            }
        });
    }
}
