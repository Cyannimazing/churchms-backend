<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberChild extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_member_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'sex',
        'religion',
        'baptism',
        'first_eucharist',
        'confirmation',
        'school',
        'grade',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'baptism' => 'boolean',
        'first_eucharist' => 'boolean',
        'confirmation' => 'boolean',
    ];

    public function churchMember(): BelongsTo
    {
        return $this->belongsTo(ChurchMember::class);
    }
}