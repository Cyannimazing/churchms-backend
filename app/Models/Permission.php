<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'Permission';
    protected $primaryKey = 'PermissionID';
    protected $fillable = ['PermissionName'];
    public $timestamps = false;

    public function roles()
    {
        return $this->belongsToMany(ChurchRole::class, 'RolePermission', 'PermissionID', 'RoleID');
    }
}