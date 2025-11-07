<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentRequirementSubmission extends Model
{
    protected $table = 'appointment_requirement_submissions';
    protected $primaryKey = 'SubmissionID';
    
    protected $fillable = [
        'AppointmentID',
        'RequirementID',
        'isSubmitted',
        'notes',
        'submitted_at',
        'reviewed_by',
    ];
    
    protected $casts = [
        'AppointmentID' => 'integer',
        'RequirementID' => 'integer',
        'isSubmitted' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_by' => 'integer',
    ];
    
    /**
     * Get the appointment that owns this submission.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'AppointmentID', 'AppointmentID');
    }
    
    /**
     * Get the requirement for this submission.
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ServiceRequirement::class, 'RequirementID', 'RequirementID');
    }
    
    /**
     * Get the staff member who reviewed this submission.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }
}