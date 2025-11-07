<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemRole extends Model
{
    public $timestamps = false;
    protected $fillable = ['role_name'];

    public function profiles()
    {
        return $this->hasMany(UserProfile::class, 'system_role_id');
    }
}
