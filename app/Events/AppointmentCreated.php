<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Appointment;
use App\Models\Notification;

class AppointmentCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $appointment;
    public $churchId;
    public $notification;

    /**
     * Create a new event instance.
     */
    public function __construct(Appointment $appointment, $churchId, Notification $notification = null)
    {
        $this->appointment = $appointment;
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
        return 'appointment.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        \Log::info('[AppointmentCreated] Broadcasting event', [
            'appointment_id' => $this->appointment->AppointmentID,
            'church_id' => $this->churchId,
            'channel' => 'church.' . $this->churchId
        ]);
        
        try {
            // Load appointment relationships for complete data
            $this->appointment->load(['user.profile', 'service', 'subService', 'schedule']);
            
            // Load schedule time separately (not a nested relationship)
            $scheduleTime = null;
            if ($this->appointment->ScheduleTimeID) {
                $scheduleTime = \DB::table('schedule_times')
                    ->where('ScheduleTimeID', $this->appointment->ScheduleTimeID)
                    ->first();
            }
            
            $userName = 'Unknown User';
            if ($this->appointment->user && $this->appointment->user->profile) {
                $userName = trim(($this->appointment->user->profile->first_name ?? '') . ' ' . ($this->appointment->user->profile->last_name ?? ''));
            }
            
            // Match the exact structure from getChurchAppointments API
            return [
                'appointment_id' => $this->appointment->AppointmentID,
                'appointment' => [
                    'AppointmentID' => $this->appointment->AppointmentID,
                    'AppointmentDate' => $this->appointment->AppointmentDate,
                    'ScheduleTimeID' => $scheduleTime->ScheduleTimeID ?? $this->appointment->ScheduleTimeID,
                    'Status' => $this->appointment->Status,
                    'Notes' => $this->appointment->Notes,
                    'cancellation_category' => $this->appointment->cancellation_category,
                    'cancellation_note' => $this->appointment->cancellation_note,
                    'cancelled_at' => $this->appointment->cancelled_at,
                    'created_at' => $this->appointment->created_at->toISOString(),
                    'UserEmail' => $this->appointment->user->email ?? null,
                    'UserName' => $userName,
                    'ServiceID' => $this->appointment->ServiceID,
                    'ServiceName' => $this->appointment->service->ServiceName ?? null,
                    'ServiceDescription' => $this->appointment->service->Description ?? null,
                    'isMass' => $this->appointment->service->isMass ?? null,
                    'StartTime' => $scheduleTime->StartTime ?? null,
                    'EndTime' => $scheduleTime->EndTime ?? null,
                    'SubServiceName' => $this->appointment->subService->SubServiceName ?? null,
                    'SubSacramentServiceID' => $this->appointment->subService->SubSacramentServiceID ?? null,
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
            \Log::error('Error in AppointmentCreated broadcast: ' . $e->getMessage());
            return [
                'appointment_id' => $this->appointment->AppointmentID,
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
