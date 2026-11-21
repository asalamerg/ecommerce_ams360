<?php

namespace App\Http\Requests;

use App\Rules\TurnstileToken;
use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9][0-9\-\s()]{6,}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'accept_estimate' => ['sometimes', 'boolean'],
            'use_points' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'delivery_method' => ['sometimes', 'in:delivery,pickup'],
            'shipping_zone_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'payment_method' => ['sometimes', 'in:whatsapp,online'],
            // Hidden from people, so only bots that fill every field put anything here.
            'website' => ['prohibited'],
            ...(TurnstileToken::enabled() ? [
                'turnstile_token' => ['required', 'string', 'max:2048', new TurnstileToken($this->ip())],
            ] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website.prohibited' => __('ui.checkout.verification_failed'),
            'turnstile_token.required' => __('ui.checkout.verification_required'),
        ];
    }

    /**
     * The checkout limits count a phone number by its digits only, so spacing or a
     * leading + does not make it a new number.
     */
    public function phoneDigits(): string
    {
        return (string) preg_replace('/\D/', '', (string) $this->validated('phone'));
    }
}
