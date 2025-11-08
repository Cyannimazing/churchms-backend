<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    protected $fillable = [
        'church_id',
        'name',
        'imagePath',
    ];

    protected $appends = ['imageUrl'];

    public function church()
    {
        return $this->belongsTo(Church::class, 'church_id', 'ChurchID');
    }

    public function getImageUrlAttribute()
    {
        if ($this->imagePath) {
            $supabaseUrl = config('supabase.url');
            $bucket = config('supabase.storage_bucket');
            return "{$supabaseUrl}/storage/v1/object/public/{$bucket}/{$this->imagePath}";
        }
        return null;
    }
}
