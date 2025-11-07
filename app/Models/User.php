<?php

namespace App\Models;

use App\Notifications\CustomVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    // Laravel default id is "id", so no need to change primaryKey

    protected $fillable = [
        'email',
        'password',
        'email_verified_at',
        'remember_token',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail);
    }

    /**
     * Get the user's profile.
     */
    public function profile()
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    /**
     * Get the user's contact information.
     */
    public function contact()
    {
        return $this->hasOne(UserContact::class, 'user_id');
    }

    /**
     * Get the user's system role through their profile.
     */
    public function systemRole()
    {
        return $this->hasOneThrough(
            SystemRole::class,
            UserProfile::class,
            'user_id',
            'system_role_id',
            'id',
            'system_role_id'
            
        );
    }

    /**
     * Get all churches owned by the user through the ChurchOwner table.
     */
    public function churches()
    {
        return $this->hasMany(Church::class, 'user_id');
    }

    // One UserChurchRole per User (one role, one church)
    public function userChurchRole()
    {
        return $this->hasOne(UserChurchRole::class, 'user_id', 'id');
    }

    // One Church through UserChurchRole
    public function church()
    {
        return $this->hasOneThrough(
            Church::class,
            UserChurchRole::class,
            'user_id', // Foreign key on UserChurchRole pointing to User
            'ChurchID', // Foreign key on Church
            'id', // Local key on User
            'ChurchID' // Local key on UserChurchRole
        );
    }

    // One ChurchRole through UserChurchRole
    public function churchRole()
    {
        return $this->hasOneThrough(
            ChurchRole::class,
            UserChurchRole::class,
            'user_id', // Foreign key on UserChurchRole pointing to User
            'RoleID', // Foreign key on ChurchRole
            'id', // Local key on User
            'RoleID' // Local key on UserChurchRole
        );
    }
}