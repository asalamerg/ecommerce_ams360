<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Rules\TurnstileToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a customer without an account (or on another device) open their order
 * with its number and the phone number it was placed with. Both must match;
 * a wrong pair says nothing about which part was wrong. Failed attempts are
 * limited per visitor, so numbers cannot be guessed.
 */
class TrackOrderController extends Controller
{
    private const int MAX_FAILURES_PER_HOUR = 10;

    public function show(Request $request, string $locale): Response
    {
        return Inertia::render('storefront/track', [
            'reference' => strtoupper(substr((string) $request->query('reference', ''), 0, 12)),
            'turnstileSiteKey' => TurnstileToken::siteKey(),
        ]);
    }

    public function find(Request $request, string $locale): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:12'],
            'phone' => ['required', 'string', 'max:25'],
            ...TurnstileToken::rules($request->ip()),
        ], ['turnstile_token.required' => __('ui.checkout.verification_required')]);

        $key = 'track-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES_PER_HOUR)) {
            throw ValidationException::withMessages(['reference' => __('ui.order_updates.track_locked', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ])]);
        }

        $order = Order::query()->where('reference', strtoupper(trim($data['reference'])))->first();

        if ($order === null || ! self::samePhone($order->phone, $data['phone'])) {
            RateLimiter::hit($key, 3600);

            throw ValidationException::withMessages(['reference' => __('ui.order_updates.track_not_found')]);
        }

        // The same proof the checkout leaves behind, see CheckoutController::show.
        if (! in_array($order->reference, (array) $request->session()->get('placed_orders', []), true)) {
            $request->session()->push('placed_orders', $order->reference);
        }

        return to_route('storefront.orders.show', ['locale' => App::getLocale(), 'reference' => $order->reference]);
    }

    /**
     * Compares the last nine digits, so 050-123-4567 and +972 50 123 4567 match.
     */
    public static function samePhone(string $stored, string $given): bool
    {
        $stored = substr((string) preg_replace('/\D/', '', $stored), -9);
        $given = substr((string) preg_replace('/\D/', '', $given), -9);

        return strlen($given) === 9 && hash_equals($stored, $given);
    }
}
