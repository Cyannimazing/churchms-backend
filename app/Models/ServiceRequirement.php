<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequirement extends Model
{
    protected $table = 'service_requirement';
    protected $primaryKey = 'RequirementID';
    
    protected $fillable = [
        'ServiceID',
        'Description',
        'isNeeded',
        'RequirementType',
        'RequirementData',
        'SortOrder',
    ];
    
    protected $casts = [
        'ServiceID' => 'integer',
        'isNeeded' => 'boolean',
        'RequirementData' => 'array',
        'SortOrder' => 'integer',
    ];
    
    // Requirement type constants
    const REQUIREMENT_TYPES = [
        'document' => 'Document Required',
        'age_limit' => 'Age Requirement',
        'status_check' => 'Status Verification',
        'previous_sacrament' => 'Previous Sacrament Required',
        'attendance' => 'Attendance Requirement',
        'training' => 'Training/Class Completion',
        'approval' => 'Staff Approval Required',
        'custom' => 'Custom Requirement',
    ];
    
    /**
     * Get the sacrament service that owns this requirement.
     */
    public function sacramentService(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'ServiceID', 'ServiceID');
    }
    
    /**
     * Scope for needed requirements only.
     */
    public function scopeNeeded($query)
    {
        return $query->where('isNeeded', true);
    }
    
    /**
     * Scope for optional requirements only.
     */
    public function scopeOptional($query)
    {
        return $query->where('isNeeded', false);
    }
    
    
    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('SortOrder');
    }
    
    /**
     * Scope to filter by requirement type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('RequirementType', $type);
    }
}
