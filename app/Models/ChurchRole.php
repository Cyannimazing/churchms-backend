<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChurchRole extends Model
{
    protected $table = 'ChurchRole';
    protected $primaryKey = 'RoleID';
    protected $fillable = ['ChurchID', 'RoleName'];
    public $timestamps = false;

    public function church()
    {
        return $this->belongsTo(Church::class, 'ChurchID', 'ChurchID');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'RolePermission', 'RoleID', 'PermissionID');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'UserChurchRole', 'RoleID', 'user_id')
                    ->wherePivot('ChurchID', $this->ChurchID);
    }
}