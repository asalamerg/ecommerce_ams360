<?php

namespace App\Http\Controllers;

use App\Actions\Payments\ApplyGatewayStatus;
use App\Actions\Payments\HandlePaymentWebhook;
use App\Actions\Payments\StartPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Gateways\FakeGateway;
use App\Payments\GatewayStatus;
use App\Payments\PaymentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    /**
     * The customer is back from the provider's page. Nothing in this request
     * is believed: the payment is found by its id and secret token, and its
     * status is asked of the provider directly.
     */
    public function return(Request $request, int $payment, string $token, PaymentManager $payments, ApplyGatewayStatus $apply): RedirectResponse
    {
        $attempt = Payment::query()->with('order')->find($payment);

        abort_unless($attempt !== null && hash_equals($attempt->return_token_hash, hash('sha256', $token)), 404);

        $order = $attempt->order;

        if (! in_array($order->reference, (array) $request->session()->get('placed_orders', []), true)) {
            $request->session()->push('placed_orders', $order->reference);
        }

        if ($attempt->isOpen() && $payments->usable($attempt->gateway)) {
            $apply->execute($attempt, $payments->gateway($attempt->gateway)->status($attempt), 'return');
        }

        return to_route('storefront.orders.show', ['locale' => $order->locale, 'reference' => $order->reference]);
    }

    public function webhook(Request $request, string $gateway, HandlePaymentWebhook $handle): HttpResponse
    {
        $outcome = $handle->execute($gateway, $request);

        // Providers only need the status code; the body helps when reading their logs.
        return response($outcome['result'], $outcome['status'])->header('Content-Type', 'text/plain');
    }

    /**
     * Pay again after a failed or abandoned attempt, from the order page. Only
     * whoever may see the order (its account or the browser that placed it).
     */
    public function pay(Request $request, string $locale, string $reference, StartPayment $start): SymfonyResponse
    {
        $order = Order::query()->where('reference', $reference)->firstOrFail();
        $user = $request->user();

        abort_unless(
            ($user !== null && $order->user_id === $user->id)
                || in_array($reference, (array) $request->session()->get('placed_orders', []), true),
            404,
        );

        return Inertia::location($start->execute($order));
    }

    /**
     * The test provider's payment page. Exists only where the fake gateway is
     * usable, which is never production, and only through a signed link.
     */
    public function fakeShow(Request $request, string $reference, FakeGateway $gateway, PaymentManager $payments): Response
    {
        abort_unless($payments->usable('fake'), 404);
        $state = $gateway->state($reference);
        abort_unless($state !== null, 404);

        return Inertia::render('payments/fake', [
            'reference' => $reference,
            'amount' => $state['amount'],
            'currency' => $state['currency'],
            'decideUrl' => url()->signedRoute('payments.fake.decide', ['reference' => $reference]),
        ]);
    }

    public function fakeDecide(Request $request, string $reference, FakeGateway $gateway, PaymentManager $payments, HandlePaymentWebhook $handle): SymfonyResponse
    {
        abort_unless($payments->usable('fake'), 404);
        $data = $request->validate(['decision' => ['required', 'in:paid,failed']]);

        $notice = $gateway->decide($reference, $data['decision'] === 'paid' ? GatewayStatus::PAID : GatewayStatus::FAILED);
        abort_unless($notice !== null, 404);

        // Delivered exactly as a real provider would: a signed request to the webhook.
        $webhook = Request::create(route('payments.webhook', ['gateway' => 'fake']), 'POST', server: [
            'HTTP_X_FAKE_SIGNATURE' => $notice['signature'],
            'CONTENT_TYPE' => 'application/json',
        ], content: $notice['body']);
        $handle->execute('fake', $webhook);

        return Inertia::location($notice['return_url']);
    }
}
