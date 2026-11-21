<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Two levels at most: a main section (parent_id null) and the detailed
 * categories under it. A main section shows its own products and its children's.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string|null $image_path
 * @property string|null $thumb_path
 */
#[Fillable(['is_active', 'show_on_home', 'position', 'image_path', 'thumb_path', 'parent_id'])]
class Category extends Model
{
    use SoftDeletes;

    /**
     * @return HasMany<CategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * This category and its active children: what its page lists.
     *
     * @return list<int>
     */
    public function familyIds(): array
    {
        return array_values([$this->id, ...$this->children()->where('is_active', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()]);
    }

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
            'is_active' => 'boolean',
            'show_on_home' => 'boolean',
            'position' => 'integer',
        ];
    }
}
