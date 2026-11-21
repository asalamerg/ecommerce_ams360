<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $gateway
 * @property string $status
 * @property numeric-string $amount
 * @property string $currency
 * @property string|null $provider_reference
 * @property string $idempotency_key
 * @property string $return_token_hash
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $refunded_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'order_id', 'gateway', 'status', 'amount', 'currency', 'provider_reference', 'idempotency_key',
    'return_token_hash', 'failure_reason', 'paid_at', 'refunded_at',
])]
#[Hidden(['return_token_hash', 'idempotency_key'])]
class Payment extends Model
{
    public const string CREATED = 'created';

    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const string REFUNDED = 'refunded';

    /** The provider said something that does not add up; a person must look. */
    public const string REVIEW = 'review';

    /** States a notification can no longer move backwards from. */
    public const array SETTLED = [self::PAID, self::REFUNDED, self::REVIEW];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The amount in agorot, as most providers expect it.
     */
    public function minorAmount(): int
    {
        return (int) bcmul((string) $this->amount, '100', 0);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::CREATED, self::PENDING], true);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }
}
