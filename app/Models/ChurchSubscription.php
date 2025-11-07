<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChurchSubscription extends Model
{
    protected $table = 'ChurchSubscription';
    protected $primaryKey = 'SubscriptionID';
    public $timestamps = false;

    protected $fillable = [
        'UserID',
        'PlanID',
        'StartDate',
        'EndDate',
        'Status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'id');
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'PlanID', 'PlanID');
    }
}