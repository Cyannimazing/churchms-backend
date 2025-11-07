<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $primaryKey = 'profile_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'system_role_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
    public function systemRole()
    {
        return $this->belongsTo(SystemRole::class, 'system_role_id');
    }

}
