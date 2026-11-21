<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A signed-in customer's cart (user_id) or a guest's cart (token, held in an
 * encrypted cookie). Prices are never stored here: they are read from the catalog
 * every time, so a cart can never carry a stale or tampered price.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $token
 */
#[Fillable(['user_id', 'token'])]
class Cart extends Model
{
    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
