<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_application_id',
        'selected_partner_id',
        'selected_partner_name',
        'partner_locked',
        'bank_application_no',
        'proof_screenshot',
        'bank_portal_status',
        'proof_remarks',
        'selfie_with_agent',
    ];

    protected $casts = [
        'partner_locked' => 'boolean',
    ];

    public function loanApplication()
    {
        return $this->belongsTo(LoanApplication::class);
    }
}

