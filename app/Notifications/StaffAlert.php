<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One entry in a staff member's notification list in the admin panel. Stored
 * immediately (not queued), so it appears even while the queue is down. The
 * title is a translation key, shown in the language each staff member uses.
 */
class StaffAlert extends Notification
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public string $kind,
        public string $title,
        public array $params,
        public string $url,
    ) {
        //
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'params' => $this->params,
            'url' => $this->url,
        ];
    }
}
