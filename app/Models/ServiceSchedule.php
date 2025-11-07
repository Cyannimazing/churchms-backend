<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceSchedule extends Model
{
    use HasFactory;

    protected $table = 'service_schedules';
    protected $primaryKey = 'ScheduleID';

    protected $fillable = [
        'ServiceID',
        'SubSacramentServiceID',
        'StartDate',
        'EndDate',
        'SlotCapacity',
    ];

    protected $casts = [
        'StartDate' => 'date',
        'EndDate' => 'date',
        'SlotCapacity' => 'integer',
    ];

    /**
     * Get the sacrament service that owns this schedule
     */
    public function sacramentService()
    {
        return $this->belongsTo(SacramentService::class, 'ServiceID', 'ServiceID');
    }
    
    /**
     * Get the sub sacrament service (variant) if applicable
     */
    public function subSacramentService()
    {
        return $this->belongsTo(SubSacramentService::class, 'SubSacramentServiceID', 'SubSacramentServiceID');
    }

    /**
     * Get the recurrence patterns for this schedule
     */
    public function recurrences()
    {
        return $this->hasMany(ScheduleRecurrence::class, 'ScheduleID', 'ScheduleID');
    }

    /**
     * Get the time slots for this schedule
     */
    public function times()
    {
        return $this->hasMany(ScheduleTime::class, 'ScheduleID', 'ScheduleID');
    }

    /**
     * Check if schedule is active (within date range)
     */
    public function isActive()
    {
        $today = now()->toDateString();
        
        if ($this->StartDate > $today) {
            return false; // Schedule hasn't started yet
        }
        
        if ($this->EndDate && $this->EndDate < $today) {
            return false; // Schedule has ended
        }
        
        return true;
    }

    /**
     * Check if there are available slots for a specific date
     * Uses the schedule_time_date_slots table for accurate slot tracking
     */
    public function hasAvailableSlots($date = null, $scheduleTimeId = null)
    {
        if (!$date) {
            $date = now()->toDateString();
        }
        
        // If scheduleTimeId is provided, check that specific time slot
        if ($scheduleTimeId) {
            $slot = \DB::table('schedule_time_date_slots')
                ->where('ScheduleTimeID', $scheduleTimeId)
                ->where('SlotDate', $date)
                ->first();
                
            return $slot ? $slot->RemainingSlots > 0 : true;
        }
        
        // Otherwise, check if any time slot for this schedule has availability
        $timeSlots = $this->times;
        foreach ($timeSlots as $timeSlot) {
            $slot = \DB::table('schedule_time_date_slots')
                ->where('ScheduleTimeID', $timeSlot->ScheduleTimeID)
                ->where('SlotDate', $date)
                ->first();
                
            if (!$slot || $slot->RemainingSlots > 0) {
                return true; // At least one time slot has availability
            }
        }
        
        return false; // No time slots have availability
    }
}
