<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $user_id
 * @property string $type
 * @property string|null $from
 * @property string|null $to
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['order_id', 'user_id', 'type', 'from', 'to', 'note', 'created_at'])]
class OrderEvent extends Model
{
    public const string CREATED = 'created';

    public const string STATUS = 'status';

    public const string PAYMENT = 'payment_status';

    public const string NOTE = 'note';

    public const string CUSTOMER_EMAILED = 'customer_emailed';

    public const string CUSTOMER_WHATSAPP = 'customer_whatsapp';

    /** Event types the customer sees on their order page. */
    public const array PUBLIC = [self::CREATED, self::STATUS];

    public const UPDATED_AT = null;

    public static function record(Order $order, string $type, ?string $from = null, ?string $to = null, ?string $note = null, ?User $actor = null): self
    {
        return self::query()->create([
            'order_id' => $order->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
