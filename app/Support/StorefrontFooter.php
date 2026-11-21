<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the footer shows comes from the admin panel: contact details and
 * social links from Settings, links from Pages, and the busiest categories.
 */
class StorefrontFooter
{
    /** Admin edits can take this long to reach the footer. */
    private const int CACHE_SECONDS = 60;

    /**
     * @return array<string, mixed>
     */
    public static function for(string $locale): array
    {
        return Cache::remember("store.footer.{$locale}", self::CACHE_SECONDS, function () use ($locale): array {
            $settings = DB::table('site_settings')->where('id', 1)->first();
            // Settings first; until they are filled in, STORE_PHONE and STORE_WHATSAPP.
            $phone = trim((string) ($settings->phone ?? '')) ?: (string) config('store.phone');
            $whatsapp = preg_replace('/\D/', '', (string) ($settings->whatsapp ?? '') ?: (string) config('store.whatsapp'));

            return [
                'phone' => $phone !== '' ? $phone : null,
                // For tel: links, which must not hold spaces or dashes.
                'phone_link' => $phone !== '' ? preg_replace('/[^\d+]/', '', $phone) : null,
                'whatsapp' => $whatsapp !== '' ? 'https://wa.me/'.$whatsapp : null,
                // The SIM plans link shows only when there are plans to see.
                'has_sim_plans' => DB::table('sim_plans')->where('is_active', true)->exists(),
                'facebook' => $settings->facebook_url ?? null,
                'instagram' => $settings->instagram_url ?? null,
                'address' => ProductCardData::localized($settings->address ?? null, $locale),
                'pages' => DB::table('pages')
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->get(['slug', 'title'])
                    ->map(fn (object $page): array => [
                        'slug' => $page->slug,
                        'title' => ProductCardData::localized($page->title, $locale),
                    ])
                    ->filter(fn (array $page): bool => $page['title'] !== null)
                    ->values()
                    ->all(),
                'categories' => Category::query()
                    ->where('is_active', true)
                    ->whereHas('products', fn ($query) => $query->where('is_active', true))
                    ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
                    ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
                    ->orderByDesc('products_count')
                    ->limit(4)
                    ->get()
                    ->map(fn (Category $category): array => [
                        'name' => $category->translations->first()?->name,
                        'slug' => $category->translations->first()?->slug,
                    ])
                    ->filter(fn (array $category): bool => $category['slug'] !== null)
                    ->values()
                    ->all(),
            ];
        });
    }
}
