<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserContact extends Model
{
    protected $primaryKey = 'contact_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'address',
        'contact_number',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
