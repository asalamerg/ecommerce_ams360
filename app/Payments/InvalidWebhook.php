<?php

namespace App\Payments;

use RuntimeException;

/**
 * A request to the webhook address that is not a genuine provider notification.
 * Its message is logged; the request body never is.
 */
class InvalidWebhook extends RuntimeException
{
    //
}
