<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The structured admin module fields: pickers for other rows (relation,
 * relations), opening hours and short lists. The form sends each one as a
 * JSON string; this checks its shape, then stores a clean version.
 */
class ModuleFieldValues
{
    /** Sunday first, as the week runs in Israel. */
    public const array DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public const array STRUCTURED = ['relations', 'hours', 'list'];

    private const int MAX_RELATIONS = 500;

    private const int MAX_LIST_ITEMS = 30;

    /**
     * Choices for a relation picker, labelled in the admin's language. The
     * other languages and the SKU go in the hint, so search finds them too.
     *
     * @return list<array{value: string, label: string, hint?: string}>
     */
    public static function choices(string $table): array
    {
        $locale = app()->getLocale();
        $named = function (int $id, array $names, ?string $extra = null) use ($locale): array {
            $label = $names[$locale] ?? $names['ar'] ?? $names['en'] ?? reset($names) ?: "#{$id}";

            return [
                'value' => (string) $id,
                'label' => (string) $label,
                'hint' => trim(implode(' ', array_filter([...array_values($names), $extra, "#{$id}"]))),
            ];
        };

        return match ($table) {
            'products' => array_values(Product::query()->with('translations')->orderByDesc('id')->get()
                ->map(fn (Product $product): array => $named($product->id, $product->translations->pluck('name', 'locale')->all(), $product->sku))
                ->all()),
            'categories' => array_values(Category::query()->with('translations')->orderBy('position')->get()
                ->map(fn (Category $category): array => $named($category->id, $category->translations->pluck('name', 'locale')->all()))
                ->all()),
            'brands' => array_values(DB::table('brands')->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn (object $brand): array => $named((int) $brand->id, (array) json_decode((string) $brand->name, true)))
                ->all()),
            'branches' => array_values(DB::table('branches')->orderBy('id')->get(['id', 'name'])
                ->map(fn (object $branch): array => $named((int) $branch->id, (array) json_decode((string) $branch->name, true)))
                ->all()),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public static function rule(array $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if ($value === null || $value === '') {
                return;
            }

            $decoded = json_decode((string) $value, true);
            $error = is_array($decoded) ? match ($field['type']) {
                'relations' => self::relationsError($decoded, (string) $field['relation']),
                'hours' => self::hoursError($decoded),
                'list' => self::listError($decoded),
                default => null,
            } : self::text('The supplied JSON data is invalid.');

            if ($error !== null) {
                $fail($error);
            }
        };
    }

    /**
     * The value to store: a compact JSON string, or null when empty.
     *
     * @param  array<string, mixed>  $field
     */
    public static function store(array $field, mixed $value): ?string
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        $clean = match ($field['type']) {
            'relations' => array_values(array_unique(array_map('intval', $decoded))),
            'hours' => self::cleanHours($decoded),
            'list' => array_values(array_filter(array_map(fn (mixed $line): string => trim((string) $line), $decoded), fn (string $line): bool => $line !== '')),
            default => $decoded,
        };

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $ids
     */
    private static function relationsError(array $ids, string $table): ?string
    {
        if (! array_is_list($ids) || count($ids) > self::MAX_RELATIONS) {
            return self::text('Choose at most :count items.', ['count' => self::MAX_RELATIONS]);
        }

        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                return self::text('The supplied JSON data is invalid.');
            }
        }

        $unique = array_unique(array_map('intval', $ids));

        return DB::table($table)->whereIn('id', $unique)->count() === count($unique)
            ? null
            : self::text('Some of the chosen items no longer exist.');
    }

    /**
     * @param  array<mixed>  $hours
     */
    private static function hoursError(array $hours): ?string
    {
        foreach ($hours as $day => $times) {
            if (! in_array($day, self::DAYS, true) || ! is_array($times)) {
                return self::text('The supplied JSON data is invalid.');
            }

            if (($times['closed'] ?? false) === true) {
                continue;
            }

            $open = $times['open'] ?? null;
            $close = $times['close'] ?? null;

            if (! is_string($open) || ! is_string($close)
                || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $open) !== 1
                || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $close) !== 1) {
                return self::text('Enter opening and closing times as HH:MM, or mark the day closed.');
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $lines
     */
    private static function listError(array $lines): ?string
    {
        if (! array_is_list($lines) || count($lines) > self::MAX_LIST_ITEMS) {
            return self::text('Add at most :count lines.', ['count' => self::MAX_LIST_ITEMS]);
        }

        foreach ($lines as $line) {
            if (! is_string($line) || mb_strlen($line) > 200) {
                return self::text('Each line can be at most 200 characters.');
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /**
     * @param  array<mixed>  $hours
     * @return array<string, array{closed: bool, open?: string, close?: string}>
     */
    private static function cleanHours(array $hours): array
    {
        $clean = [];

        foreach (self::DAYS as $day) {
            $times = $hours[$day] ?? null;

            if (! is_array($times)) {
                continue;
            }

            $clean[$day] = ($times['closed'] ?? false) === true
                ? ['closed' => true]
                : ['closed' => false, 'open' => (string) $times['open'], 'close' => (string) $times['close']];
        }

        return $clean;
    }
}
