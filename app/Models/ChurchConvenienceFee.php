<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchConvenienceFee extends Model
{
    use HasFactory;

    protected $primaryKey = 'ConvenienceFeeID';
    
    protected $fillable = [
        'church_id',
        'fee_name',
        'fee_type',
        'fee_value',
        'is_active',
    ];

    protected $casts = [
        'fee_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'church_id', 'ChurchID');
    }

    /**
     * Calculate the convenience fee for a given amount
     */
    public function calculateFee(float $amount): float
    {
        if ($this->fee_type === 'percent') {
            return ($amount * $this->fee_value) / 100;
        }
        
        return $this->fee_value;
    }

    /**
     * Calculate the refund amount after deducting convenience fee
     */
    public function calculateRefundAmount(float $originalAmount): float
    {
        $convenienceFee = $this->calculateFee($originalAmount);
        return $originalAmount - $convenienceFee;
    }

    /**
     * Get the active convenience fee for a church
     */
    public static function getActiveForChurch(int $churchId): ?self
    {
        return self::where('church_id', $churchId)
                   ->where('is_active', true)
                   ->first();
    }
}