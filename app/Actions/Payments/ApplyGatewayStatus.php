<?php

namespace App\Actions\Payments;

use App\Actions\Orders\ApplyOrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Notifications\OrderStatusUpdated;
use App\Payments\GatewayStatus;
use App\Services\StaffAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The one place a provider's answer changes a payment and its order, whether
 * it arrived as a notification, on the customer's return, or from the expiry
 * check. Safe to run any number of times with the same answer.
 *
 * A payment counts as paid only when the provider reports exactly the amount
 * and currency the attempt was opened with, for this payment's own reference.
 * Anything else puts it in "review" and alerts the owners; the order is not
 * marked paid. A payment that arrives for an order already cancelled (it
 * expired first) is also put in review, so someone refunds it.
 */
class ApplyGatewayStatus
{
    public function __construct(private ApplyOrderStatus $orderStatus, private StaffAlerts $alerts)
    {
        //
    }

    public function execute(Payment $payment, GatewayStatus $answer, string $source): string
    {
        $after = [];

        $result = DB::transaction(function () use ($payment, $answer, $source, &$after): string {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);

            if ($answer->reference !== null && $payment->provider_reference !== null
                && ! hash_equals($payment->provider_reference, $answer->reference)) {
                return $this->review($payment, $order, 'reference mismatch', $after);
            }

            return match ($answer->status) {
                GatewayStatus::PAID => $this->paid($payment, $order, $answer, $source, $after),
                GatewayStatus::FAILED, GatewayStatus::CANCELLED => $this->failed($payment, $order, $answer),
                GatewayStatus::REFUNDED => $this->refunded($payment, $order, $after),
                default => 'pending',
            };
        });

        foreach ($after as $callback) {
            $callback();
        }

        return $result;
    }

    /**
     * @param  list<callable>  $after
     */
    private function paid(Payment $payment, Order $order, GatewayStatus $answer, string $source, array &$after): string
    {
        if ($payment->status === Payment::PAID) {
            return 'already paid';
        }

        if (in_array($payment->status, [Payment::REFUNDED, Payment::REVIEW], true)) {
            return 'ignored';
        }

        $amountMatches = $answer->amount !== null && bccomp($answer->amount, (string) $payment->amount, 2) === 0;
        $currencyMatches = $answer->currency !== null && strtoupper($answer->currency) === $payment->currency;

        if (! $amountMatches || ! $currencyMatches) {
            return $this->review($payment, $order, 'amount or currency mismatch', $after);
        }

        if ($order->status === 'cancelled' || $order->payment_status === 'paid') {
            return $this->review($payment, $order, $order->status === 'cancelled' ? 'paid after the order was cancelled' : 'order already paid by another attempt', $after);
        }

        $payment->update(['status' => Payment::PAID, 'paid_at' => now(), 'failure_reason' => null]);

        $previous = $order->status;
        $order->update(['payment_status' => 'paid', 'payment_reference' => $payment->provider_reference]);
        OrderEvent::record($order, 'payment', $payment->gateway, 'paid', "via {$source}");

        // Money received is the confirmation: the order goes straight to preparation.
        if ($previous === 'pending') {
            $this->orderStatus->execute($order->id, 'processing', null);
            $order->update(['status' => 'processing']);
            OrderEvent::record($order, OrderEvent::STATUS, 'pending', 'processing', 'Paid online');
        }

        $after[] = function () use ($order): void {
            $this->alerts->send('payment', 'orders', 'Order :reference was paid online: :total ₪', [
                'reference' => $order->reference,
                'total' => StaffAlerts::money((string) $order->total),
            ], StaffAlerts::orderUrl($order->id));

            if (filled($order->email)) {
                Notification::route('mail', $order->email)->notify(new OrderStatusUpdated($order->fresh()));
            }
        };

        return 'paid';
    }

    private function failed(Payment $payment, Order $order, GatewayStatus $answer): string
    {
        if (! $payment->isOpen()) {
            return 'ignored';
        }

        $payment->update(['status' => Payment::FAILED, 'failure_reason' => mb_substr((string) ($answer->reason ?? $answer->status), 0, 250)]);

        if ($order->payment_status !== 'paid') {
            $order->update(['payment_status' => 'failed']);
            OrderEvent::record($order, 'payment', $payment->gateway, 'failed');
        }

        return 'failed';
    }

    /**
     * @param  list<callable>  $after
     */
    private function refunded(Payment $payment, Order $order, array &$after): string
    {
        if ($payment->status !== Payment::PAID) {
            return $payment->status === Payment::REFUNDED ? 'already refunded' : 'ignored';
        }

        $payment->update(['status' => Payment::REFUNDED, 'refunded_at' => now()]);
        $order->update(['payment_status' => 'refunded']);
        OrderEvent::record($order, 'payment', $payment->gateway, 'refunded');

        $after[] = fn () => $this->alerts->send('payment', 'admin', 'Order :reference was refunded', [
            'reference' => $order->reference,
        ], StaffAlerts::orderUrl($order->id));

        return 'refunded';
    }

    /**
     * @param  list<callable>  $after
     */
    private function review(Payment $payment, Order $order, string $reason, array &$after): string
    {
        $payment->update(['status' => Payment::REVIEW, 'failure_reason' => $reason]);
        OrderEvent::record($order, OrderEvent::NOTE, note: "Payment needs review: {$reason}");

        $after[] = fn () => $this->alerts->send('payment_review', 'admin', 'Payment for order :reference needs review: :reason', [
            'reference' => $order->reference,
            'reason' => $reason,
        ], StaffAlerts::orderUrl($order->id));

        return 'review';
    }
}
