<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks a Cloudflare Turnstile token with Cloudflare. Fails closed: if
 * Cloudflare cannot be reached, the form is rejected and the visitor can retry.
 */
class TurnstileToken implements ValidationRule
{
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private ?string $ip)
    {
        //
    }

    /**
     * The validation rules a public form adds for the bot check, or none
     * while Turnstile is not configured.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?string $ip): array
    {
        return self::enabled() ? ['turnstile_token' => ['required', 'string', 'max:2048', new self($ip)]] : [];
    }

    public static function siteKey(): ?string
    {
        return self::enabled() ? (string) config('services.turnstile.site_key') : null;
    }

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key')) && filled(config('services.turnstile.site_key'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $value,
                'remoteip' => $this->ip,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Turnstile verification unreachable', ['message' => $exception->getMessage()]);
            $fail(__('ui.checkout.verification_failed'));

            return;
        }

        if ($response->json('success') !== true) {
            $fail(__('ui.checkout.verification_failed'));
        }
    }
}
