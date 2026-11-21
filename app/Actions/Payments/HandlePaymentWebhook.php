<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use App\Payments\InvalidWebhook;
use App\Payments\PaymentManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A notification from a payment provider, in order:
 * 1. only from the provider's published addresses, when it publishes them;
 * 2. only with a valid signature (the gateway checks it);
 * 3. each event once: a replayed event id changes nothing;
 * 4. the status is read again from the provider's API before it is applied
 *    (payments.verify_with_api), so even a leaked secret cannot mark an order
 *    paid on its own.
 */
class HandlePaymentWebhook
{
    private const int MAX_BODY_BYTES = 65536;

    public function __construct(private PaymentManager $payments, private ApplyGatewayStatus $apply)
    {
        //
    }

    /**
     * @return array{status: int, result: string}
     */
    public function execute(string $gatewayName, Request $request): array
    {
        if (! $this->payments->usable($gatewayName)) {
            return ['status' => 404, 'result' => 'unknown gateway'];
        }

        $allowedIps = (array) config("payments.gateways.{$gatewayName}.webhook_ips", []);

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            Log::warning('Payment webhook from an unexpected address', ['gateway' => $gatewayName, 'ip' => $request->ip()]);

            return ['status' => 403, 'result' => 'address not allowed'];
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return ['status' => 413, 'result' => 'too large'];
        }

        $gateway = $this->payments->gateway($gatewayName);

        try {
            $event = $gateway->parseWebhook($request);
        } catch (InvalidWebhook $exception) {
            Log::warning('Rejected payment webhook', ['gateway' => $gatewayName, 'reason' => $exception->getMessage(), 'ip' => $request->ip()]);

            return ['status' => 400, 'result' => 'invalid'];
        }

        $payment = Payment::query()
            ->where('gateway', $gatewayName)
            ->where('provider_reference', $event->reference)
            ->first();

        try {
            $eventRow = DB::table('payment_webhook_events')->insertGetId([
                'gateway' => $gatewayName,
                'event_id' => mb_substr($event->id, 0, 191),
                'payment_id' => $payment?->id,
                'payload_hash' => hash('sha256', $request->getContent()),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 200, 'result' => 'duplicate'];
        }

        if ($payment === null) {
            Log::warning('Payment webhook for an unknown payment', ['gateway' => $gatewayName, 'event' => $event->id]);
            $this->finish($eventRow, 'unknown payment');

            return ['status' => 200, 'result' => 'unknown payment'];
        }

        $answer = config('payments.verify_with_api') ? $gateway->status($payment) : $event->status;
        $result = $this->apply->execute($payment, $answer, 'webhook');
        $this->finish($eventRow, $result);

        return ['status' => 200, 'result' => $result];
    }

    private function finish(int $eventRow, string $result): void
    {
        DB::table('payment_webhook_events')->where('id', $eventRow)->update(['result' => $result, 'processed_at' => now()]);
    }
}
