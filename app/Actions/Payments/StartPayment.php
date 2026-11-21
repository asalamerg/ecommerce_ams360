<?php

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Opens a payment attempt for an order and returns the provider page to send
 * the customer to. The amount is the order total as saved, and the redirect
 * must point at the provider itself, so a misconfigured or tampered answer
 * cannot send customers somewhere else.
 */
class StartPayment
{
    public function __construct(private PaymentManager $payments)
    {
        //
    }

    public function execute(Order $order): string
    {
        $gateway = $this->payments->default();
        $token = Str::random(48);

        $payment = DB::transaction(function () use ($order, $gateway, $token): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== 'pending' || in_array($locked->payment_status, ['paid', 'refunded'], true) || $locked->paymentIsManual()) {
                throw ValidationException::withMessages(['payment' => __('ui.payments.not_payable')]);
            }

            // One open attempt at a time: an older one still open is abandoned.
            Payment::query()->where('order_id', $locked->id)
                ->whereIn('status', [Payment::CREATED, Payment::PENDING])
                ->update(['status' => Payment::CANCELLED, 'failure_reason' => 'replaced by a new attempt']);

            $locked->update(['payment_status' => 'pending']);

            return Payment::query()->create([
                'order_id' => $locked->id,
                'gateway' => $gateway->name(),
                'status' => Payment::CREATED,
                'amount' => $locked->total,
                'currency' => 'ILS',
                'idempotency_key' => (string) Str::uuid(),
                'return_token_hash' => hash('sha256', $token),
            ]);
        });

        // The provider is called outside the transaction, so a slow answer holds no locks.
        try {
            $session = $gateway->start(
                $payment,
                $order,
                route('payments.return', ['payment' => $payment->id, 'token' => $token]),
                route('payments.webhook', ['gateway' => $gateway->name()]),
            );

            $this->assertProviderUrl($session->redirectUrl, $gateway->browserOrigins());
        } catch (Throwable $exception) {
            $payment->update(['status' => Payment::FAILED, 'failure_reason' => 'could not start']);
            report($exception);

            throw ValidationException::withMessages(['payment' => __('ui.payments.start_failed')]);
        }

        $payment->update(['status' => Payment::PENDING, 'provider_reference' => $session->reference]);

        return $session->redirectUrl;
    }

    /**
     * @param  list<string>  $origins
     */
    private function assertProviderUrl(string $url, array $origins): void
    {
        $parts = parse_url($url);
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;
        $own = rtrim(url('/'), '/');

        // The test gateway's page lives on this site; real ones must be listed.
        $allowed = in_array($origin, $origins, true)
            || ($origins === [] && str_starts_with($url, $own.'/'));
        $secure = ($parts['scheme'] ?? '') === 'https' || app()->environment('local', 'testing');

        if (! $allowed || ! $secure) {
            throw new RuntimeException('The payment provider returned an unexpected payment page address.');
        }
    }
}
