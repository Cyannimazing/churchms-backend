<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'church_id',
        'user_id',
        
        // Parish Registration Info
        'first_name',
        'middle_initial',
        'last_name',
        'email',
        'contact_number',
        'street_address',
        'city',
        'province',
        'postal_code',
        'barangay',
        'financial_support',
        
        // Head of House
        'head_first_name',
        'head_middle_initial',
        'head_last_name',
        'head_date_of_birth',
        'head_phone_number',
        'head_email_address',
        'head_religion',
        'head_baptism',
        'head_first_eucharist',
        'head_confirmation',
        'head_marital_status',
        'head_catholic_marriage',
        
        // Spouse
        'spouse_first_name',
        'spouse_middle_initial',
        'spouse_last_name',
        'spouse_date_of_birth',
        'spouse_phone_number',
        'spouse_email_address',
        'spouse_religion',
        'spouse_baptism',
        'spouse_first_eucharist',
        'spouse_confirmation',
        'spouse_marital_status',
        'spouse_catholic_marriage',
        
        // About Yourself
        'talent_to_share',
        'interested_ministry',
        'parish_help_needed',
        'homebound_special_needs',
        'other_languages',
        'ethnicity',
        
        // Status
        'status',
        'approved_at',
        'approved_by',
        'notes',
    ];

    protected $casts = [
        'head_date_of_birth' => 'date',
        'spouse_date_of_birth' => 'date',
        'approved_at' => 'datetime',
        'head_baptism' => 'boolean',
        'head_first_eucharist' => 'boolean',
        'head_confirmation' => 'boolean',
        'head_catholic_marriage' => 'boolean',
        'spouse_baptism' => 'boolean',
        'spouse_first_eucharist' => 'boolean',
        'spouse_confirmation' => 'boolean',
        'spouse_catholic_marriage' => 'boolean',
        'homebound_special_needs' => 'boolean',
    ];

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'church_id', 'ChurchID');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(MemberChild::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}