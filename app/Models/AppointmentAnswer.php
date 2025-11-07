<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentAnswer extends Model
{
    protected $table = 'AppointmentInputAnswer';
    protected $primaryKey = 'AnswerID';
    
    protected $fillable = [
        'AppointmentID',
        'InputFieldID',
        'AnswerText',
    ];
    
    protected $casts = [
        'AppointmentID' => 'integer',
        'InputFieldID' => 'integer',
    ];
    
    /**
     * Get the appointment that owns this answer.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'AppointmentID', 'AppointmentID');
    }
    
    /**
     * Get the input field that this answer belongs to.
     */
    public function inputField(): BelongsTo
    {
        return $this->belongsTo(ServiceInputField::class, 'InputFieldID', 'InputFieldID');
    }
}
