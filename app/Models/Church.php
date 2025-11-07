<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Church extends Model
{
    protected $table = 'Church';
    protected $primaryKey = 'ChurchID';
    protected $fillable = [
        'ChurchName',
        'IsPublic',
        'Latitude',
        'Longitude',
        'Street',
        'City',
        'Province',
        'ChurchStatus',
        'user_id', // Added to link to User
    ];
    protected $casts = [
        'IsPublic' => 'boolean',
        'ChurchStatus' => 'string',
        'Latitude' => 'decimal:8',
        'Longitude' => 'decimal:8',
    ];

    const STATUS_PENDING = 'Pending';
    const STATUS_ACTIVE = 'Active';
    const STATUS_REJECTED = 'Rejected';
    const STATUS_DISABLED = 'Disabled';

    protected $attributes = [
        'ChurchStatus' => self::STATUS_PENDING,
    ];

    public static $validStatuses = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_REJECTED,
        self::STATUS_DISABLED,
    ];

    public function setChurchStatusAttribute($value)
    {
        if (!in_array($value, self::$validStatuses)) {
            throw new \InvalidArgumentException("Invalid ChurchStatus: {$value}");
        }
        $this->attributes['ChurchStatus'] = $value;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function profile()
    {
        return $this->hasOne(ChurchProfile::class, 'ChurchID', 'ChurchID');
    }

    public function documents()
    {
        return $this->hasMany(ChurchOwnerDocument::class, 'ChurchID', 'ChurchID');
    }

    // Many UserChurchRole records (users serving the church)
    public function userChurchRoles()
    {
        return $this->hasMany(UserChurchRole::class, 'ChurchID', 'ChurchID');
    }

    // Many Users through UserChurchRole (employees)
    public function users()
    {
        return $this->hasManyThrough(
            User::class,
            UserChurchRole::class,
            'ChurchID', // Foreign key on UserChurchRole pointing to Church
            'id', // Foreign key on User
            'ChurchID', // Local key on Church
            'user_id' // Local key on UserChurchRole
        );
    }

    // Many ChurchRoles (roles defined for the church)
    public function roles()
    {
        return $this->hasMany(ChurchRole::class, 'ChurchID', 'ChurchID');
    }
    
    // Many SacramentServices (sacrament services offered by the church)
    public function sacramentServices()
    {
        return $this->hasMany(SacramentService::class, 'ChurchID', 'ChurchID');
    }

    // Payment configuration
    public function paymentConfig()
    {
        return $this->hasOne(ChurchPaymentConfig::class, 'church_id', 'ChurchID');
    }
}
