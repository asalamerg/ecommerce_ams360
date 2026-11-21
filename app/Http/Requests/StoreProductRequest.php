<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Services\ImageUploads;
use App\Services\ProductImageStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->canManageCatalog() ?? false;
    }

    /**
     * Only the Arabic name is required. An empty English or Hebrew name is
     * filled with it, so the item still shows in those stores until translated.
     */
    protected function prepareForValidation(): void
    {
        // The store sells in shekels: an empty currency means ILS.
        $currency = strtoupper(trim((string) $this->input('currency', '')));
        $this->merge(['currency' => $currency === '' ? 'ILS' : $currency]);

        $translations = $this->input('translations');
        $arabic = is_array($translations) ? trim((string) ($translations['ar']['name'] ?? '')) : '';

        if ($arabic === '') {
            return;
        }

        foreach (['en', 'he'] as $locale) {
            if (! is_array($translations[$locale] ?? null)) {
                $translations[$locale] = [];
            }

            if (trim((string) ($translations[$locale]['name'] ?? '')) === '') {
                $translations[$locale]['name'] = $arabic;
            }
        }

        $this->merge(['translations' => $translations]);
    }

    /**
     * Errors name each field as the form shows it.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [
            'category_id' => __('Category'),
            'brand_id' => __('Brand'),
            'sku' => __('SKU'),
            'aliases' => __('Other spellings for search'),
            'price' => __('Price'),
            'discount_percent' => __('Discount (%)'),
            'discount_ends_at' => __('Discount ends on'),
            'currency' => __('Currency (ISO 4217)'),
            'stock_quantity' => __('Quantity in stock'),
            'new_images.*' => __('Product images'),
            'colors.*.hex' => __('Color'),
            'colors.*.name.ar' => __('Color'),
        ];

        foreach (['ar', 'en', 'he'] as $locale) {
            $suffix = ' ('.strtoupper($locale).')';
            $names["translations.{$locale}.name"] = __('Product name').$suffix;
            $names["translations.{$locale}.slug"] = __('URL slug').$suffix;
            $names["translations.{$locale}.description"] = __('Description').$suffix;
            $names["translations.{$locale}.seo_title"] = __('SEO title').$suffix;
            $names["translations.{$locale}.seo_description"] = __('SEO description').$suffix;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : null;
        $locales = ['ar', 'en', 'he'];
        $rules = [
            'category_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('is_active', true)],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($productId)],
            'aliases' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999.9999'],
            // 0 or empty means no discount; capped so a typo cannot give a product away.
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:'.Product::MAX_DISCOUNT_PERCENT],
            // A day: the discount runs to its end, see CatalogController::discountFields().
            'discount_ends_at' => ['nullable', 'date', 'after_or_equal:today'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'price_is_estimate' => ['required', 'boolean'],
            'translations' => ['required', 'array:ar,en,he'],
            'sync_images' => ['sometimes', 'boolean'],
            'image_order' => ['sometimes', 'array', 'max:'.ProductImageStore::MAX_IMAGES],
            'image_order.*' => ['string', 'regex:/^(existing|new)-\d+$/'],
            'new_images' => ['sometimes', 'array', 'max:'.ProductImageStore::MAX_IMAGES],
            'new_images.*' => ImageUploads::rules(),
            // Like sync_images: an empty list cannot travel in multipart data.
            'sync_colors' => ['sometimes', 'boolean'],
            'colors' => ['sometimes', 'array', 'max:20'],
            'colors.*.id' => ['nullable', 'integer'],
            'colors.*.hex' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'colors.*.name' => ['required', 'array:ar,en,he'],
            'colors.*.name.ar' => ['required', 'string', 'max:40'],
            'colors.*.name.en' => ['nullable', 'string', 'max:40'],
            'colors.*.name.he' => ['nullable', 'string', 'max:40'],
            // Stock changes are an inventory permission, separate from editing the
            // catalog: the form shows the field only to roles that hold it, and
            // CatalogController::applyStock() ignores it from anyone else.
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            // The quantity the form was opened with, so a sale in the meantime is
            // not silently overwritten.
            'stock_quantity_was' => [
                'nullable',
                'integer',
                'min:0',
                Rule::requiredIf(fn (): bool => $this->filled('stock_quantity')
                    && ($this->user()?->role->canManageInventory() ?? false)),
            ],
        ];

        foreach ($locales as $locale) {
            $translationId = $product instanceof Product
                ? $product->translations()->where('locale', $locale)->value('id')
                : null;

            $rules["translations.{$locale}"] = ['required', 'array:name,slug,description,seo_title,seo_description'];
            $rules["translations.{$locale}.name"] = ['required', 'string', 'max:255'];
            $rules["translations.{$locale}.slug"] = [
                // Left empty, it is made from the name, see App\Support\Slugs.
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('product_translations', 'slug')
                    ->where('locale', $locale)
                    ->ignore($translationId),
            ];
            $rules["translations.{$locale}.description"] = ['nullable', 'string', 'max:20000'];
            $rules["translations.{$locale}.seo_title"] = ['nullable', 'string', 'max:255'];
            $rules["translations.{$locale}.seo_description"] = ['nullable', 'string', 'max:320'];
        }

        return $rules;
    }
}
