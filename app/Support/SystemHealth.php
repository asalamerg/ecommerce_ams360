<?php

namespace App\Support;

use App\Rules\TurnstileToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Problems an owner should fix that nothing else would reveal: background
 * workers that stopped, and production settings left empty. Shown on the
 * dashboard to owners and administrators. Checked only in production, where
 * the scheduler and queue worker are expected to run.
 */
class SystemHealth
{
    public const string SCHEDULER_HEARTBEAT = 'store.heartbeat.scheduler';

    public const string QUEUE_HEARTBEAT = 'store.heartbeat.queue';

    /**
     * @return list<array{key: string, message: string}>
     */
    public static function warnings(): array
    {
        if (! app()->isProduction()) {
            return [];
        }

        $warnings = [];
        $stale = fn (string $key, int $minutes): bool => time() - (int) Cache::get($key, 0) > $minutes * 60;

        if ($stale(self::SCHEDULER_HEARTBEAT, 10)) {
            $warnings[] = self::warning('scheduler', 'Scheduled tasks are not running: unconfirmed orders are not expiring and reminders are not sent. Check the scheduler container.');
        }

        if ($stale(self::QUEUE_HEARTBEAT, 20)) {
            $warnings[] = self::warning('queue', 'The queue worker is not running: e-mails and alerts are waiting. Check the queue container.');
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $warnings[] = self::warning('mail', 'E-mail is not configured: customers cannot verify accounts or reset passwords.');
        }

        if (! TurnstileToken::enabled()) {
            $warnings[] = self::warning('turnstile', 'Bot protection (Cloudflare Turnstile) is off at checkout.');
        }

        if (blank(config('sentry.dsn'))) {
            $warnings[] = self::warning('sentry', 'Error reporting (Sentry) is not configured: site errors go unnoticed.');
        }

        $alertEmail = DB::table('site_settings')->where('id', 1)->value('notification_email');

        if (blank($alertEmail) && blank(config('services.telegram.chat_id'))) {
            $warnings[] = self::warning('alerts', 'New orders are announced only inside this panel. Add an alert e-mail in store settings or a Telegram chat.');
        }

        return $warnings;
    }

    /**
     * @return array{key: string, message: string}
     */
    private static function warning(string $key, string $message): array
    {
        return ['key' => $key, 'message' => __($message)];
    }
}
