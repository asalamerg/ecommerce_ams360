<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageUploads;
use App\Services\PointsLedger;
use App\Services\ProductPricing;
use App\Support\AdminReturnUrl;
use App\Support\ModuleFieldValues;
use App\Support\ProductSearchIndex;
use App\Support\StoreAdminModules;
use App\Support\StorefrontCache;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

class StoreModuleController extends Controller
{
    public function index(Request $request, string $module): Response
    {
        $definition = $this->definition($module);
        $query = DB::table($definition['table'])->orderByDesc('id');
        $search = trim((string) $request->query('q'));

        if ($search !== '' && $definition['searchable'] !== []) {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $where) use ($definition, $term): void {
                foreach ($definition['searchable'] as $column) {
                    $where->orWhere($column, 'like', $term);
                }
            });
        }

        $records = $definition['singleton']
            ? $query->where('id', 1)->get()
            : $query->paginate(20)->withQueryString()->through(
                fn (object $record): array => $this->formatRecord($definition, $record),
            );

        if ($definition['singleton']) {
            $records = $records->map(fn (object $record): array => $this->formatRecord($definition, $record));
        }

        return Inertia::render('admin/module/index', [
            'module' => $this->pageDefinition($module, $definition),
            'records' => $records,
            'search' => $search,
        ]);
    }

    public function create(string $module): Response
    {
        $definition = $this->definition($module);
        abort_if($definition['singleton'] && DB::table($definition['table'])->where('id', 1)->exists(), 404);
        abort_unless($definition['can_create'], 403);

        return $this->formResponse($module, $definition, null);
    }

    public function edit(string $module, int $record): Response
    {
        $definition = $this->definition($module);
        abort_if($definition['singleton'] && $record !== 1, 404);
        $row = DB::table($definition['table'])->where('id', $record)->first();
        abort_unless($row !== null, 404);

        return $this->formResponse($module, $definition, $this->formatRecord($definition, $row));
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $definition = $this->definition($module);
        abort_unless($definition['can_create'], 403);

        return $this->save($request, $module, $definition, null);
    }

    public function update(Request $request, string $module, int $record): RedirectResponse
    {
        $definition = $this->definition($module);
        abort_if($definition['singleton'] && $record !== 1, 404);
        $exists = DB::table($definition['table'])->where('id', $record)->exists();
        abort_unless($exists, 404);

        return $this->save($request, $module, $definition, $record);
    }

    public function destroy(string $module, int $record): RedirectResponse
    {
        $definition = $this->definition($module);
        abort_unless($definition['can_delete'], 403);

        $row = DB::table($definition['table'])->where('id', $record)->first();
        abort_unless($row !== null, 404);

        DB::transaction(function () use ($module, $definition, $record, $row): void {
            DB::table($definition['table'])->where('id', $record)->delete();
            $this->recordActivity($module, 'deleted', $record, ['fields' => array_keys((array) $row)]);
            $this->deleteUploadedImages($definition, $row);
        });

        if ($definition['table'] === 'offers') {
            ProductPricing::refreshAll();
        }

        StorefrontCache::flush();

        return to_route('admin.modules.index', ['module' => $module])->with('success', __('Item deleted.'));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function pageDefinition(string $module, array $definition): array
    {
        return [
            'key' => $module,
            'title' => __($definition['title']),
            'description' => __($definition['description']),
            'fields' => array_map(
                fn (array $field): array => [
                    ...$field,
                    'label' => __($field['label']),
                    'options' => isset($field['options']) ? array_map(fn (string $label): string => __($label), $field['options']) : null,
                    'readOnly' => $field['read_only'] ?? false,
                    'choices' => isset($field['relation']) ? ModuleFieldValues::choices($field['relation']) : null,
                ],
                $definition['fields'],
            ),
            'columns' => array_map(fn (string $label): string => __($label), $definition['columns']),
            'searchable' => $definition['searchable'] !== [],
            'canCreate' => $definition['can_create'],
            'canDelete' => $definition['can_delete'],
            'singleton' => $definition['singleton'],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{id: int, values: array<string, mixed>, summary: array<string, string>}|null  $record
     */
    private function formResponse(string $module, array $definition, ?array $record): Response
    {
        $values = [];

        foreach ($definition['fields'] as $field) {
            $values[$field['name']] = $record['values'][$field['name']] ?? $field['default'] ?? match ($field['type']) {
                'checkbox' => false,
                // The first option is what the browser shows; send what it shows.
                'select' => (string) array_key_first($field['options'] ?? []),
                default => '',
            };
        }

        return Inertia::render('admin/module/form', [
            'back' => AdminReturnUrl::for("/admin/modules/{$module}", request()->query('back')),
            'module' => $this->pageDefinition($module, $definition),
            'record' => $record ? ['id' => $record['id'], 'values' => $values] : null,
            'values' => $values,
            'relatedItems' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{id: int, values: array<string, mixed>, summary: array<string, string>}
     */
    private function formatRecord(array $definition, object $record): array
    {
        $values = [];

        foreach ($definition['fields'] as $field) {
            $column = $field['column'] ?? $field['name'];
            $value = $record->{$column} ?? null;

            if (isset($field['locale'])) {
                $localized = $this->decodeJson($value);
                $value = $localized[$field['locale']] ?? '';
            } elseif (in_array($field['type'], ['textarea'], true) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;
            } elseif ($field['type'] === 'datetime-local' && $value) {
                $value = CarbonImmutable::parse((string) $value)->format('Y-m-d\TH:i');
            } elseif ($field['type'] === 'checkbox') {
                $value = (bool) $value;
            }

            $values[$field['name']] = $value ?? '';
        }

        $summary = [];

        foreach ($definition['columns'] as $column => $label) {
            $value = $record->{$column} ?? null;

            if (in_array($column, ['name', 'title', 'site_name', 'address'], true)) {
                $translations = $this->decodeJson($value);
                // A plain name (a person's, on users) is not a translation set.
                $value = $translations === []
                    ? (is_string($value) && ! json_validate($value) ? $value : '')
                    : ($translations[app()->getLocale()] ?? $translations['ar'] ?? $translations['en'] ?? reset($translations) ?: '');
            } elseif (is_string($value) && json_validate($value)) {
                $value = json_encode(json_decode($value, true), JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value) || in_array($column, ['is_active', 'is_read', 'is_approved'], true)) {
                $value = (bool) $value ? __('Yes') : __('No');
            } elseif (is_string($value)) {
                $value = $this->optionLabel($definition, $column, $value);
            }

            $summary[$column] = $value === null ? '' : (string) $value;
        }

        return [
            'id' => (int) data_get($record, 'id'),
            'values' => $values,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function save(Request $request, string $module, array $definition, ?int $record): RedirectResponse
    {
        $rules = [];

        foreach ($definition['fields'] as $field) {
            if ($field['read_only'] ?? false) {
                continue;
            }

            $ruleSet = explode('|', $field['rules']);

            if (($field['unique'] ?? false) && $record !== null) {
                $column = $field['column'] ?? $field['name'];
                $ruleSet[] = Rule::unique($definition['table'], $column)->ignore($record);
            } elseif ($field['unique'] ?? false) {
                $column = $field['column'] ?? $field['name'];
                $ruleSet[] = Rule::unique($definition['table'], $column);
            }

            if (in_array($field['type'], ModuleFieldValues::STRUCTURED, true)) {
                $ruleSet[] = ModuleFieldValues::rule($field);
            }

            if ($field['type'] === 'image') {
                $ruleSet[] = $this->imageLinkRule();
                $rules[$field['name'].'_file'] = ['nullable', ...ImageUploads::rules()];
            }

            $rules[$field['name']] = $ruleSet;
        }

        // An offer takes at most the same share off as a product's own discount,
        // and has to cover something.
        if ($definition['table'] === 'offers') {
            $rules['discount_value'][] = function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                if ($request->input('discount_type') === 'percentage' && is_numeric($value) && (float) $value > Product::MAX_DISCOUNT_PERCENT) {
                    $fail(__('A percentage offer can take at most :max% off.', ['max' => Product::MAX_DISCOUNT_PERCENT]));
                }
            };
            $rules['category_id'][] = Rule::requiredIf(fn (): bool => ! $request->filled('brand_id')
                && in_array(json_decode((string) $request->input('product_ids', '[]'), true), [[], null], true));
        }

        $data = $request->validate($rules, [], $this->attributeNames($definition));

        try {
            $attributes = $this->databaseAttributes($definition, $data);
        } catch (JsonException) {
            return back()->withErrors(['module' => __('The supplied JSON data is invalid.')])->withInput();
        }

        if ($definition['table'] === 'users' && isset($attributes['role'])) {
            abort_if(
                in_array($attributes['role'], ['owner', 'administrator'], true)
                    && $request->user()->role->value !== 'owner'
                    && ($record !== $request->user()->id || $attributes['role'] !== $request->user()->role->value),
                403,
                __('Only the current owner can grant an administrator or owner role.'),
            );
            abort_if(
                $record === $request->user()->id
                    && $request->user()->role->canManageAdministration()
                    && ! in_array($attributes['role'], ['owner', 'administrator'], true),
                422,
                __('You cannot remove your own administrator access.'),
            );
        }

        if ($definition['table'] === 'users' && ! ($attributes['is_active'] ?? true)) {
            abort_if($record === $request->user()->id, 422, __('You cannot disable your own account.'));
            abort_if(
                in_array(DB::table('users')->where('id', $record)->value('role'), ['owner', 'administrator'], true)
                    && $request->user()->role !== UserRole::Owner,
                403,
                __('Only the current owner can disable an administrator or owner account.'),
            );
        }

        $savedId = DB::transaction(function () use ($definition, $attributes, $module, $record, $request, $data): int {
            $attributes = $this->applyImages($request, $definition, $attributes, $record);
            $attributes['updated_at'] = now();

            if ($definition['table'] === 'users' && $record !== null && filled($data['points_adjustment'] ?? null)) {
                app(PointsLedger::class)->record(
                    $record,
                    (int) $data['points_adjustment'],
                    PointsLedger::ADJUSTED,
                    actor: $request->user(),
                    note: (string) ($data['points_note'] ?? ''),
                );
                $this->recordActivity($module, 'points_adjusted', $record, ['points' => (int) $data['points_adjustment']]);
            }

            if ($record === null) {
                $attributes['created_at'] = now();
                $id = $definition['singleton']
                    ? (int) DB::table($definition['table'])->insertGetId(['id' => 1, ...$attributes])
                    : (int) DB::table($definition['table'])->insertGetId($attributes);
                $this->recordActivity($module, 'created', $id, ['fields' => array_keys($attributes)]);

                return $id;
            }

            DB::table($definition['table'])->where('id', $record)->update($attributes);
            $this->recordActivity($module, 'updated', $record, ['fields' => array_keys($attributes)]);

            return $record;
        });

        // A brand's names and spellings are part of its products' search text.
        if ($definition['table'] === 'brands') {
            Product::query()->where('brand_id', $savedId)->each(fn (Product $product) => ProductSearchIndex::refresh($product));
        }

        // Offers change prices at once.
        if ($definition['table'] === 'offers') {
            ProductPricing::refreshAll();
        }

        // Slides, services, brands and offers appear on the storefront.
        StorefrontCache::flush();

        // Like products: back to the list the form came from with the row marked,
        // an empty form for the next one, or the same form. A single-record
        // screen (settings, points) has no list to go back to.
        $list = "/admin/modules/{$module}";
        $back = AdminReturnUrl::for($list, $request->input('back'));
        if ($definition['singleton']) {
            return to_route('admin.modules.edit', ['module' => $module, 'record' => $savedId])->with('success', __('Changes saved.'));
        }

        $redirect = match ($request->input('after')) {
            'new' => redirect("{$list}/create?".http_build_query(['back' => $back])),
            'stay' => redirect("{$list}/{$savedId}/edit?".http_build_query(['back' => $back])),
            default => redirect($back)->with('highlight', $savedId),
        };

        return $redirect->with('success', __('Changes saved.'));
    }

    /**
     * An image field keeps its saved URL unless a new file arrives, which is
     * re-encoded and stored under uploads/{table}. An empty value removes it.
     * A replaced or removed upload is deleted after commit when no other row of
     * the table still points to it; links to other sites are never touched.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyImages(Request $request, array $definition, array $attributes, ?int $record): array
    {
        $table = $definition['table'];
        $uploads = app(ImageUploads::class);
        $previous = $record === null ? null : DB::table($table)->where('id', $record)->first();

        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'image') {
                continue;
            }

            $column = $field['column'] ?? $field['name'];
            $file = $request->file($field['name'].'_file');

            if ($file instanceof UploadedFile) {
                $path = $uploads->store($file, "uploads/{$table}", (int) $field['max_side'], $field['name'].'_file', (bool) ($field['trim'] ?? false));
                $attributes[$column] = Storage::disk(ImageUploads::DISK)->url($path);
            }

            $old = $previous->{$column} ?? null;
            $prefix = "/storage/uploads/{$table}/";

            if (is_string($old) && $old !== ($attributes[$column] ?? null) && str_starts_with($old, $prefix)
                && ! DB::table($table)->where($column, $old)->where('id', '!=', $record)->exists()) {
                $uploads->deleteAfterCommit(substr($old, strlen('/storage/')));
            }
        }

        return $attributes;
    }

    /**
     * Uploads belonging to a deleted record go with it, unless another record of
     * the same table still points to them. Links to other sites are left alone.
     *
     * @param  array<string, mixed>  $definition
     */
    private function deleteUploadedImages(array $definition, object $row): void
    {
        $table = $definition['table'];
        $prefix = "/storage/uploads/{$table}/";

        foreach ($definition['fields'] as $field) {
            if ($field['type'] !== 'image') {
                continue;
            }

            $column = $field['column'] ?? $field['name'];
            $url = $row->{$column} ?? null;

            if (is_string($url) && str_starts_with($url, $prefix) && ! DB::table($table)->where($column, $url)->exists()) {
                app(ImageUploads::class)->deleteAfterCommit(substr($url, strlen('/storage/')));
            }
        }
    }

    /**
     * The saved value of an image field: an upload on this site, or an https
     * link entered before uploads existed. Anything else would either be blocked
     * by the Content Security Policy or point at an unexpected place.
     */
    private function imageLinkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $upload = '#^/storage/uploads/[a-z_]+/[A-Za-z0-9]{40}\.webp$#';

            if (is_string($value) && $value !== '' && ! str_starts_with($value, 'https://') && preg_match($upload, $value) !== 1) {
                $fail(__('The image must be uploaded here or be an https:// link.'));
            }
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function databaseAttributes(array $definition, array $data): array
    {
        $attributes = [];
        $localized = [];

        foreach ($definition['fields'] as $field) {
            if (($field['read_only'] ?? false) || ($field['virtual'] ?? false)) {
                continue;
            }

            $name = $field['name'];
            $value = $data[$name] ?? null;

            if (isset($field['locale'])) {
                $localized[$field['column']][$field['locale']] = $value;

                continue;
            }

            if ($field['type'] === 'textarea' && $value !== null && isset($field['json'])) {
                $value = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
            }

            if (in_array($field['type'], ModuleFieldValues::STRUCTURED, true)) {
                $attributes[$field['column'] ?? $name] = ModuleFieldValues::store($field, $value);

                continue;
            }

            if ($field['type'] === 'textarea' && $value !== null
                && in_array($name, ['theme'], true)) {
                $value = json_encode(
                    json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                );
            }

            if ($field['type'] === 'datetime-local' && $value !== null && $value !== '') {
                $value = CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
            }

            $column = $field['column'] ?? $name;
            $attributes[$column] = $value === '' ? null : $value;
        }

        foreach ($localized as $column => $translations) {
            $attributes[$column] = json_encode($translations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return $attributes;
    }

    /**
     * "Discount type" rather than "discount type", with the language for texts
     * that have one, in the admin's language.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function attributeNames(array $definition): array
    {
        $names = [];

        foreach ($definition['fields'] as $field) {
            $label = (string) __($field['label']);
            $names[$field['name']] = isset($field['locale']) ? $label.' ('.strtoupper((string) $field['locale']).')' : $label;
            $names[$field['name'].'_file'] = $label;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $module): array
    {
        $definition = StoreAdminModules::find($module);
        abort_unless($definition !== null, 404);

        $role = request()->user()?->role;
        $allowed = match ($definition['permission']) {
            'content' => $role?->canManageCatalog() ?? false,
            'orders' => $role?->canManageOrders() ?? false,
            'support' => $role?->canManageSupport() ?? false,
            'owner' => $role === UserRole::Owner,
            'admin' => $role?->canManageAdministration() ?? false,
            default => false,
        };
        abort_unless($allowed, 403);

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordActivity(string $module, string $action, ?int $record, array $changes): void
    {
        DB::table('store_admin_activities')->insert([
            'user_id' => request()->user()?->id,
            'section' => $module,
            'action' => $action,
            'record_id' => $record,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function optionLabel(array $definition, string $column, string $value): string
    {
        foreach ($definition['fields'] as $field) {
            if ($field['name'] === $column && isset($field['options'][$value])) {
                return __($field['options'][$value]);
            }
        }

        return $value;
    }
}
