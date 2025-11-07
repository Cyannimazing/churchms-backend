<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInputField extends Model
{
    protected $table = 'service_input_field';
    protected $primaryKey = 'InputFieldID';
    
    protected $fillable = [
        'ServiceID',
        'Label',
        'InputType',
        'IsRequired',
        'Options',
        'Placeholder',
        'HelpText',
        'SortOrder',
        'element_id',
        'x_position',
        'y_position',
        'width',
        'height',
        'z_index',
        'text_content',
        'text_size',
        'text_align',
        'text_color',
        'textarea_rows',
    ];
    
    protected $casts = [
        'ServiceID' => 'integer',
        'IsRequired' => 'boolean',
        'Options' => 'array',
        'SortOrder' => 'integer',
        'x_position' => 'integer',
        'y_position' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'z_index' => 'integer',
        'textarea_rows' => 'integer',
    ];
    
    // Input type constants for validation
    const INPUT_TYPES = [
        'text' => 'Text Input',
        'textarea' => 'Text Area',
        'email' => 'Email',
        'phone' => 'Phone Number',
        'number' => 'Number',
        'date' => 'Date',
        'datetime' => 'Date & Time',
        'select' => 'Dropdown',
        'checkbox' => 'Checkbox',
        'radio' => 'Radio Button',
        'file' => 'File Upload',
        'url' => 'URL',
        'password' => 'Password',
    ];
    
    /**
     * Get the sacrament service that owns this input field.
     */
    public function sacramentService(): BelongsTo
    {
        return $this->belongsTo(SacramentService::class, 'ServiceID', 'ServiceID');
    }
    
    /**
     * Scope for required fields only.
     */
    public function scopeRequired($query)
    {
        return $query->where('IsRequired', true);
    }
    
    /**
     * Scope for optional fields only.
     */
    public function scopeOptional($query)
    {
        return $query->where('IsRequired', false);
    }
    
    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('SortOrder');
    }
}
