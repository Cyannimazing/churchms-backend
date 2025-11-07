<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleTime extends Model
{
    use HasFactory;

    protected $table = 'schedule_times';
    protected $primaryKey = 'ScheduleTimeID';

    protected $fillable = [
        'ScheduleID',
        'StartTime',
        'EndTime',
    ];

    protected $casts = [
        // TIME fields should not be cast as datetime - leave as strings
    ];

    /**
     * Get the service schedule that owns this time slot
     */
    public function schedule()
    {
        return $this->belongsTo(ServiceSchedule::class, 'ScheduleID', 'ScheduleID');
    }

    /**
     * Get the date-specific slot records for this time slot
     */
    public function dateSlots()
    {
        return $this->hasMany(ScheduleTimeDateSlot::class, 'ScheduleTimeID', 'ScheduleTimeID');
    }

    /**
     * Get formatted time range
     */
    public function getTimeRange()
    {
        return $this->StartTime->format('g:i A') . ' - ' . $this->EndTime->format('g:i A');
    }

    /**
     * Get duration in minutes
     */
    public function getDurationInMinutes()
    {
        return $this->StartTime->diffInMinutes($this->EndTime);
    }

    /**
     * Check if time slot is currently active
     */
    public function isCurrentlyActive()
    {
        $now = now()->format('H:i:s');
        return $now >= $this->StartTime->format('H:i:s') && $now <= $this->EndTime->format('H:i:s');
    }
}
