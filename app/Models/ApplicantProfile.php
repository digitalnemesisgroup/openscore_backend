<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_application_id',
        'user_id',
        'full_name',
        'dob',
        'mobile_number',
        'email',
        'pan_number',
        'aadhaar_number',
        'gender',
        'address',
        'city',
        'state',
        'pin_code',
        'employment_type',
        'company_name',
        'monthly_income',
        'existing_emi',
        'work_experience',
        'required_amount',
        'loan_purpose',
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

