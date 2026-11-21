<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Services\ImageUploads;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
            'parent_id' => __('Main section'),
            'position' => __('Display position'),
            'image' => __('Category image'),
        ];

        foreach (['ar', 'en', 'he'] as $locale) {
            $suffix = ' ('.strtoupper($locale).')';
            $names["translations.{$locale}.name"] = __('Category name').$suffix;
            $names["translations.{$locale}.slug"] = __('URL slug').$suffix;
            $names["translations.{$locale}.description"] = __('Description').$suffix;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $rules = [
            'is_active' => ['required', 'boolean'],
            'show_on_home' => ['sometimes', 'boolean'],
            'position' => ['required', 'integer', 'min:0', 'max:65535'],
            'translations' => ['required', 'array:ar,en,he'],
            'image' => ['nullable', ...ImageUploads::rules()],
            'remove_image' => ['sometimes', 'boolean'],
            // Two levels only: the parent must be a main section, and a main
            // section with categories under it cannot itself move under another.
            'parent_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($category): void {
                $parent = is_numeric($value) ? Category::query()->whereKey((int) $value)->first() : null;

                if ($parent === null || $parent->parent_id !== null || ($category instanceof Category && $parent->id === $category->id)) {
                    $fail(__('Choose a main section as the parent.'));
                } elseif ($category instanceof Category && $category->children()->exists()) {
                    $fail(__('This category has categories under it, so it must stay a main section.'));
                }
            }],
        ];

        foreach (['ar', 'en', 'he'] as $locale) {
            $translationId = $category instanceof Category
                ? $category->translations()->where('locale', $locale)->value('id')
                : null;

            $rules["translations.{$locale}"] = ['required', 'array:name,slug,description,seo_title,seo_description'];
            $rules["translations.{$locale}.name"] = ['required', 'string', 'max:255'];
            $rules["translations.{$locale}.slug"] = [
                // Left empty, it is made from the name, see App\Support\Slugs.
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('category_translations', 'slug')
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
