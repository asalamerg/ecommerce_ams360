<?php

namespace App\Http\Requests;

use App\Support\Theme\ThemeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-store') ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $colors = [];

        foreach (ThemeCatalog::TOKENS as $token) {
            $colors["theme.colors.{$token}"] = ['required', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'];
        }

        return [
            ...$colors,
            'version' => ['required', 'integer', 'min:0'],
            'theme' => ['required', 'array'],
            'theme.preset' => ['required', 'string', 'alpha_dash', 'max:40'],
            'theme.colors' => ['required', 'array'],
            'theme.font' => ['required', 'string', Rule::in(array_keys(ThemeCatalog::FONTS))],
            'theme.radius' => ['required', 'string', Rule::in(array_keys(ThemeCatalog::RADII))],
            'theme.darkMode' => ['required', 'boolean'],
            'theme.logoFavicon' => ['required', 'boolean'],
        ];
    }
}
