<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property array<string, string|null>|null $name
 * @property array<string, string|null>|null $description
 * @property string|null $logo_url
 * @property string|null $aliases
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'description', 'logo_url', 'sort_order', 'is_active', 'aliases'])]
class Brand extends Model
{
    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
