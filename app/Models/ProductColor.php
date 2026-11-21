<?php

namespace App\Models;

use App\Support\ProductCardData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property array<string, string> $name Locale to name.
 * @property string $hex
 * @property int $position
 */
#[Fillable(['product_id', 'name', 'hex', 'position'])]
class ProductColor extends Model
{
    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function nameIn(string $locale): string
    {
        return ProductCardData::localized($this->name, $locale) ?? $this->hex;
    }

    /**
     * @return array{id: int, name: string, hex: string}
     */
    public function toCard(string $locale): array
    {
        return ['id' => $this->id, 'name' => $this->nameIn($locale), 'hex' => $this->hex];
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'position' => 'integer'];
    }
}
