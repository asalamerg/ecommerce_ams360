<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int|null $color_id
 * @property int $quantity
 * @property-read Product|null $product
 * @property-read ProductColor|null $color
 */
#[Fillable(['cart_id', 'product_id', 'color_id', 'quantity'])]
class CartItem extends Model
{
    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductColor, $this>
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(ProductColor::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
