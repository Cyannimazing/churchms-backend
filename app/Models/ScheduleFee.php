<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleFee extends Model
{
    use HasFactory;

    protected $table = 'schedule_fees';
    protected $primaryKey = 'ScheduleFeeID';

    protected $fillable = [
        'ScheduleID',
        'FeeType',
        'Fee',
    ];

    protected $casts = [
        'Fee' => 'decimal:2',
    ];

    /**
     * Get the service schedule that owns this fee
     */
    public function schedule()
    {
        return $this->belongsTo(ServiceSchedule::class, 'ScheduleID', 'ScheduleID');
    }

    /**
     * Get formatted fee amount
     */
    public function getFormattedFee()
    {
        return '$' . number_format($this->Fee, 2);
    }

    /**
     * Check if this is a donation (optional payment)
     */
    public function isDonation()
    {
        return $this->FeeType === 'Donation';
    }

    /**
     * Check if this is a required fee
     */
    public function isRequiredFee()
    {
        return $this->FeeType === 'Fee';
    }

    /**
     * Get fee description
     */
    public function getDescription()
    {
        if ($this->isDonation()) {
            return 'Suggested donation: ' . $this->getFormattedFee();
        }
        
        return 'Required fee: ' . $this->getFormattedFee();
    }
}
