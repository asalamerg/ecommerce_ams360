<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\ProductImage;
use App\Services\ImageUploads;
use Illuminate\Console\Command;

/**
 * Makes the small copies that product cards and category circles show, for
 * images that have none yet: imported ones or those uploaded before thumbnails.
 * Safe to run again; images already small enough are skipped.
 */
class MakeProductThumbnails extends Command
{
    protected $signature = 'store:make-thumbnails';

    protected $description = 'Create small images for product cards and category circles that have none';

    public function handle(ImageUploads $uploads): int
    {
        $made = 0;

        ProductImage::query()->whereNull('thumb_path')->orderBy('id')->each(function (ProductImage $image) use ($uploads, &$made): void {
            // An image shared by several products gets one thumbnail.
            $thumbnail = ProductImage::query()->where('path', $image->path)->whereNotNull('thumb_path')->value('thumb_path')
                ?? $uploads->thumbnail($image->path);

            if ($thumbnail !== null) {
                ProductImage::query()->where('path', $image->path)->update(['thumb_path' => $thumbnail]);
                $made++;
            }
        });

        Category::query()->whereNotNull('image_path')->whereNull('thumb_path')->orderBy('id')->each(function (Category $category) use ($uploads, &$made): void {
            $thumbnail = $uploads->thumbnail((string) $category->image_path, ImageUploads::CATEGORY_THUMBNAIL_SIDE);

            if ($thumbnail !== null) {
                $category->update(['thumb_path' => $thumbnail]);
                $made++;
            }
        });

        $this->info("Created thumbnails for {$made} images.");

        return self::SUCCESS;
    }
}
