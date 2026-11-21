<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $product_id
 * @property string|null $product_name
 * @property int|null $user_id
 * @property string $direction
 * @property int $quantity_delta
 * @property int $stock_before
 * @property int $stock_after
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property-read Product|null $product
 * @property-read User|null $user
 */
#[Fillable([
    'product_id',
    'product_name',
    'user_id',
    'direction',
    'quantity_delta',
    'stock_before',
    'stock_after',
    'reason',
    'created_at',
])]
class StockMovement extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
