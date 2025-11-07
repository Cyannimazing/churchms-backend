<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\ChurchMember;
use App\Models\Notification;

class MemberApplicationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $application;
    public $churchId;
    public $notification;

    /**
     * Create a new event instance.
     */
    public function __construct(ChurchMember $application, $churchId, Notification $notification = null)
    {
        $this->application = $application;
        $this->churchId = $churchId;
        $this->notification = $notification;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('church.' . $this->churchId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'member-application.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        \Log::info('[MemberApplicationCreated] Broadcasting event', [
            'application_id' => $this->application->id,
            'church_id' => $this->churchId,
            'channel' => 'church.' . $this->churchId
        ]);
        
        try {
            // Load relationships
            $this->application->load(['user.profile', 'church']);
            
            $userName = 'Unknown User';
            if ($this->application->user && $this->application->user->profile) {
                $userName = trim(($this->application->user->profile->first_name ?? '') . ' ' . ($this->application->user->profile->last_name ?? ''));
            }
            
            // Fall back to the applicant name in the application
            if ($userName === 'Unknown User' || $userName === '') {
                $userName = trim(($this->application->first_name ?? '') . ' ' . ($this->application->last_name ?? ''));
            }
            
            return [
                'application_id' => $this->application->id,
                'application' => [
                    'id' => $this->application->id,
                    'status' => $this->application->status,
                    'first_name' => $this->application->first_name,
                    'last_name' => $this->application->last_name,
                    'email' => $this->application->email,
                    'contact_number' => $this->application->contact_number,
                    'created_at' => $this->application->created_at->toISOString(),
                    'UserName' => $userName,
                    'ChurchName' => $this->application->church->ChurchName ?? null,
                ],
                'notification' => $this->notification ? [
                    'id' => $this->notification->id,
                    'type' => $this->notification->type,
                    'title' => $this->notification->title,
                    'message' => $this->notification->message,
                    'data' => $this->notification->data,
                    'is_read' => $this->notification->is_read,
                    'created_at' => $this->notification->created_at->toISOString(),
                ] : null,
            ];
        } catch (\Exception $e) {
            // Fallback to minimal data if relationships fail
            \Log::error('Error in MemberApplicationCreated broadcast: ' . $e->getMessage());
            return [
                'application_id' => $this->application->id,
                'notification' => $this->notification ? [
                    'id' => $this->notification->id,
                    'type' => $this->notification->type,
                    'title' => $this->notification->title,
                    'message' => $this->notification->message,
                    'data' => $this->notification->data,
                    'is_read' => $this->notification->is_read,
                    'created_at' => $this->notification->created_at->toISOString(),
                ] : null,
            ];
        }
    }
}
