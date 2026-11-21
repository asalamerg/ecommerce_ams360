<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The loyalty rules set in the admin panel (Loyalty points): how much spending
 * earns one point, what one point is worth, and how much of an order points
 * may pay. All money maths uses bcmath strings, like the rest of checkout.
 *
 * Example with the defaults: 100 ₪ spent = 1 point, 1 point = 1 ₪, so a 1000 ₪
 * order earns 10 points, worth 10 ₪ off a later order.
 */
class Loyalty
{
    /**
     * @return array{is_enabled: bool, spend_per_point: numeric-string, point_value: numeric-string, min_redeem_points: int, max_redeem_percent: int}
     */
    public static function settings(): array
    {
        $row = DB::table('loyalty_settings')->where('id', 1)->first();

        /** @var numeric-string $spend */
        $spend = (string) ($row->spend_per_point ?? '100');
        /** @var numeric-string $value */
        $value = (string) ($row->point_value ?? '1');

        return [
            'is_enabled' => (bool) ($row->is_enabled ?? false),
            'spend_per_point' => $spend,
            'point_value' => $value,
            'min_redeem_points' => (int) ($row->min_redeem_points ?? 0),
            'max_redeem_percent' => max(0, min(100, (int) ($row->max_redeem_percent ?? 0))),
        ];
    }

    public static function enabled(): bool
    {
        $settings = self::settings();

        return $settings['is_enabled'] && bccomp($settings['spend_per_point'], '0', 4) > 0;
    }

    /**
     * Whole points earned by paying $amount. Fractions are dropped.
     *
     * @param  numeric-string  $amount
     */
    public static function pointsFor(string $amount): int
    {
        $settings = self::settings();

        if (! self::enabled() || bccomp($amount, '0', 4) <= 0) {
            return 0;
        }

        return (int) bcdiv($amount, $settings['spend_per_point'], 0);
    }

    /**
     * @return numeric-string
     */
    public static function valueOf(int $points): string
    {
        return bcmul((string) max(0, $points), self::settings()['point_value'], 4);
    }

    /**
     * The most points one order may use: limited by the balance and by the share
     * of the order total that points are allowed to pay.
     *
     * @param  numeric-string  $payable  The total after any coupon.
     */
    public static function maxRedeemable(int $balance, string $payable): int
    {
        $settings = self::settings();

        if (! self::enabled() || bccomp($settings['point_value'], '0', 4) <= 0 || bccomp($payable, '0', 4) <= 0) {
            return 0;
        }

        $allowedMoney = bcdiv(bcmul($payable, (string) $settings['max_redeem_percent'], 4), '100', 4);
        $byShare = (int) bcdiv($allowedMoney, $settings['point_value'], 0);

        return max(0, min($balance, $byShare));
    }

    /**
     * What the cart page needs to show and preview points. Null when the
     * programme is off.
     *
     * @return array<string, mixed>|null
     */
    public static function forCart(?int $balance): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $settings = self::settings();

        return [
            'balance' => $balance,
            'spend_per_point' => $settings['spend_per_point'],
            'point_value' => $settings['point_value'],
            'min_redeem_points' => $settings['min_redeem_points'],
            'max_redeem_percent' => $settings['max_redeem_percent'],
        ];
    }
}
