<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserWalletCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mobile',
        'card_number',
        'card_holder_name',
        'valid_thru',
        'available_value',
        'incremental_value',
        'daily_increment',
        'verifying_status',
        'card_type',
        'bank_name',
        'bank_account_number',
        'bank_reference_no',
        'bank_account_holder_name',
        'bank_ifsc_code',
        'settlement_status',
    ];

    protected $casts = [
        'available_value' => 'float',
        'incremental_value' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

