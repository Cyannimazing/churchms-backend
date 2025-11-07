<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Notification;

class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public int $userId;
    public Notification $notification;

    public function __construct(int $userId, Notification $notification)
    {
        $this->userId = $userId;
        $this->notification = $notification;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => [
                'id' => $this->notification->id,
                'type' => $this->notification->type,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'data' => $this->notification->data,
                'is_read' => $this->notification->is_read,
                'created_at' => optional($this->notification->created_at)->toISOString(),
            ],
        ];
    }
}
