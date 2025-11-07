<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationDeleted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public int $userId;
    public int $notificationId;

    public function __construct(int $userId, int $notificationId)
    {
        $this->userId = $userId;
        $this->notificationId = $notificationId;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId,
        ];
    }
}
