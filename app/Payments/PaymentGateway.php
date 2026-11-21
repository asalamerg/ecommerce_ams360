<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * What the store needs from an online payment provider. One class per provider
 * implements it (see Gateways/FakeGateway for a complete example).
 *
 * Rules every implementation must keep:
 * - The customer pays on the provider's own page. Card numbers never reach
 *   this server.
 * - Amounts are sent from the Payment row, never from anything the browser sent.
 * - parseWebhook() proves the request came from the provider (signature over the
 *   raw body, compared in constant time, with a time limit) before reading it.
 * - Secrets come from config/payments.php (the .env file) and are never logged.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * True for stand-in gateways that move no money. PaymentManager refuses
     * them in production.
     */
    public static function forTestingOnly(): bool;

    /**
     * Opens a payment with the provider and returns where to send the customer.
     * Must pass $payment->idempotency_key so a retried call never charges twice.
     */
    public function start(Payment $payment, Order $order, string $returnUrl, string $webhookUrl): PaymentSession;

    /**
     * Checks that the request is a genuine notification from the provider and
     * reads it. Throws InvalidWebhook for anything that is not.
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Asks the provider directly, server to server, what happened to a payment.
     * The only answer trusted when the customer comes back from the payment page.
     */
    public function status(Payment $payment): GatewayStatus;

    /**
     * Refunds the whole payment. Returns the provider's answer; only a "refunded"
     * status marks it refunded here.
     */
    public function refund(Payment $payment): GatewayStatus;

    /**
     * Origins the customer's browser is sent to or shows in a frame, for the
     * Content Security Policy.
     *
     * @return list<string>
     */
    public function browserOrigins(): array;
}
