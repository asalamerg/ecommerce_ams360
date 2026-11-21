<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer their order moved on, in the language they ordered in.
 * Sent to the e-mail on the order, so guests who left one get it too. The link
 * opens the tracking form, which asks for the phone number before showing
 * anything, so a forwarded e-mail does not expose the order.
 */
class OrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /** Statuses worth an e-mail. "pending" is where every order starts. */
    public const array STATUSES = ['processing', 'ready_for_pickup', 'shipped', 'completed', 'cancelled'];

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public Order $order)
    {
        $this->locale($order->locale);
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
        $status = $this->order->status;
        $locale = $this->order->locale;

        return (new MailMessage)
            ->subject(__('ui.order_updates.subject', ['reference' => $this->order->reference, 'status' => __(ucfirst(str_replace('_', ' ', $status)))]))
            ->greeting(__('ui.order_updates.greeting', ['name' => $this->order->customer_name]))
            ->line(__("ui.order_updates.{$status}"))
            ->line(__('ui.checkout.reference').': '.$this->order->reference)
            ->action(__('ui.order_updates.view'), url("/{$locale}/track?reference={$this->order->reference}"));
    }
}
