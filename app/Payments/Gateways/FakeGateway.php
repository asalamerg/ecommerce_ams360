<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\GatewayStatus;
use App\Payments\HmacSignature;
use App\Payments\InvalidWebhook;
use App\Payments\PaymentGateway;
use App\Payments\PaymentSession;
use App\Payments\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * A stand-in provider for development and tests, never available in
 * production (see PaymentManager). It behaves like a real hosted payment page:
 * the customer approves or declines on a separate page, the "provider" sends
 * a signed notification, and its status API answers from what was chosen.
 * A real gateway class follows the same shape against the provider's API.
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public static function forTestingOnly(): bool
    {
        return true;
    }

    public function start(Payment $payment, Order $order, string $returnUrl, string $webhookUrl): PaymentSession
    {
        $reference = 'fake_'.Str::lower(Str::random(24));

        Cache::put($this->key($reference), [
            'status' => GatewayStatus::PENDING,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'return_url' => $returnUrl,
        ], now()->addDay());

        return new PaymentSession(
            URL::temporarySignedRoute('payments.fake.show', now()->addMinutes(30), ['reference' => $reference]),
            $reference,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->getContent();
        HmacSignature::verify($request->header('X-Fake-Signature'), $body, $this->secret());

        $data = json_decode($body, true);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['reference'] ?? null)) {
            throw new InvalidWebhook('The notification is not readable.');
        }

        return new WebhookEvent($data['id'], $data['reference'], new GatewayStatus(
            (string) ($data['status'] ?? ''),
            isset($data['amount']) ? (string) $data['amount'] : null,
            isset($data['currency']) ? (string) $data['currency'] : null,
            $data['reference'],
        ));
    }

    public function status(Payment $payment): GatewayStatus
    {
        $state = $this->state((string) $payment->provider_reference);

        if ($state === null) {
            return new GatewayStatus(GatewayStatus::FAILED, reason: 'unknown payment');
        }

        return new GatewayStatus($state['status'], $state['amount'], $state['currency'], $payment->provider_reference);
    }

    public function refund(Payment $payment): GatewayStatus
    {
        $this->decide((string) $payment->provider_reference, GatewayStatus::REFUNDED);

        return $this->status($payment);
    }

    public function browserOrigins(): array
    {
        return [];
    }

    /**
     * What the fake provider page does when the customer chooses: records the
     * outcome for status() and returns a signed notification body and header.
     *
     * @return array{body: string, signature: string, return_url: string}|null
     */
    public function decide(string $reference, string $status): ?array
    {
        $state = $this->state($reference);

        if ($state === null) {
            return null;
        }

        $state['status'] = $status;
        Cache::put($this->key($reference), $state, now()->addDay());

        $body = (string) json_encode([
            'id' => 'evt_'.Str::lower(Str::random(20)),
            'reference' => $reference,
            'status' => $status,
            'amount' => $state['amount'],
            'currency' => $state['currency'],
        ]);

        return ['body' => $body, 'signature' => HmacSignature::sign($body, $this->secret()), 'return_url' => $state['return_url']];
    }

    /**
     * @return array{status: string, amount: string, currency: string, return_url: string}|null
     */
    public function state(string $reference): ?array
    {
        $state = Cache::get($this->key($reference));

        if (! is_array($state)) {
            return null;
        }

        foreach (['status', 'amount', 'currency', 'return_url'] as $field) {
            if (! is_string($state[$field] ?? null)) {
                return null;
            }
        }

        return [
            'status' => $state['status'],
            'amount' => $state['amount'],
            'currency' => $state['currency'],
            'return_url' => $state['return_url'],
        ];
    }

    private function secret(): string
    {
        return (string) config('payments.gateways.fake.webhook_secret');
    }

    private function key(string $reference): string
    {
        return "fake-gateway:{$reference}";
    }
}
