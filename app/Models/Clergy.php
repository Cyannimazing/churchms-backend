<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Clergy extends Model
{
    use HasFactory;

    protected $table = 'Clergy';
    protected $primaryKey = 'ClergyID';

    protected $fillable = [
        'ChurchID',
        'first_name',
        'last_name',
        'middle_name',
        'position',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function church()
    {
        return $this->belongsTo(Church::class, 'ChurchID');
    }

    public function getFullNameAttribute()
    {
        $middleName = $this->middle_name ? ' ' . $this->middle_name . '.' : '';
        return $this->first_name . $middleName . ' ' . $this->last_name;
    }
}