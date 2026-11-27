<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class StatusChangedNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
