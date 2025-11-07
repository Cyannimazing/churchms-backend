<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubService extends Model
{
    protected $table = 'sub_service';
    protected $primaryKey = 'SubServiceID';
    
    protected $fillable = [
        'ServiceID',
        'SubServiceName',
        'Description',
        'IsActive',
    ];
    
    protected $casts = [
        'ServiceID' => 'integer',
        'IsActive' => 'boolean',
    ];
    
    /**
     * Get the sacrament service that owns this sub-service.
     */
    public function sacramentService(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'ServiceID', 'ServiceID');
    }
    
    /**
     * Get all schedules for this sub-service.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(SubServiceSchedule::class, 'SubServiceID', 'SubServiceID');
    }
    
    /**
     * Get all requirements for this sub-service.
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(SubServiceRequirement::class, 'SubServiceID', 'SubServiceID')
                    ->orderBy('SortOrder');
    }
}
