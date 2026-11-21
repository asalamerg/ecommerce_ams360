<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackInStock extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Product $product, public string $language)
    {
        $this->locale($language);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $translation = $this->product->translations->firstWhere('locale', $this->language) ?? $this->product->translations->first();
        $name = (string) ($translation->name ?? '');

        return (new MailMessage)
            ->subject(__('ui.stock_alerts.subject', ['name' => $name]))
            ->line(__('ui.stock_alerts.body', ['name' => $name]))
            ->action(__('ui.stock_alerts.view'), url("/{$this->language}/products/".($translation->slug ?? '')));
    }
}
