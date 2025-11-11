<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Send appointment reminders to users (3 days before, 1 day before, and today)';

    public function handle()
    {
        $now = Carbon::now();
        $threeDaysFromNow = $now->copy()->addDays(3)->startOfDay();
        $oneDayFromNow = $now->copy()->addDays(1)->startOfDay();
        $today = $now->copy()->startOfDay();

        // Get confirmed appointments only
        $appointments = Appointment::with(['user', 'church', 'service'])
            ->where('Status', 'Confirmed')
            ->whereDate('AppointmentDate', '>=', $today)
            ->get();

        $sent3DayReminders = 0;
        $sent1DayReminders = 0;
        $sentTodayReminders = 0;

        foreach ($appointments as $appointment) {
            $appointmentDate = Carbon::parse($appointment->AppointmentDate)->startOfDay();
            $userId = $appointment->UserID;
            $churchName = $appointment->church->ChurchName ?? 'the church';
            $serviceName = $appointment->service->ServiceName ?? 'service';

            // 3-day reminder
            if ($appointmentDate->equalTo($threeDaysFromNow) && !$this->reminderAlreadySent($userId, $appointment->AppointmentID, 3)) {
                $this->sendNotification(
                    $userId,
                    'Appointment Reminder',
                    "Your appointment at {$churchName} for {$serviceName} is in 3 days on {$appointmentDate->format('M j, Y')}.",
                    $appointment->AppointmentID,
                    3
                );
                $sent3DayReminders++;
            }

            // 1-day reminder
            if ($appointmentDate->equalTo($oneDayFromNow) && !$this->reminderAlreadySent($userId, $appointment->AppointmentID, 1)) {
                $this->sendNotification(
                    $userId,
                    'Appointment Tomorrow',
                    "Your appointment at {$churchName} for {$serviceName} is tomorrow at {$appointment->AppointmentDate->format('g:i A')}.",
                    $appointment->AppointmentID,
                    1
                );
                $sent1DayReminders++;
            }

            // Today reminder
            if ($appointmentDate->equalTo($today) && !$this->reminderAlreadySent($userId, $appointment->AppointmentID, 0)) {
                $this->sendNotification(
                    $userId,
                    'Appointment Today',
                    "Your appointment at {$churchName} for {$serviceName} is today at {$appointment->AppointmentDate->format('g:i A')}.",
                    $appointment->AppointmentID,
                    0
                );
                $sentTodayReminders++;
            }
        }

        $this->info("Appointment reminders sent successfully.");
        $this->info("3-day reminders: {$sent3DayReminders}, 1-day reminders: {$sent1DayReminders}, Today reminders: {$sentTodayReminders}");

        Log::info('Appointment reminders sent', [
            '3_day' => $sent3DayReminders,
            '1_day' => $sent1DayReminders,
            'today' => $sentTodayReminders,
        ]);

        return 0;
    }

    private function reminderAlreadySent($userId, $appointmentId, $daysUntil)
    {
        return Notification::where('user_id', $userId)
            ->where('type', 'appointment_reminder')
            ->where('data->appointment_id', $appointmentId)
            ->where('data->days_until', $daysUntil)
            ->exists();
    }

    private function sendNotification($userId, $title, $message, $appointmentId, $daysUntil)
    {
        Notification::create([
            'user_id' => $userId,
            'type' => 'appointment_reminder',
            'title' => $title,
            'message' => $message,
            'data' => [
                'appointment_id' => $appointmentId,
                'days_until' => $daysUntil,
            ],
            'is_read' => false,
        ]);
    }
}
