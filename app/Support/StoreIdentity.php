<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The shop's own name and logo, as entered in the admin panel's store settings.
 * Until a name is entered there, APP_NAME stands in; without a logo the name is
 * written out instead.
 */
class StoreIdentity
{
    /**
     * @return array{name: string, logo: string|null}
     */
    public static function for(string $locale): array
    {
        return StorefrontCache::remember("identity.{$locale}", function () use ($locale): array {
            try {
                $settings = DB::table('site_settings')->where('id', 1)->first(['site_name', 'logo_url']);
            } catch (Throwable) {
                // Before the first migration there is no settings table yet.
                $settings = null;
            }

            $name = ProductCardData::localized($settings->site_name ?? null, $locale);
            $logo = trim((string) ($settings->logo_url ?? ''));

            return [
                'name' => filled($name) ? (string) $name : (string) config('app.name'),
                'logo' => $logo !== '' ? $logo : null,
            ];
        });
    }
}
