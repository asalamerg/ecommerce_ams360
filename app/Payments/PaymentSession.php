<?php

namespace App\Payments;

/**
 * Where to send the customer to pay, and the provider's id for the payment.
 */
final readonly class PaymentSession
{
    public function __construct(
        public string $redirectUrl,
        public string $reference,
    ) {
        //
    }
}
