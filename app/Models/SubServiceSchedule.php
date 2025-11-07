<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubServiceSchedule extends Model
{
    protected $table = 'sub_service_schedule';
    protected $primaryKey = 'ScheduleID';
    
    protected $fillable = [
        'SubServiceID',
        'DayOfWeek',
        'StartTime',
        'EndTime',
        'OccurrenceType',
        'OccurrenceValue',
    ];
    
    protected $casts = [
        'SubServiceID' => 'integer',
        'OccurrenceValue' => 'integer',
    ];
    
    /**
     * Get the sub-service that owns this schedule.
     */
    public function subService(): BelongsTo
    {
        return $this->belongsTo(SubService::class, 'SubServiceID', 'SubServiceID');
    }
    
    /**
     * Get a human-readable description of this schedule.
     */
    public function getDescription(): string
    {
        $description = "{$this->DayOfWeek} {$this->StartTime} - {$this->EndTime}";
        
        if ($this->OccurrenceType === 'nth_day_of_month' && $this->OccurrenceValue) {
            $ordinal = match($this->OccurrenceValue) {
                1 => '1st',
                2 => '2nd',
                3 => '3rd',
                4 => '4th',
                -1 => 'last',
                default => $this->OccurrenceValue . 'th'
            };
            $description = "Every {$ordinal} {$this->DayOfWeek} of the month, {$this->StartTime} - {$this->EndTime}";
        } elseif ($this->OccurrenceType === 'weekly') {
            $description = "Every {$this->DayOfWeek}, {$this->StartTime} - {$this->EndTime}";
        }
        
        return $description;
    }
}
