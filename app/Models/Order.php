<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $reference
 * @property int|null $user_id
 * @property string $customer_name
 * @property string|null $email
 * @property string $phone
 * @property array<string, string|null>|null $shipping_address
 * @property string|null $notes
 * @property int $item_count
 * @property numeric-string $subtotal
 * @property numeric-string $discount_total
 * @property numeric-string $total
 * @property string|null $coupon_code
 * @property int $points_used
 * @property numeric-string $points_discount
 * @property int $points_earned
 * @property string $status
 * @property string $payment_status
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property string $delivery_method
 * @property int|null $shipping_zone_id
 * @property int|null $branch_id
 * @property string|null $delivery_label
 * @property numeric-string $shipping_fee
 * @property string $locale
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'reference', 'user_id', 'customer_name', 'email', 'phone', 'shipping_address', 'notes', 'item_count',
    'subtotal', 'discount_total', 'total', 'coupon_code', 'status', 'payment_method', 'payment_status', 'locale',
    'points_used', 'points_discount', 'points_earned', 'payment_reference',
    'delivery_method', 'shipping_zone_id', 'branch_id', 'delivery_label', 'shipping_fee',
])]
class Order extends Model
{
    public const array STATUSES = ['pending', 'processing', 'ready_for_pickup', 'shipped', 'completed', 'cancelled'];

    public const array PAYMENT_STATUSES = ['unpaid', 'pending', 'paid', 'failed', 'refunded'];

    /**
     * Payment methods settled outside the site (WhatsApp, cash, transfer), whose
     * payment status staff record by hand. Anything else is a payment gateway
     * and only its verified confirmation may change the status.
     */
    public const array MANUAL_PAYMENT_METHODS = ['whatsapp', 'cash', 'cash_on_delivery', 'bank_transfer'];

    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            $order->phone_digits = preg_replace('/\D/', '', (string) $order->phone);
        });
    }

    public function paymentIsManual(): bool
    {
        return $this->payment_method === null || in_array($this->payment_method, self::MANUAL_PAYMENT_METHODS, true);
    }

    public function deliveryLabel(): ?string
    {
        if ($this->delivery_label === null) {
            return null;
        }

        return __($this->delivery_method === 'pickup' ? 'Pickup: :place' : 'Delivery: :place', ['place' => $this->delivery_label]);
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'points_discount' => 'decimal:4',
            'points_used' => 'integer',
            'points_earned' => 'integer',
            'total' => 'decimal:4',
            'shipping_fee' => 'decimal:4',
        ];
    }
}
