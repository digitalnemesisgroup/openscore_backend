<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmtpPool extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'password',
        'purpose', // 'otp' or 'general'
        'host',
        'port',
        'encryption',
        'is_active',
        'dispatch_count',
        'last_dispatched_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_dispatched_at' => 'datetime',
    ];
}
