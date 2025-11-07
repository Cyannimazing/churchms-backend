<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    protected $fillable = [
        'church_id',
        'name',
        'imagePath',
    ];

    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id', 'ChurchID');
    }
}
