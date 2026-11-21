<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How an order reaches the customer: delivery to a zone with its fee, or
 * collection from a branch for free. The fee is always worked out here from
 * the zone as saved, never taken from the browser.
 *
 * With no active zones, delivery is a free-text address with no fee, as before
 * zones existed.
 */
class Delivery
{
    public const string DELIVERY = 'delivery';

    public const string PICKUP = 'pickup';

    /**
     * @return list<array{id: int, name: string, fee: numeric-string, free_over: numeric-string|null, estimate: string|null}>
     */
    public static function zones(string $locale): array
    {
        $zones = [];

        foreach (DB::table('shipping_zones')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $zone) {
            $zones[] = [
                'id' => (int) $zone->id,
                'name' => ProductCardData::localized($zone->name, $locale) ?? "#{$zone->id}",
                // The same four decimals from MySQL and SQLite.
                'fee' => self::decimal($zone->fee),
                'free_over' => $zone->free_over === null ? null : self::decimal($zone->free_over),
                'estimate' => ProductCardData::localized($zone->estimate, $locale),
            ];
        }

        return $zones;
    }

    /**
     * @return list<array{id: int, name: string, address: string|null, phone: string|null}>
     */
    public static function pickupBranches(string $locale): array
    {
        return array_values(DB::table('branches')
            ->where('is_active', true)
            ->where('allows_pickup', true)
            ->orderBy('id')
            ->get()
            ->map(fn (object $branch): array => [
                'id' => (int) $branch->id,
                'name' => ProductCardData::localized($branch->name, $locale) ?? "#{$branch->id}",
                'address' => ProductCardData::localized($branch->address, $locale),
                'phone' => $branch->phone,
            ])
            ->all());
    }

    /**
     * @param  array<string, mixed>  $details  The validated checkout form.
     * @param  numeric-string  $goods  What the items cost after the coupon.
     * @return array{delivery_method: string, shipping_zone_id: int|null, branch_id: int|null, delivery_label: string|null, shipping_fee: numeric-string}
     */
    public static function resolve(array $details, string $goods, string $locale): array
    {
        $method = ($details['delivery_method'] ?? self::DELIVERY) === self::PICKUP ? self::PICKUP : self::DELIVERY;

        if ($method === self::PICKUP) {
            $branch = collect(self::pickupBranches($locale))->firstWhere('id', (int) ($details['branch_id'] ?? 0));

            if ($branch === null) {
                throw ValidationException::withMessages(['branch_id' => __('ui.delivery.choose_branch')]);
            }

            return ['delivery_method' => $method, 'shipping_zone_id' => null, 'branch_id' => $branch['id'], 'delivery_label' => $branch['name'], 'shipping_fee' => '0'];
        }

        $zones = self::zones($locale);

        if ($zones === []) {
            return ['delivery_method' => $method, 'shipping_zone_id' => null, 'branch_id' => null, 'delivery_label' => null, 'shipping_fee' => '0'];
        }

        $chosen = (int) ($details['shipping_zone_id'] ?? 0);
        $zone = array_find($zones, fn (array $candidate): bool => $candidate['id'] === $chosen);

        if ($zone === null) {
            throw ValidationException::withMessages(['shipping_zone_id' => __('ui.delivery.choose_zone')]);
        }

        if (blank($details['address'] ?? null)) {
            throw ValidationException::withMessages(['address' => __('ui.delivery.address_required')]);
        }

        return [
            'delivery_method' => $method,
            'shipping_zone_id' => $zone['id'],
            'branch_id' => null,
            'delivery_label' => $zone['name'],
            'shipping_fee' => self::fee($zone, $goods),
        ];
    }

    /**
     * @param  array{fee: numeric-string, free_over: numeric-string|null}  $zone
     * @param  numeric-string  $goods
     * @return numeric-string
     */
    public static function fee(array $zone, string $goods): string
    {
        if ($zone['free_over'] !== null && bccomp($goods, $zone['free_over'], 4) >= 0) {
            return '0';
        }

        return bcadd($zone['fee'], '0', 4);
    }

    /**
     * @return numeric-string
     */
    private static function decimal(mixed $value): string
    {
        $value = (string) $value;

        return is_numeric($value) ? bcadd($value, '0', 4) : '0.0000';
    }
}
