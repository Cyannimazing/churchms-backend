<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateConfiguration extends Model
{
    protected $table = 'certificate_configurations';
    protected $primaryKey = 'CertificateConfigID';
    
    protected $fillable = [
        'ChurchID',
        'CertificateType',
        'ServiceID',
        'FieldMappings',
        'IsEnabled',
    ];
    
    protected $casts = [
        'FieldMappings' => 'array',
        'IsEnabled' => 'boolean',
    ];
    
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'ChurchID', 'ChurchID');
    }
    
    public function service(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'ServiceID', 'ServiceID');
    }
}
