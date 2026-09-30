<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DisbursementDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_application_id',
        'user_id',
        'bank_account_holder_name',
        'bank_name',
        'bank_account_number',
        'bank_ifsc_code',
        'bank_account_type',
        'disbursement_status',
        'disbursement_reference_no',
        'disbursed_at',
    ];

    protected $casts = [
        'disbursed_at' => 'datetime',
    ];

    public function loanApplication()
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

