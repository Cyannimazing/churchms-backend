<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchRescheduleFee extends Model
{
    use HasFactory;

    protected $primaryKey = 'RescheduleFeeID';

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
     * Calculate the reschedule fee for a given base amount.
     * For percent type, fee is a percentage of the base amount.
     * For fixed type, fee is the exact value regardless of base amount.
     */
    public function calculateFee(float $baseAmount): float
    {
        if ($this->fee_type === 'percent') {
            return ($baseAmount * $this->fee_value) / 100;
        }

        return $this->fee_value;
    }

    /**
     * Get the active reschedule fee for a church
     */
    public static function getActiveForChurch(int $churchId): ?self
    {
        return self::where('church_id', $churchId)
            ->where('is_active', true)
            ->first();
    }
}