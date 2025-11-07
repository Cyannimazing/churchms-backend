<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleRecurrence extends Model
{
    use HasFactory;

    protected $table = 'schedule_recurrences';
    protected $primaryKey = 'RecurrenceID';

    protected $fillable = [
        'ScheduleID',
        'RecurrenceType',
        'DayOfWeek',
        'WeekOfMonth',
        'SpecificDate',
    ];

    protected $casts = [
        'DayOfWeek' => 'integer',
        'WeekOfMonth' => 'integer',
        'SpecificDate' => 'date',
    ];

    /**
     * Get the service schedule that owns this recurrence
     */
    public function schedule()
    {
        return $this->belongsTo(ServiceSchedule::class, 'ScheduleID', 'ScheduleID');
    }

    /**
     * Get day name from DayOfWeek number
     */
    public function getDayName()
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return isset($days[$this->DayOfWeek]) ? $days[$this->DayOfWeek] : null;
    }

    /**
     * Get week name from WeekOfMonth number
     */
    public function getWeekName()
    {
        $weeks = [1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth'];
        return isset($weeks[$this->WeekOfMonth]) ? $weeks[$this->WeekOfMonth] : null;
    }

    /**
     * Get human readable recurrence description
     */
    public function getDescription()
    {
        switch ($this->RecurrenceType) {
            case 'Weekly':
                return 'Every ' . $this->getDayName();
            case 'MonthlyNth':
                return $this->getWeekName() . ' ' . $this->getDayName() . ' of every month';
            case 'OneTime':
                return 'One time on ' . $this->SpecificDate->format('M d, Y');
            default:
                return 'Unknown recurrence type';
        }
    }
}
