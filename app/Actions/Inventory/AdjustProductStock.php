<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustProductStock
{
    public function execute(
        Product $product,
        User $actor,
        string $direction,
        int $quantity,
        string $reason,
    ): StockMovement {
        return DB::transaction(function () use ($product, $actor, $direction, $quantity, $reason): StockMovement {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->whereKey($product->getKey())
                ->firstOrFail();
            $before = $lockedProduct->stock_quantity;
            $delta = $direction === 'increase' ? $quantity : -$quantity;
            $after = $before + $delta;

            if ($after < 0 || $after > 4294967295) {
                throw ValidationException::withMessages([
                    'quantity' => __('The requested adjustment is outside the available stock range.'),
                ]);
            }

            $lockedProduct->update(['stock_quantity' => $after]);

            return StockMovement::create([
                'product_id' => $lockedProduct->id,
                'user_id' => $actor->id,
                'direction' => $direction,
                'quantity_delta' => $delta,
                'stock_before' => $before,
                'stock_after' => $after,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        });
    }
}
