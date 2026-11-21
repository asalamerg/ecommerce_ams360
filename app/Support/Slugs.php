<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds URL slugs (lowercase English letters, digits and dashes) for catalog
 * forms when the slug field is left empty.
 *
 * Every language gets the same slug, made from the English name when there is
 * one, since that reads best in a link. Otherwise the Arabic name is
 * transliterated; Hebrew has no transliteration, so it comes last. A number is
 * added when the slug is already taken in that language.
 */
class Slugs
{
    private const int MAX_LENGTH = 170;

    /**
     * @param  array<string, array<string, mixed>>  $translations  Locale to form fields; empty slugs are filled in.
     * @param  string  $table  The translations table, for example product_translations.
     * @param  string  $owner  Its foreign key column, for example product_id.
     * @return array<string, array<string, mixed>>
     */
    public static function fill(array $translations, string $table, string $owner, ?int $ownerId, string $fallback): array
    {
        $base = self::base($translations, $fallback);

        foreach ($translations as $locale => $translation) {
            if (filled($translation['slug'] ?? null)) {
                continue;
            }

            $current = $ownerId === null ? null : DB::table($table)
                ->where($owner, $ownerId)
                ->where('locale', $locale)
                ->value('slug');

            // Links already shared keep working: an existing slug is never
            // replaced just because the field was cleared.
            $translations[$locale]['slug'] = is_string($current) && $current !== ''
                ? $current
                : self::unique($base, $table, $owner, $ownerId, $locale);
        }

        return $translations;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    private static function base(array $translations, string $fallback): string
    {
        foreach (['en', 'ar', 'he'] as $locale) {
            $slug = Str::slug((string) ($translations[$locale]['name'] ?? ''));

            if ($slug !== '') {
                return rtrim(Str::limit($slug, self::MAX_LENGTH, ''), '-');
            }
        }

        return $fallback;
    }

    private static function unique(string $base, string $table, string $owner, ?int $ownerId, string $locale): string
    {
        $slug = $base;
        $number = 2;

        while (DB::table($table)
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->when($ownerId !== null, fn ($query) => $query->where($owner, '!=', $ownerId))
            ->exists()) {
            $slug = "{$base}-{$number}";
            $number++;
        }

        return $slug;
    }
}
