<?php

namespace App\Support;

/**
 * Folds text so a search matches however it was typed: hamza and taa marbuta
 * variants, diacritics, Hebrew final letters, Arabic digits, and common plurals.
 * Indexed text and search terms go through the same steps.
 */
class SearchText
{
    private const array FOLD = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        'ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Words shoppers use interchangeably. Written in folded form. */
    private const array SYNONYMS = [
        ['كيبل', 'كابل', 'cable'],
        ['ماوس', 'فاره', 'فارات', 'mouse'],
        ['شاحن', 'شواحن', 'charger'],
        ['سماع', 'headphone', 'headset', 'earphone'],
        ['جوال', 'موبايل', 'هاتف', 'تلفون', 'phone'],
        ['كيبورد', 'لوحه', 'keyboard'],
    ];

    private const int SHORT_WORD = 4;

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[\x{0591}-\x{05C7}\x{0610}-\x{061A}\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = strtr($text, self::FOLD);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Splits a query into words, each with the spellings that may match it. Every
     * word must match, so adding a word narrows the results.
     *
     * @return list<list<string>>
     */
    public static function terms(string $query): array
    {
        $words = array_filter(explode(' ', self::normalize($query)), fn (string $word): bool => $word !== '');

        return array_map(function (string $word): array {
            $stem = self::stem($word);

            foreach (self::SYNONYMS as $group) {
                if (in_array($stem, $group, true) || in_array($word, $group, true)) {
                    return $group;
                }
            }

            return [$stem];
        }, array_slice(array_values(array_unique($words)), 0, 6));
    }

    /**
     * The LIKE pattern for a spelling. Short synonyms match from the start of a word
     * only, so "فاره" (mouse) does not match inside "صفاره" (siren). Everything else
     * matches anywhere, which also catches attached prefixes such as "الـ".
     */
    public static function pattern(string $spelling): string
    {
        $synonym = in_array($spelling, array_merge(...self::SYNONYMS), true);

        return $synonym && mb_strlen($spelling) <= self::SHORT_WORD ? "% {$spelling}%" : "%{$spelling}%";
    }

    private static function stem(string $word): string
    {
        if (mb_strlen($word) >= 5 && str_starts_with($word, 'ال')) {
            $word = mb_substr($word, 2);
        }

        if (mb_strlen($word) >= 5) {
            return (string) preg_replace('/(ات|ين|ون|ه)$/u', '', $word);
        }

        return $word;
    }
}
