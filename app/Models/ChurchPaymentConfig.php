<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ChurchPaymentConfig extends Model
{
    protected $table = 'church_payment_configs';
    
    protected $fillable = [
        'church_id',
        'provider',
        'public_key',
        'secret_key',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    protected $hidden = [
        'secret_key',
    ];

    /**
     * Encrypt secret key when storing
     */
    public function setSecretKeyAttribute($value)
    {
        $this->attributes['secret_key'] = Crypt::encrypt($value);
    }

    /**
     * Decrypt secret key when retrieving
     */
    public function getSecretKeyAttribute($value)
    {
        try {
            return Crypt::decrypt($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the masked secret key for display
     */
    public function getMaskedSecretKeyAttribute()
    {
        if (!$this->secret_key) {
            return null;
        }
        
        $key = $this->getOriginal('secret_key');
        try {
            $decrypted = Crypt::decrypt($key);
            return str_repeat('*', strlen($decrypted) - 8) . substr($decrypted, -4);
        } catch (\Exception $e) {
            return '****';
        }
    }

    /**
     * Relationship to Church
     */
    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id', 'ChurchID');
    }

    /**
     * Check if config is complete
     */
    public function isComplete()
    {
        return !empty($this->public_key) && !empty($this->secret_key);
    }

    /**
     * Validate PayMongo keys format
     */
    public static function validatePayMongoKeys($publicKey, $secretKey)
    {
        $errors = [];

        // Public key should start with pk_test_ or pk_live_
        if (!preg_match('/^pk_(test|live)_[a-zA-Z0-9]+$/', $publicKey)) {
            $errors['public_key'] = 'Invalid PayMongo public key format';
        }

        // Secret key should start with sk_test_ or sk_live_
        if (!preg_match('/^sk_(test|live)_[a-zA-Z0-9]+$/', $secretKey)) {
            $errors['secret_key'] = 'Invalid PayMongo secret key format';
        }

        // Both keys should be from same environment
        $publicEnv = strpos($publicKey, 'pk_test_') === 0 ? 'test' : 'live';
        $secretEnv = strpos($secretKey, 'sk_test_') === 0 ? 'test' : 'live';

        if ($publicEnv !== $secretEnv) {
            $errors['environment'] = 'Public and secret keys must be from the same environment (test or live)';
        }

        return $errors;
    }
}