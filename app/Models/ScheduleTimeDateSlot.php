<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleTimeDateSlot extends Model
{
    use HasFactory;

    protected $table = 'schedule_time_date_slots';
    protected $primaryKey = 'ScheduleTimeDateSlotID';

    protected $fillable = [
        'ScheduleTimeID',
        'SlotDate',
        'RemainingSlots',
    ];

    protected $casts = [
        'SlotDate' => 'date',
        'RemainingSlots' => 'integer',
    ];

    /**
     * Get the schedule time that owns this date slot
     */
    public function scheduleTime()
    {
        return $this->belongsTo(ScheduleTime::class, 'ScheduleTimeID', 'ScheduleTimeID');
    }
}
