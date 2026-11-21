<?php

namespace App\Actions\Payments;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\User;
use App\Payments\GatewayStatus;
use App\Payments\PaymentManager;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A full refund through the provider, started by an owner or administrator.
 * The order is marked refunded only when the provider confirms it.
 */
class RefundPayment
{
    public function __construct(private PaymentManager $payments, private ApplyGatewayStatus $apply)
    {
        //
    }

    public function execute(Order $order, User $actor): string
    {
        $payment = Payment::query()->where('order_id', $order->id)->where('status', Payment::PAID)->latest('id')->first();

        if ($payment === null || ! $this->payments->usable($payment->gateway)) {
            throw ValidationException::withMessages(['refund' => __('There is no online payment to refund for this order.')]);
        }

        try {
            $answer = $this->payments->gateway($payment->gateway)->refund($payment);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['refund' => __('The payment provider did not accept the refund. Nothing was changed.')]);
        }

        OrderEvent::record($order, OrderEvent::NOTE, note: 'Refund requested', actor: $actor);

        if ($answer->status !== GatewayStatus::REFUNDED) {
            return 'pending';
        }

        return $this->apply->execute($payment, $answer, 'refund');
    }
}
