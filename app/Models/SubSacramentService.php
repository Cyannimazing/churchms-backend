<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubSacramentService extends Model
{
    protected $table = 'sub_sacrament_services';
    protected $primaryKey = 'SubSacramentServiceID';
    
    protected $fillable = [
        'ParentServiceID',
        'SubServiceName',
        'fee',
    ];
    
    protected $casts = [
        'ParentServiceID' => 'integer',
        'fee' => 'decimal:2',
    ];
    
    /**
     * Get the parent sacrament service.
     */
    public function parentService(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'ParentServiceID', 'ServiceID');
    }
}
