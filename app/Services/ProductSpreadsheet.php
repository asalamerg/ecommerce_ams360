<?php

namespace App\Services;

use App\Actions\Inventory\AdjustProductStock;
use App\Models\CatalogActivity;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Products in and out of a spreadsheet (CSV, opened by Excel or Google Sheets).
 *
 * The export can be edited and imported back to change prices, visibility and
 * stock for many products at once. An import is all or nothing: if any row is
 * wrong, nothing is changed and every problem is listed. Stock changes go
 * through the normal stock adjustment, so each one is in the stock history.
 */
class ProductSpreadsheet
{
    public const array COLUMNS = ['id', 'sku', 'name_ar', 'name_en', 'name_he', 'category', 'price', 'price_is_estimate', 'stock', 'is_active'];

    public const int MAX_ROWS = 5000;

    public function __construct(private AdjustProductStock $stock)
    {
        //
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            // Excel needs the byte order mark to read Arabic and Hebrew as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::COLUMNS, escape: '');

            Product::query()->with(['translations', 'category.translations'])->orderBy('id')->chunk(500, function (Collection $products) use ($out): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $names = $product->translations->pluck('name', 'locale');
                    $category = $product->category?->translations->pluck('name', 'locale');

                    fputcsv($out, array_map(self::safeCell(...), [
                        $product->id,
                        $product->sku,
                        $names->get('ar'),
                        $names->get('en'),
                        $names->get('he'),
                        $category?->get('ar') ?? $category?->get('en'),
                        rtrim(rtrim((string) $product->price, '0'), '.'),
                        $product->price_is_estimate ? 1 : 0,
                        $product->stock_quantity,
                        $product->is_active ? 1 : 0,
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{updated: int, errors: list<string>}
     */
    public function import(UploadedFile $file, User $actor): array
    {
        [$header, $rows] = $this->read($file);
        $errors = [];

        if (! in_array('id', $header, true) && ! in_array('sku', $header, true)) {
            return ['updated' => 0, 'errors' => [self::text('The file needs an "id" or "sku" column.')]];
        }

        if (count($rows) > self::MAX_ROWS) {
            return ['updated' => 0, 'errors' => [self::text('A file can have at most :count rows.', ['count' => self::MAX_ROWS])]];
        }

        $changes = [];

        foreach ($rows as $index => $cells) {
            $line = $index + 2;
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $product = $this->find($row);

            if ($product === null) {
                $errors[] = self::text('Row :line: no product with this id or SKU.', ['line' => $line]);

                continue;
            }

            $change = $this->rowChanges($row, $product, $actor, $line, $errors);

            if ($change !== []) {
                $changes[] = [$product, $change];
            }
        }

        if ($errors !== []) {
            return ['updated' => 0, 'errors' => array_slice($errors, 0, 50)];
        }

        DB::transaction(function () use ($changes, $actor): void {
            foreach ($changes as [$product, $change]) {
                $before = $product->only(['price', 'price_is_estimate', 'is_active', 'stock_quantity']);

                if (isset($change['stock'])) {
                    $delta = (int) $change['stock'] - $product->stock_quantity;
                    $this->stock->execute($product, $actor, $delta > 0 ? 'increase' : 'decrease', $delta > 0 ? $delta : -$delta, 'Spreadsheet import');
                    unset($change['stock']);
                }

                if ($change !== []) {
                    $product->update($change);
                }

                CatalogActivity::query()->create([
                    'user_id' => $actor->id,
                    'action' => 'product.imported',
                    'subject_type' => Product::class,
                    'subject_id' => $product->id,
                    'changes' => ['before' => $before, 'after' => $product->fresh()?->only(['price', 'price_is_estimate', 'is_active', 'stock_quantity']) ?? []],
                    'created_at' => now(),
                ]);
            }
        });

        return ['updated' => count($changes), 'errors' => []];
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /**
     * Cells starting with = + - @ are formulas to a spreadsheet. A product name
     * typed as "=HYPERLINK(...)" must stay text when staff open the file.
     */
    public static function safeCell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function read(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $first = (string) array_shift($lines);
        // Excel in many locales saves with semicolons.
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $header = array_map(fn (?string $cell): string => strtolower(trim((string) $cell)), str_getcsv($first, $delimiter, escape: ''));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $rows[] = array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, escape: ''));
            }
        }

        return [$header, $rows];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function find(array $row): ?Product
    {
        if (ctype_digit($row['id'] ?? '')) {
            return Product::query()->find((int) $row['id']);
        }

        return filled($row['sku'] ?? null) ? Product::query()->where('sku', $row['sku'])->first() : null;
    }

    /**
     * Only filled cells change anything; an empty cell keeps the current value.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function rowChanges(array $row, Product $product, User $actor, int $line, array &$errors): array
    {
        $change = [];
        $flag = fn (string $value): ?bool => match (strtolower($value)) {
            '1', 'yes', 'true', 'نعم', 'כן' => true,
            '0', 'no', 'false', 'لا', 'לא' => false,
            default => null,
        };

        if (($row['price'] ?? '') !== '') {
            $price = str_replace(',', '', $row['price']);

            if (! is_numeric($price) || (float) $price < 0 || (float) $price > 99999999) {
                $errors[] = self::text('Row :line: the price is not a valid amount.', ['line' => $line]);
            } elseif (bccomp($price, (string) $product->price, 4) !== 0) {
                $change['price'] = $price;
            }
        }

        foreach (['price_is_estimate', 'is_active'] as $column) {
            if (($row[$column] ?? '') === '') {
                continue;
            }

            $value = $flag($row[$column]);

            if ($value === null) {
                $errors[] = self::text('Row :line: :column must be 1 or 0.', ['line' => $line, 'column' => $column]);
            } elseif ($value !== (bool) $product->{$column}) {
                $change[$column] = $value;
            }
        }

        if (($row['stock'] ?? '') !== '') {
            if (! ctype_digit($row['stock']) || (int) $row['stock'] > 1000000) {
                $errors[] = self::text('Row :line: the stock must be a whole number.', ['line' => $line]);
            } elseif ((int) $row['stock'] !== $product->stock_quantity) {
                if (! $actor->role->canManageInventory()) {
                    $errors[] = self::text('Row :line: only inventory staff can change stock.', ['line' => $line]);
                } else {
                    $change['stock'] = (int) $row['stock'];
                }
            }
        }

        return $change;
    }
}
