<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The delivery and returns texts from the admin's Store settings, shown on the
 * product page and the contact page.
 */
class StorePolicies
{
    /** Admin edits can take this long to show, like the footer. */
    private const int CACHE_SECONDS = 60;

    /**
     * @return array{delivery: string|null, returns: string|null}
     */
    public static function for(string $locale): array
    {
        return Cache::remember("store.policies.{$locale}", self::CACHE_SECONDS, function () use ($locale): array {
            $settings = DB::table('site_settings')->where('id', 1)->first(['delivery_info', 'return_policy']);

            return [
                'delivery' => ProductCardData::localized($settings->delivery_info ?? null, $locale),
                'returns' => ProductCardData::localized($settings->return_policy ?? null, $locale),
            ];
        });
    }
}
