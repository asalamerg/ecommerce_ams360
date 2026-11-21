<?php

namespace App\Support;

use App\Models\Order;

/**
 * A wa.me link from the store to the customer, with a status message in the
 * language they ordered in. Staff press send in WhatsApp themselves; nothing is
 * sent automatically.
 */
class CustomerWhatsApp
{
    /**
     * The number in international form without "+", or null when it cannot be
     * one. Local numbers starting with 0 are taken as Israeli (+972).
     */
    public static function number(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '972'.substr($digits, 1);
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    public static function url(Order $order): ?string
    {
        $number = self::number($order->phone);

        return $number === null ? null : "https://wa.me/{$number}?text=".rawurlencode(self::text($order));
    }

    public static function text(Order $order): string
    {
        $locale = $order->locale;
        $status = in_array($order->status, ['processing', 'ready_for_pickup', 'shipped', 'completed', 'cancelled'], true)
            ? $order->status
            : 'pending';

        return implode("\n", [
            trans('ui.order_updates.greeting', ['name' => $order->customer_name], $locale),
            trans("ui.order_updates.{$status}", [], $locale),
            trans('ui.checkout.reference', [], $locale).': '.$order->reference,
            url("/{$locale}/track?reference={$order->reference}"),
        ]);
    }
}
