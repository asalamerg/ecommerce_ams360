<?php

namespace App\Models;

use App\Support\ProductSearchIndex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'locale', 'name', 'slug', 'description', 'seo_title', 'seo_description'])]
class ProductTranslation extends Model
{
    protected static function booted(): void
    {
        static::saved(function (ProductTranslation $translation): void {
            $product = Product::query()->find($translation->product_id);

            if ($product !== null) {
                ProductSearchIndex::refresh($product);
            }
        });
    }

    public $timestamps = false;

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
