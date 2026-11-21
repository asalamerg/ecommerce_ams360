<?php

namespace App\Payments;

/**
 * Signed notifications in the common "t=<unix time>,v1=<hex HMAC-SHA256>"
 * form, where the signature covers "<time>.<raw body>". Gateways whose provider
 * signs this way can use it as is; others write their own check with the same
 * three rules: the raw body, a constant-time comparison, and a time limit.
 */
class HmacSignature
{
    /** Older notifications are refused, so a captured one cannot be replayed later. */
    public const int TOLERANCE_SECONDS = 300;

    public static function sign(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public static function verify(?string $header, string $body, string $secret): void
    {
        if ($secret === '') {
            throw new InvalidWebhook('No webhook secret is configured.');
        }

        $parts = [];

        foreach (explode(',', (string) $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);

        if ($timestamp === 0 || abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidWebhook('The signature is missing or too old.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidWebhook('The signature does not match.');
    }
}
