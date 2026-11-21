<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use stdClass;

class CouponDiscount
{
    public static function find(string $code): ?stdClass
    {
        return DB::table('coupons')->where('code', mb_strtoupper(trim($code)))->first();
    }

    public static function usable(?stdClass $coupon): bool
    {
        return $coupon !== null
            && (bool) $coupon->is_active
            && ($coupon->expires_at === null || now()->lessThan($coupon->expires_at))
            && ($coupon->max_uses === null || $coupon->used_count < $coupon->max_uses);
    }

    /**
     * Never more than the subtotal, however the coupon was configured, so an
     * order can never go below zero.
     *
     * @param  numeric-string  $subtotal
     * @return numeric-string
     */
    public static function amount(stdClass $coupon, string $subtotal): string
    {
        /** @var numeric-string $value */
        $value = (string) $coupon->discount_value;

        $discount = $coupon->discount_type === 'percentage'
            ? bcdiv(bcmul($subtotal, bccomp($value, '100', 4) > 0 ? '100' : $value, 4), '100', 4)
            : bcadd($value, '0', 4);

        return bccomp($discount, $subtotal, 4) > 0 ? $subtotal : $discount;
    }
}
