<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;
    
    protected $table = 'Appointment';
    protected $primaryKey = 'AppointmentID';
    
    protected $fillable = [
        'UserID',
        'ChurchID',
        'ServiceID',
        'ScheduleID',
        'ScheduleTimeID',
        'AppointmentDate',
        'Status',
        'Notes',
        'cancellation_category',
        'cancellation_note',
        'cancelled_at',
    ];
    
    protected $casts = [
        'AppointmentDate' => 'datetime',
        'cancelled_at' => 'datetime',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'UserID', 'id');
    }
    
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'ChurchID', 'ChurchID');
    }
    
    public function service(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'ServiceID', 'ServiceID');
    }
    
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ServiceSchedule::class, 'ScheduleID', 'ScheduleID');
    }
    
    public function subService(): BelongsTo
    {
        return $this->belongsTo(SubService::class, 'SubServiceID', 'SubServiceID');
    }
    
    public function churchTransaction(): BelongsTo
    {
        return $this->belongsTo(ChurchTransaction::class, 'AppointmentID', 'appointment_id');
    }
    
    public function churchTransactions()
    {
        return $this->hasMany(ChurchTransaction::class, 'appointment_id', 'AppointmentID');
    }
}