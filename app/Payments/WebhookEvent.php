<?php

namespace App\Payments;

/**
 * A verified notification: which payment it is about and what it says.
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public string $reference,
        public GatewayStatus $status,
    ) {
        //
    }
}
