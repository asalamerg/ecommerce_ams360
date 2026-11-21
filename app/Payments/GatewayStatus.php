<?php

namespace App\Payments;

use InvalidArgumentException;

/**
 * A provider's answer about one payment, in the store's own words.
 */
final readonly class GatewayStatus
{
    public const string PENDING = 'pending';

    public const string PAID = 'paid';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const string REFUNDED = 'refunded';

    public const array ALL = [self::PENDING, self::PAID, self::FAILED, self::CANCELLED, self::REFUNDED];

    /** @var numeric-string|null In major units (shekels), as the provider reported it. */
    public ?string $amount;

    /**
     * @param  string|null  $amount  Anything else than a number is refused.
     */
    public function __construct(
        public string $status,
        ?string $amount = null,
        public ?string $currency = null,
        public ?string $reference = null,
        public ?string $reason = null,
    ) {
        if (! in_array($status, self::ALL, true)) {
            throw new InvalidArgumentException("Unknown payment status [{$status}].");
        }

        if ($amount !== null && ! is_numeric($amount)) {
            throw new InvalidArgumentException('The amount must be numeric.');
        }

        $this->amount = $amount;
    }
}
