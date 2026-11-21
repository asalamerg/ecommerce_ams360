<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The same alert sent outside the panel: to the store's alert e-mail and the
 * staff Telegram chat. Queued, so a slow mail server never delays checkout.
 * Carries no customer phone, address or e-mail. Only ever sent on demand
 * (Notification::route), never to a user.
 */
class StaffAlertMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public string $title,
        public array $params,
        public string $url,
    ) {
        //
    }

    /**
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return array_values(array_filter([
            filled($notifiable->routeNotificationFor('mail')) ? 'mail' : null,
            filled($notifiable->routeNotificationFor('telegram')) ? TelegramChannel::class : null,
        ]));
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $title = $this->text();

        return (new MailMessage)
            ->subject($title)
            ->line($title)
            ->action(__('Open in the admin panel'), $this->url);
    }

    public function toTelegram(AnonymousNotifiable $notifiable): string
    {
        return $this->text()."\n".$this->url;
    }

    private function text(): string
    {
        return __($this->title, $this->params, (string) config('app.locale'));
    }
}
