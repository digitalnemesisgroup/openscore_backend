<?php

$autoloaderPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];

$autoloaderPath = null;
foreach ($autoloaderPaths as $path) {
    if (file_exists($path)) {
        $autoloaderPath = $path;
        break;
    }
}
require $autoloaderPath;

$appPaths = [
    __DIR__ . '/bootstrap/app.php',
    __DIR__ . '/../bootstrap/app.php',
    dirname(__DIR__) . '/bootstrap/app.php',
];

$appPath = null;
foreach ($appPaths as $path) {
    if (file_exists($path)) {
        $appPath = $path;
        break;
    }
}
$app = require_once $appPath;
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\LoanApplication;
use App\Models\UserWalletCard;
use Illuminate\Support\Facades\DB;

echo "Seeding user loan applications...\n";

// Find or create user
$user = User::where('mobile', 'like', '%8516%')
    ->orWhere('email', 'like', '%avisekh%')
    ->orWhere('role', 'user')
    ->first();

if (!$user) {
    $user = User::create([
        'name' => 'Avisekh Kumar Tewari',
        'email' => 'avisekh@gmail.com',
        'mobile' => '9876548516',
        'password' => bcrypt('password123'),
        'role' => 'user',
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123456789012',
    ]);
}

$mobile = $user->mobile ?: '9876548516';
$name = $user->name ?: 'Avisekh Kumar Tewari';

// 1. Virtual Credit Line Loan (Approved - ₹30,000)
$virtApp = LoanApplication::updateOrCreate(
    [
        'application_number' => 'OSV-30000',
    ],
    [
        'user_id' => $user->id,
        'loan_type' => 'virtual_loan',
        'loan_category' => 'virtual',
        'full_name' => $name,
        'dob' => '1995-05-15',
        'gender' => 'male',
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123456789012',
        'employment_type' => 'salaried',
        'monthly_income' => 45000,
        'address' => 'Flat 402, Royal Residency',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'pin_code' => '400001',
        'mobile_number' => $mobile,
        'email' => $user->email ?: 'avisekh@gmail.com',
        'selected_amount' => 30000,
        'required_amount' => 30000,
        'approved_amount' => 30000,
        'selected_tenure' => 90,
        'tenure_months' => 3,
        'status' => 'approved',
        'final_decision' => 'APPROVED',
        'disbursement_status' => 'credited',
        'payment_status' => 'verified',
        'fee_payment_status' => 'approved',
        'processing_fee' => 199,
        'fee_amount' => 199,
        'selected_partner_name' => 'OpenScore Smart Value Virtual Line',
    ]
);

// 2. Elite Personal Cash Loan (In Review / Processing - ₹50,000)
$eliteApp = LoanApplication::updateOrCreate(
    [
        'application_number' => 'ECL-50821',
    ],
    [
        'user_id' => $user->id,
        'loan_type' => 'elite_cash_loan',
        'loan_category' => 'cash',
        'full_name' => $name,
        'dob' => '1995-05-15',
        'gender' => 'male',
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123456789012',
        'employment_type' => 'salaried',
        'monthly_income' => 45000,
        'address' => 'Flat 402, Royal Residency',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'pin_code' => '400001',
        'mobile_number' => $mobile,
        'email' => $user->email ?: 'avisekh@gmail.com',
        'selected_amount' => 50000,
        'required_amount' => 50000,
        'approved_amount' => 50000,
        'selected_tenure' => 12,
        'tenure_months' => 12,
        'status' => 'in_review',
        'final_decision' => 'PENDING',
        'payment_status' => 'paid',
        'fee_payment_status' => 'verified',
        'processing_fee' => 499,
        'fee_amount' => 499,
        'selected_partner_name' => 'OpenScore Express Direct NBFC',
    ]
);

// 3. Urgent Construction Loan (In Progress - ₹2,50,000)
$constApp = LoanApplication::updateOrCreate(
    [
        'application_number' => 'UCL-89210',
    ],
    [
        'user_id' => $user->id,
        'loan_type' => 'urgent_construction_loan',
        'loan_category' => 'construction',
        'full_name' => $name,
        'dob' => '1995-05-15',
        'gender' => 'male',
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123456789012',
        'employment_type' => 'salaried',
        'monthly_income' => 45000,
        'address' => 'Flat 402, Royal Residency',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'pin_code' => '400001',
        'mobile_number' => $mobile,
        'email' => $user->email ?: 'avisekh@gmail.com',
        'selected_amount' => 250000,
        'required_amount' => 250000,
        'approved_amount' => 250000,
        'selected_tenure' => 24,
        'tenure_months' => 24,
        'status' => 'proof_submitted',
        'final_decision' => 'PENDING',
        'payment_status' => 'paid',
        'fee_payment_status' => 'verified',
        'processing_fee' => 999,
        'fee_amount' => 999,
        'selected_partner_name' => 'OpenScore Infrastructure Lending Partner',
    ]
);

// User wallet card check
UserWalletCard::updateOrCreate(
    [
        'user_id' => $user->id,
    ],
    [
        'available_value' => 30000,
        'card_holder_name' => strtoupper($name),
        'card_number' => '4734 8912 1805 8516',
        'mobile' => $mobile,
        'bank_name' => 'IDFC FIRST Bank',
        'bank_account_number' => '9123',
        'is_locked' => false,
        'is_admin_approved' => true,
    ]
);

echo "✓ Successfully seeded 3 loan applications and wallet card for {$name} ({$mobile})\n";
