<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Builds the wa.me link that hands an order to the store, in the customer's
 * language. It is built from the saved order, so it stays complete after the cart
 * has been emptied.
 */
class WhatsAppOrderMessage
{
    public static function url(Order $order): string
    {
        $number = DB::table('site_settings')->where('id', 1)->value('whatsapp') ?: config('store.whatsapp');
        $digits = (string) preg_replace('/\D/', '', (string) $number);

        return "https://wa.me/{$digits}?text=".rawurlencode(self::text($order));
    }

    public static function text(Order $order): string
    {
        $locale = $order->locale;
        $line = fn (string $key): string => (string) trans("ui.checkout.{$key}", [], $locale);
        $hasEstimate = $order->items->contains('price_is_estimate', true);
        $mark = $hasEstimate ? '≈ ' : '';

        $parts = [
            $line('whatsapp_intro'),
            ...$order->items->map(fn (OrderItem $item): string => sprintf(
                '- %s x%d: %s%s ₪%s',
                ($item->name[$locale] ?? reset($item->name)).($item->color_name ? " ({$item->color_name})" : ''),
                $item->quantity,
                $item->price_is_estimate ? '≈ ' : '',
                self::money($item->lineTotal()),
                $item->price_is_estimate ? ' ('.trans('ui.store.availability_confirm', [], $locale).')' : '',
            ))->all(),
            sprintf('%s: %s%s ₪', $line('subtotal'), $mark, self::money($order->subtotal)),
        ];

        if ((float) $order->discount_total > 0) {
            $parts[] = sprintf('%s: -%s ₪', $line('discount'), self::money($order->discount_total));
        }

        if ($order->points_used > 0) {
            $parts[] = sprintf(
                '%s: -%s ₪',
                trans('ui.points.discount', ['points' => $order->points_used], $locale),
                self::money($order->points_discount),
            );
        }

        if ($order->delivery_label !== null) {
            $parts[] = sprintf(
                '%s: %s ₪',
                trans($order->delivery_method === 'pickup' ? 'ui.delivery.pickup_at' : 'ui.delivery.delivery_to', ['place' => $order->delivery_label], $locale),
                self::money((string) $order->shipping_fee),
            );
        }

        $parts[] = sprintf('%s: %s%s ₪', $line('total'), $mark, self::money($order->total));
        $parts[] = sprintf('%s: %s', $line('reference'), $order->reference);
        $parts[] = sprintf('%s: %s', $line('customer_name'), $order->customer_name);
        $parts[] = sprintf('%s: %s', $line('customer_phone'), $order->phone);

        if (filled($order->shipping_address['address'] ?? null)) {
            $parts[] = sprintf('%s: %s', $line('customer_address'), $order->shipping_address['address']);
        }

        if (filled($order->notes)) {
            $parts[] = sprintf('%s: %s', $line('customer_notes_label'), $order->notes);
        }

        return implode("\n", $parts);
    }

    private static function money(string $amount): string
    {
        $formatted = number_format((float) $amount, 2);

        return str_ends_with($formatted, '.00') ? substr($formatted, 0, -3) : $formatted;
    }
}
