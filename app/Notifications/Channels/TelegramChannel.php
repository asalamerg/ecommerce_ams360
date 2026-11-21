<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts a plain-text message to the staff Telegram chat. Plain text, so nothing
 * a customer typed (a name, a note) can be read as formatting or a link.
 */
class TelegramChannel
{
    public function send(AnonymousNotifiable $notifiable, Notification $notification): void
    {
        $token = config('services.telegram.bot_token');
        $chat = $notifiable->routeNotificationFor('telegram');

        if (! is_string($token) || $token === '' || blank($chat) || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $response = Http::timeout(10)
            ->asJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => mb_substr((string) $notification->toTelegram($notifiable), 0, 4000),
                'disable_web_page_preview' => true,
            ]);

        // Thrown so the queue retries; the token is never part of the message.
        if ($response->failed()) {
            throw new RuntimeException("Telegram alert failed with HTTP {$response->status()}.");
        }
    }
}
