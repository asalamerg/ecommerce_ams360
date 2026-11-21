<?php

namespace App\Http\Requests;

use App\Concerns\ProfileValidationRules;
use App\Http\Middleware\SetStoreLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]+$/'],
            'preferred_locale' => ['required', 'string', Rule::in(SetStoreLocale::SUPPORTED)],
        ];
    }
}
