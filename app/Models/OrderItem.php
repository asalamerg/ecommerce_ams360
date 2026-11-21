<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A snapshot of the product at order time, so later catalog edits never change
 * what the customer ordered. stock_deducted records how many units to give back
 * if the order is cancelled.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property array<string, string> $name
 * @property numeric-string $price
 * @property int $quantity
 * @property string|null $color_name
 * @property bool $price_is_estimate
 * @property int $stock_deducted
 */
#[Fillable(['order_id', 'product_id', 'name', 'price', 'original_price', 'quantity', 'price_is_estimate', 'stock_deducted', 'color_name'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'price' => 'decimal:4',
            'original_price' => 'decimal:4',
            'quantity' => 'integer',
            'price_is_estimate' => 'boolean',
            'stock_deducted' => 'integer',
        ];
    }

    public function lineTotal(): string
    {
        return bcmul($this->price, (string) $this->quantity, 4);
    }
}
