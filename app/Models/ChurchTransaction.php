<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchTransaction extends Model
{
    use HasFactory;
    
    protected $primaryKey = 'ChurchTransactionID';
    
    protected $fillable = [
        'user_id',
        'church_id',
        'service_id',
        'schedule_id',
        'schedule_time_id',
        'appointment_id',
        'paymongo_session_id',
        'receipt_code',
        'payment_method',
        'amount_paid',
        'currency',
        'transaction_type',
        'status',
        'checkout_url',
        'appointment_date',
        'expires_at',
        'refund_status',
        'refund_date',
        'refund_reason',
        'transaction_date',
        'notes',
        'metadata',
    ];
    
    protected $casts = [
        'amount_paid' => 'decimal:2',
        'transaction_date' => 'datetime',
        'appointment_date' => 'datetime',
        'expires_at' => 'datetime',
        'refund_date' => 'datetime',
        'metadata' => 'array',
    ];

    // Always ensure a receipt code is present on create
    protected static function booted()
    {
        static::creating(function (self $model) {
            if (empty($model->receipt_code)) {
                do {
                    $code = 'TXN' . str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
                } while (self::where('receipt_code', $code)->exists());
                $model->receipt_code = $code;
            }
        });
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'church_id', 'ChurchID');
    }
    
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id', 'AppointmentID');
    }
    
    public function service(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'service_id', 'ServiceID');
    }
    
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ServiceSchedule::class, 'schedule_id', 'ScheduleID');
    }
    
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
    
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
    
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
