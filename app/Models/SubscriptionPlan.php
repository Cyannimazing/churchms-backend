<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $table = 'SubscriptionPlan';
    protected $primaryKey = 'PlanID';
    public $timestamps = false;

    protected $fillable = [
        'PlanName',
        'Price',
        'DurationInMonths',
        'MaxChurchesAllowed',
        'Description',
    ];

    public function churchSubscriptions()
    {
        return $this->hasMany(ChurchSubscription::class, 'PlanID', 'PlanID');
    }

    public function transactionsAsOldPlan()
    {
        return $this->hasMany(SubscriptionTransaction::class, 'OldPlanID', 'PlanID');
    }

    public function transactionsAsNewPlan()
    {
        return $this->hasMany(SubscriptionTransaction::class, 'NewPlanID', 'PlanID');
    }
}