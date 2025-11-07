<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubServiceRequirement extends Model
{
    protected $table = 'sub_service_requirements';
    protected $primaryKey = 'RequirementID';
    
    protected $fillable = [
        'SubServiceID',
        'RequirementName',
        'isNeeded',
        'isSubmitted',
        'SortOrder',
    ];
    
    protected $casts = [
        'SubServiceID' => 'integer',
        'isNeeded' => 'boolean',
        'isSubmitted' => 'boolean',
        'SortOrder' => 'integer',
    ];
    
    /**
     * Get the sub-service that owns this requirement.
     */
    public function subService(): BelongsTo
    {
        return $this->belongsTo(SubService::class, 'SubServiceID', 'SubServiceID');
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
     * Scope for submitted requirements only.
     */
    public function scopeSubmitted($query)
    {
        return $query->where('isSubmitted', true);
    }
    
    /**
     * Scope for pending (not submitted) requirements only.
     */
    public function scopePending($query)
    {
        return $query->where('isSubmitted', false);
    }
    
    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('SortOrder');
    }
}
