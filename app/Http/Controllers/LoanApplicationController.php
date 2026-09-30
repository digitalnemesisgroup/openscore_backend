<?php

namespace App\Http\Controllers;

use App\Models\LoanApplication;
use App\Models\ApplicantProfile;
use App\Models\DisbursementDetail;
use App\Models\PartnerSubmission;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LoanApplicationController extends Controller
{
    /**
     * Submit applicant details & compute indicative loan eligibility
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'loan_type' => 'required|string',
            'consent_accepted' => 'required|boolean',
            'full_name' => 'required|string|max:255',
            'dob' => 'required|string',
            'mobile_number' => 'required|string|max:15',
            'email' => 'required|email',
            'pan_number' => 'required|string|max:10',
            'aadhaar_number' => 'required|string|max:14',
            'gender' => 'required|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'pin_code' => 'nullable|string',
            'employment_type' => 'required|string',
            'company_name' => 'nullable|string',
            'monthly_income' => 'required|numeric|min:1000',
            'existing_emi' => 'nullable|numeric',
            'work_experience' => 'nullable|string',
            'required_amount' => 'required|numeric|min:5000',
            'loan_purpose' => 'nullable|string',
        ]);

        $required = (float) $validated['required_amount'];
        $monthlyIncome = (float) $validated['monthly_income'];
        $loanType = $validated['loan_type'];
        $cibilType = $request->input('cibil_type', '');

        if ($loanType === 'no_cibil') {
            $maxLimit = 250000; // ₹2,50,000 (2.5 Lakh)
            $reqCapped = min($required, $maxLimit);
        } else if ($loanType === 'low_cibil') {
            $maxLimit = 400000; // ₹4,00,000 (4 Lakh)
            $reqCapped = min($required, $maxLimit);
        } else if (str_contains($loanType, 'construction')) {
            if ($loanType === 'construction_no_cibil' || $cibilType === 'no_cibil') {
                $maxLimit = 1000000; // ₹10,00,000 (10 Lakh)
                $foirRate = 0.25; // 25% (range 20%-30%)
            } else if ($loanType === 'construction_low_cibil' || $cibilType === 'low_cibil') {
                $maxLimit = 4000000; // ₹40,00,000 (40 Lakh)
                $foirRate = 0.30; // 30% (range 25%-35%)
            } else {
                $maxLimit = 10000000; // ₹1,00,00,000 (1 Crore)
                $foirRate = 0.35; // 35% (range 30%-40%)
            }
            $foirValue = round($monthlyIncome * $foirRate * 36);
            $reqCapped = min($maxLimit, max($required, $foirValue));
        } else {
            $maxLimit = 5000000; // ₹50,00,000 (50 Lakh)
            $reqCapped = min($required, $maxLimit);
        }

        // Calculate 65% to 80% sanctioned amount
        $minAmount = round($reqCapped * 0.65);
        $maxAmount = round($reqCapped * 0.80);

        $userId = $request->user() ? $request->user()->id : null;
        $cleanMobile = preg_replace('/[^0-9]/', '', $validated['mobile_number']);

        // Check if user is under active re-application cooldown window
        $activeLockQuery = LoanApplication::where(function ($q) use ($userId, $cleanMobile) {
            if ($userId) {
                $q->where('user_id', $userId);
                if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
            } elseif ($cleanMobile) {
                $q->where('mobile_number', $cleanMobile);
            }
        })->where('reapply_locked_until', '>', now());

        $activeLock = $activeLockQuery->orderBy('reapply_locked_until', 'desc')->first();
        if ($activeLock) {
            $cooldownDays = self::getCooldownDays();
            return response()->json([
                'status' => 'error',
                'message' => 'Re-application Cooldown Active (Policy: ' . $cooldownDays . ' Days). You can re-apply on or after ' . $activeLock->reapply_locked_until->format('d M Y, h:i A') . '.',
                'reapply_locked_until' => $activeLock->reapply_locked_until->toIso8601String(),
                'cooldown_days' => $cooldownDays,
            ], 422);
        }

        // Check if an active uncompleted application already exists for this category
        $isNewConstruction = str_contains($validated['loan_type'], 'construction');

        $existingAppQuery = LoanApplication::where(function ($q) {
            $q->whereNull('final_decision')->orWhereNotIn('final_decision', ['REJECTED', 'APPROVED']);
        })->whereNotIn('status', ['rejected', 'disbursed', 'cancelled']);

        if ($isNewConstruction) {
            $existingAppQuery->where('loan_type', 'LIKE', '%construction%');
        } else {
            $existingAppQuery->where('loan_type', 'NOT LIKE', '%construction%');
        }

        if ($userId) {
            $existingAppQuery->where(function ($q) use ($userId, $cleanMobile) {
                $q->where('user_id', $userId);
                if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
            });
        } elseif ($cleanMobile) {
            $existingAppQuery->where('mobile_number', $cleanMobile);
        }

        $existingApp = $existingAppQuery->latest()->first();

        if ($existingApp && !$request->input('force_new')) {
            if ($request->filled('loan_type')) {
                $existingApp->loan_type = $validated['loan_type'];
            }
            if ($request->filled('cibil_type')) {
                $existingApp->cibil_type = $request->input('cibil_type');
            }
            if ($request->filled('monthly_income')) {
                $existingApp->monthly_income = $monthlyIncome;
            }
            if ($request->filled('required_amount')) {
                $existingApp->required_amount = $required;
                $existingApp->requested_amount = $required;
            }
            if ($request->filled('full_name')) {
                $existingApp->full_name = $validated['full_name'];
            }
            $existingApp->indicative_min_amount = $minAmount;
            $existingApp->indicative_max_amount = $maxAmount;
            $existingApp->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Active loan application updated and resumed from database.',
                'data' => $existingApp,
                'resumed' => true,
            ], 200);
        }

        // Only cancel previous if force_new is true
        if ($request->input('force_new')) {
            $cancelQuery = LoanApplication::where('final_decision', '!=', 'APPROVED')
                ->where('status', '!=', 'disbursed');

            if ($isNewConstruction) {
                $cancelQuery->where('loan_type', 'LIKE', '%construction%');
            } else {
                $cancelQuery->where('loan_type', 'NOT LIKE', '%construction%');
            }

            if ($userId) {
                $cancelQuery->where(function ($q) use ($userId, $cleanMobile) {
                    $q->where('user_id', $userId);
                    if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
                });
            } elseif ($cleanMobile) {
                $cancelQuery->where('mobile_number', $cleanMobile);
            }

            $cancelQuery->update([
                'final_decision' => 'REJECTED',
                'status' => 'rejected',
                'rejection_reason' => 'Cancelled by User (Started New ' . ($isNewConstruction ? 'Construction' : 'Cash') . ' Application)',
            ]);
        }

        $loanApp = LoanApplication::create([
            'user_id' => $userId,
            'loan_type' => $validated['loan_type'],
            'consent_accepted' => $validated['consent_accepted'],
            'full_name' => $validated['full_name'],
            'dob' => $validated['dob'],
            'mobile_number' => $validated['mobile_number'],
            'email' => $validated['email'],
            'pan_number' => strtoupper($validated['pan_number']),
            'aadhaar_number' => $validated['aadhaar_number'],
            'gender' => $validated['gender'],
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'pin_code' => $validated['pin_code'] ?? null,
            'employment_type' => $validated['employment_type'],
            'company_name' => $validated['company_name'] ?? null,
            'monthly_income' => $validated['monthly_income'],
            'existing_emi' => $validated['existing_emi'] ?? 0,
            'work_experience' => $validated['work_experience'] ?? null,
            'required_amount' => $validated['required_amount'],
            'selected_amount' => $maxAmount,
            'loan_purpose' => $validated['loan_purpose'] ?? null,
            'indicative_min_amount' => $minAmount,
            'indicative_max_amount' => $maxAmount,
            'indicative_interest_rate' => 8.5,
            'processing_fee' => self::calculateApplicableFee($validated['loan_type'], $required, $cibilType),
            'fee_amount' => self::calculateApplicableFee($validated['loan_type'], $required, $cibilType),
            'payment_upi_id' => SystemSetting::get('upi_id', 'flipflops@upi'),
            'payment_status' => 'unpaid',
            'status' => 'indicative_approved',
        ]);

        ApplicantProfile::updateOrCreate(
            ['loan_application_id' => $loanApp->id],
            [
                'user_id' => $userId,
                'full_name' => $validated['full_name'],
                'dob' => $validated['dob'],
                'mobile_number' => $validated['mobile_number'],
                'email' => $validated['email'],
                'pan_number' => strtoupper($validated['pan_number']),
                'aadhaar_number' => $validated['aadhaar_number'],
                'gender' => $validated['gender'],
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'pin_code' => $validated['pin_code'] ?? null,
                'employment_type' => $validated['employment_type'],
                'company_name' => $validated['company_name'] ?? null,
                'monthly_income' => $validated['monthly_income'],
                'existing_emi' => $validated['existing_emi'] ?? 0,
                'work_experience' => $validated['work_experience'] ?? null,
                'required_amount' => $validated['required_amount'],
                'loan_purpose' => $validated['loan_purpose'] ?? null,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Indicative eligibility calculated successfully',
            'data' => $loanApp,
        ], 201);
    }

    public function trackApplication(Request $request)
    {
        $query = trim($request->get('query', ''));
        if (empty($query)) {
            return response()->json(['status' => 'error', 'message' => 'Please provide an application number, loan ID or mobile number.'], 422);
        }

        $cleanMobile = preg_replace('/[^0-9]/', '', $query);

        $app = LoanApplication::where(function($q) use ($query, $cleanMobile) {
            $q->where('application_number', $query)
              ->orWhere('application_no', $query)
              ->orWhere('id', $query)
              ->orWhere('transaction_id', $query);
            if (strlen($cleanMobile) >= 10) {
                $q->orWhere('mobile_number', $cleanMobile);
            }
        })->latest()->first();

        if (!$app) {
            return response()->json(['status' => 'error', 'message' => 'No loan application found matching this reference.'], 404);
        }

        $isUrgentConst = $app->loan_type === 'urgent_construction_loan';
        $isEliteCash = $app->loan_type === 'elite_cash_loan';

        return response()->json([
            'status' => 'success',
            'data' => $app,
            'is_urgent' => (bool) $app->is_urgent,
            'loan_type' => $app->loan_type,
            'tracker_url' => $isEliteCash
                ? "/loan/apply/cash-loan/elite/status?id={$app->id}"
                : ($isUrgentConst
                    ? "/loan/apply/construction-loan/urgent/status?id={$app->id}"
                    : "/loan/my-loans/details?id={$app->id}"),
        ]);
    }

    /**
     * Get user's existing saved personal profile (from previous loan applications or profile)
     */
    public function getApplicantProfile(Request $request)
    {
        $user = $request->user();
        $mobile = $request->query('mobile');
        if (!$mobile && $user) {
            $mobile = $user->mobile;
        }

        $profile = null;

        if ($user) {
            $profile = ApplicantProfile::where('user_id', $user->id)
                ->whereNotNull('pan_number')
                ->where('pan_number', '!=', '')
                ->where('pan_number', '!=', 'ABCDE1234F')
                ->latest()
                ->first();
        }

        if (!$profile && $mobile) {
            $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
            $profile = ApplicantProfile::where('mobile_number', $cleanMobile)
                ->whereNotNull('pan_number')
                ->where('pan_number', '!=', '')
                ->where('pan_number', '!=', 'ABCDE1234F')
                ->latest()
                ->first();
        }

        if (!$profile) {
            $loanQuery = LoanApplication::query();
            if ($user) {
                $loanQuery->where('user_id', $user->id);
            } elseif ($mobile) {
                $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
                $loanQuery->where('mobile_number', $cleanMobile);
            }

            $loanApp = $loanQuery->whereNotNull('pan_number')
                ->where('pan_number', '!=', '')
                ->where('pan_number', '!=', 'ABCDE1234F')
                ->latest()
                ->first();

            if ($loanApp) {
                $profile = (object) [
                    'full_name' => $loanApp->full_name,
                    'dob' => $loanApp->dob,
                    'gender' => $loanApp->gender ?: 'Male',
                    'mobile_number' => $loanApp->mobile_number,
                    'email' => $loanApp->email,
                    'pan_number' => $loanApp->pan_number,
                    'aadhaar_number' => $loanApp->aadhaar_number,
                    'address' => $loanApp->address,
                    'city' => $loanApp->city,
                    'state' => $loanApp->state,
                    'pin_code' => $loanApp->pin_code,
                    'employment_type' => $loanApp->employment_type,
                    'monthly_income' => $loanApp->monthly_income,
                ];
            }
        }

        $hasCompleteProfile = false;
        if ($profile) {
            $hasCompleteProfile = !empty($profile->full_name)
                && !empty($profile->dob)
                && !empty($profile->mobile_number)
                && !empty($profile->pan_number)
                && !empty($profile->aadhaar_number)
                && $profile->pan_number !== 'ABCDE1234F'
                && $profile->aadhaar_number !== '123456789012';
        }

        return response()->json([
            'status' => 'success',
            'has_saved_profile' => (bool) $hasCompleteProfile,
            'profile' => $profile ? [
                'full_name' => $profile->full_name,
                'dob' => $profile->dob,
                'gender' => $profile->gender ?: 'Male',
                'mobile_number' => $profile->mobile_number,
                'email' => $profile->email,
                'pan_number' => $profile->pan_number,
                'aadhaar_number' => $profile->aadhaar_number,
                'address' => $profile->address ?? null,
                'city' => $profile->city ?? null,
                'state' => $profile->state ?? null,
                'pin_code' => $profile->pin_code ?? null,
                'employment_type' => $profile->employment_type ?? 'Salaried',
                'monthly_income' => $profile->monthly_income ?? 50000,
            ] : null,
        ]);
    }

    /**
     * Repayment Breakdown Calculation
     */
    public function updateTenure(Request $request, $id)
    {
        $request->validate([
            'selected_tenure' => 'required|integer|min:6|max:360',
            'selected_amount' => 'nullable|numeric',
            'indicative_interest_rate' => 'nullable|numeric',
            'loan_type' => 'nullable|string',
            'cibil_type' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $amount = (float) ($request->selected_amount ?: $loanApp->selected_amount ?: $loanApp->indicative_max_amount ?: 500000);
        $tenure = (int) $request->selected_tenure;
        $annualRate = (float) ($request->indicative_interest_rate ?: $loanApp->indicative_interest_rate ?: 8.5);

        if ($request->filled('loan_type')) {
            $loanApp->loan_type = $request->loan_type;
        }
        if ($request->filled('cibil_type')) {
            $loanApp->cibil_type = $request->cibil_type;
        }

        $r = ($annualRate / 12) / 100;
        $emi = ($amount * $r * pow(1 + $r, $tenure)) / (pow(1 + $r, $tenure) - 1);
        $totalRepayment = $emi * $tenure;
        $totalInterest = $totalRepayment - $amount;

        $loanApp->selected_amount = $amount;
        $loanApp->selected_tenure = $tenure;
        $loanApp->tenure_months = $tenure;
        $loanApp->indicative_interest_rate = $annualRate;
        $loanApp->interest_rate_pa = $annualRate . '% p.a.';
        $loanApp->estimated_emi = round($emi, 2);
        $loanApp->monthly_emi = round($emi, 2);
        $loanApp->total_repayment = round($totalRepayment, 2);
        $loanApp->total_interest = round($totalInterest, 2);
        $loanApp->status = 'repayment_selected';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Repayment details calculated successfully',
            'data' => $loanApp,
        ]);
    }

    /**
     * Document Upload
     */
    public function uploadDocuments(Request $request, $id)
    {
        $request->validate([
            'documents' => 'required|array',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->documents_uploaded = $request->documents;
        $loanApp->documents_status = 'pending';
        $loanApp->status = 'documents_pending';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Documents uploaded successfully. Waiting for admin verification.',
            'data' => $loanApp,
        ]);
    }

    /**
     * Fee Payment & Unique Application Number Generation
     */
    public function processPayment(Request $request, $id)
    {
        $loanApp = LoanApplication::findOrFail($id);

        if (!$loanApp->application_number) {
            $loanApp->application_number = 'OSL20250917' . sprintf('%04d', $loanApp->id);
        }

        $txId = $request->input('transaction_id', $request->input('utr', 'TXN' . rand(100000000, 999999999)));
        $loanApp->transaction_id = $txId;
        if ($request->has('payment_screenshot') && !empty($request->input('payment_screenshot'))) {
            $loanApp->payment_screenshot = $request->input('payment_screenshot');
        } elseif ($request->hasFile('payment_screenshot')) {
            $path = $request->file('payment_screenshot')->store('payment_receipts', 'public');
            $loanApp->payment_screenshot = '/storage/' . $path;
        }

        $loanApp->payment_status = 'pending_verification';
        $loanApp->fee_payment_status = 'pending_approval';
        $loanApp->status = 'fee_submitted_pending_verification';
        $loanApp->fee_paid_at = now();

        $loanApp->partner_options = [
            ['id' => 'hdfc', 'name' => 'HDFC Bank', 'subtext' => 'Personal Loan', 'feature' => 'Quick Processing', 'status' => 'Available', 'is_active' => true],
            ['id' => 'icici', 'name' => 'ICICI Bank', 'subtext' => 'Personal Loan', 'feature' => 'Flexible Tenure', 'status' => 'Available', 'is_active' => true],
            ['id' => 'axis', 'name' => 'Axis Bank', 'subtext' => 'Personal Loan', 'feature' => 'Minimal Documents', 'status' => 'Available', 'is_active' => true],
            ['id' => 'kotak', 'name' => 'Kotak Mahindra Bank', 'subtext' => 'Personal Loan', 'feature' => 'Competitive Rates', 'status' => 'Available', 'is_active' => true],
            ['id' => 'bajaj', 'name' => 'Bajaj Finserv', 'subtext' => 'Personal Loan', 'feature' => 'Instant Process', 'status' => 'Available', 'is_active' => true],
            ['id' => 'tata', 'name' => 'Tata Capital', 'subtext' => 'Personal Loan', 'feature' => 'Higher Eligibility', 'status' => 'Available', 'is_active' => true],
        ];

        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Payment UTR submitted successfully. Waiting for Admin Verification before unlocking partner portal.',
            'data' => $loanApp,
        ]);
    }

    /**
     * Partner Verification & Single Partner Link Lock
     */
    public function verifyPartner(Request $request, $id)
    {
        $request->validate([
            'verify_input' => 'nullable|string',
            'partner_id' => 'nullable|string',
            'selected_partner_id' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);

        if ($request->has('verify_input') && !empty(trim($request->verify_input))) {
            $input = trim($request->verify_input);
            if ($input !== $loanApp->application_number && $input !== $loanApp->mobile_number) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Application Number or Registered Mobile does not match.',
                ], 422);
            }
        }

        $partnerId = $request->partner_id ?: $request->selected_partner_id ?: 'hdfc';
        $partnerNameMap = [
            'hdfc' => 'HDFC Bank',
            'hdfc_bank' => 'HDFC Bank Personal Loan',
            'icici' => 'ICICI Bank',
            'axis' => 'Axis Bank',
            'kotak' => 'Kotak Mahindra Bank',
            'bajaj' => 'Bajaj Finserv',
            'bajaj_finserv' => 'Bajaj Finserv Flexi Loan',
            'krazybee' => 'Krazybee NBFC Credit',
            'tata' => 'Tata Capital',
        ];

        $loanApp->selected_partner_id = $partnerId;
        $loanApp->selected_partner_name = $request->selected_partner_name ?? ($partnerNameMap[$partnerId] ?? 'Selected Partner Bank');
        $loanApp->partner_locked = true;
        $loanApp->lender_status = 'partner_verified';
        $loanApp->status = 'partner_verified';

        if (is_array($loanApp->partner_options)) {
            $updatedOptions = [];
            foreach ($loanApp->partner_options as $p) {
                if ($p['id'] === $partnerId) {
                    $p['status'] = 'Selected Partner';
                    $p['is_active'] = true;
                    $updatedOptions[] = $p;
                } else {
                    $p['status'] = 'Locked / Removed';
                    $p['is_active'] = false;
                    $updatedOptions[] = $p;
                }
            }
            $loanApp->partner_options = $updatedOptions;
        }
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Partner verified and locked successfully.',
            'data' => $loanApp,
        ]);
    }

    /**
     * Submit Process Completion Proof
     */
    public function submitProof(Request $request, $id)
    {
        $request->validate([
            'bank_application_no' => 'required|string',
            'bank_portal_status' => 'required|string',
            'proof_remarks' => 'nullable|string',
            'proof_screenshot' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->bank_application_no = $request->bank_application_no;
        $loanApp->bank_portal_status = $request->bank_portal_status;
        $loanApp->proof_remarks = $request->proof_remarks;
        $loanApp->proof_screenshot = $request->proof_screenshot ?: 'lender_confirmation.png';
        $loanApp->proof_status = 'pending';
        $loanApp->status = 'proof_pending';
        $loanApp->final_decision = 'PROCESSING';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Process completion proof submitted successfully. Waiting for admin verification.',
            'data' => $loanApp,
        ]);
    }

    /**
     * Submit Agent Selfie Verification
     */
    public function submitAgentSelfie(Request $request, $id)
    {
        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->selfie_with_agent = $request->selfie_with_agent ?: $request->agent_selfie ?: 'selfie_agent_photo.png';
        $loanApp->status = 'agent_verified';
        $loanApp->final_decision = 'APPROVED';
        $loanApp->approved_amount = $loanApp->selected_amount ?: 200000;
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Agent selfie verified and loan application APPROVED!',
            'data' => $loanApp,
        ]);
    }

    /**
     * Submit Disbursement Bank Details
     */
    public function submitDisbursementBank(Request $request, $id)
    {
        $request->validate([
            'bank_account_holder_name' => 'required|string',
            'bank_name' => 'required|string',
            'bank_account_number' => 'required|string',
            'bank_ifsc_code' => 'required|string',
            'bank_account_type' => 'required|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->bank_account_holder_name = $request->bank_account_holder_name;
        $loanApp->bank_name = $request->bank_name;
        $loanApp->bank_account_number = $request->bank_account_number;
        $loanApp->bank_ifsc_code = strtoupper($request->bank_ifsc_code);
        $loanApp->bank_account_type = $request->bank_account_type;
        $loanApp->bank_details_status = 'pending';
        $loanApp->disbursement_status = 'submitted';
        $loanApp->status = 'bank_details_pending';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Disbursement bank details submitted successfully. Waiting for admin verification.',
            'data' => $loanApp,
        ]);
    }

    // =========================================================================
    // ADMIN PANEL ENDPOINTS (15 Admin Screens Support)
    // =========================================================================

    public function adminStats()
    {
        $total = LoanApplication::count();
        $feePaid = LoanApplication::where('payment_status', 'paid')->count();
        $partnerSelected = LoanApplication::where('partner_locked', true)->count();
        $inReview = LoanApplication::whereIn('status', ['proof_pending', 'proof_submitted', 'bank_details_pending', 'under_review', 'additional_docs_submitted', 'cibil_tier_assigned', 'documents_pending'])->count();
        $approved = LoanApplication::where(function($q) {
            $q->where('final_decision', 'APPROVED')->orWhere('status', 'approved');
        })->count();
        $rejected = LoanApplication::where(function($q) {
            $q->where('final_decision', 'REJECTED')->orWhere('status', 'rejected')->orWhere('status', 'Rejected');
        })->count();
        $disbursed = LoanApplication::where(function($q) {
            $q->where('disbursement_status', 'credited')->orWhere('status', 'disbursed');
        })->count();
        $reapply = LoanApplication::whereNotNull('reapply_locked_until')->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_applications' => $total,
                'new_applications' => LoanApplication::whereIn('status', ['fee_payment_pending', 'indicative_approved', 'pending_partner_selection', 'documents_pending'])->count(),
                'in_review' => $inReview,
                'fee_paid' => $feePaid,
                'partner_selected' => $partnerSelected,
                'validation_pending' => $inReview,
                'document_pending' => LoanApplication::where('documents_status', 'pending')->count(),
                'processing' => max($total - $approved - $rejected, 0),
                'approved' => $approved,
                'rejected' => $rejected,
                'disbursement_pending' => max($approved - $disbursed, 0),
                'disbursed' => $disbursed,
                'reapply_3_days' => $reapply,
            ],
        ]);
    }

    public function adminApproveStage(Request $request, $id)
    {
        $request->validate([
            'stage' => 'required|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $stage = $request->stage;

        if ($stage === 'fee_payment') {
            $loanApp->fee_payment_status = 'approved';
            $loanApp->payment_status = 'approved';
            $loanApp->fee_payment_approved_at = now();

            if ($loanApp->loan_type === 'virtual_loan' || $loanApp->loan_category === 'virtual_loan') {
                $loanApp->final_decision = 'APPROVED';
                $loanApp->status = 'disbursed';
                $loanApp->disbursement_status = 'credited';
                $loanApp->disbursed_at = now();

                $mobile = preg_replace('/[^0-9]/', '', $loanApp->mobile_number);
                $wallet = \App\Models\UserWalletCard::where(function($q) use ($loanApp, $mobile) {
                    if ($loanApp->user_id) $q->where('user_id', $loanApp->user_id);
                    if ($mobile) $q->orWhere('mobile', $mobile);
                })->first();

                $loanAmount = (float) ($loanApp->selected_amount ?: $loanApp->required_amount ?: $loanApp->amount ?: 30000);

                if (!$wallet) {
                    $wallet = new \App\Models\UserWalletCard();
                    $wallet->user_id = $loanApp->user_id;
                    $wallet->mobile = $mobile ?: '9999999999';
                    $wallet->card_number = '4734 8912 ' . rand(1000, 9999) . ' ' . substr($mobile ?: '9999', -4);
                    $wallet->card_holder_name = strtoupper($loanApp->full_name ?: 'OPENSCORE USER');
                    $wallet->available_value = $loanAmount;
                    $wallet->bank_name = 'OpenScore Virtual Wallet';
                    $wallet->save();
                } else {
                    $wallet->available_value = $loanAmount;
                    $wallet->save();
                }

                \App\Models\WalletTransaction::create([
                    'transaction_id' => 'VLTX' . rand(100000, 999999),
                    'sender_user_id' => null,
                    'receiver_user_id' => $loanApp->user_id,
                    'receiver_card_id' => $wallet->id,
                    'amount' => $loanAmount,
                    'type' => 'credit',
                    'payment_method' => 'Virtual Loan Disbursal',
                    'recipient_identifier' => $wallet->mobile,
                    'recipient_name' => $wallet->card_holder_name,
                    'status' => 'success',
                    'remarks' => 'Virtual Loan Activation Credit (' . ($loanApp->application_number ?? $loanApp->application_no) . ')',
                ]);
            } else {
                $loanApp->status = 'submitted_to_partners';
            }
        } else if ($stage === 'site_selfie') {
            $loanApp->site_selfie_status = 'approved';
            $loanApp->site_selfie_approved_at = now();
            $loanApp->status = 'site_selfie_approved';
        } else if ($stage === 'proof' || $stage === 'lender_proof') {
            $loanApp->proof_status = 'approved';
            $loanApp->lender_proof_status = 'approved';
            $loanApp->proof_approved_at = now();
            $loanApp->status = 'proof_approved';
        } else if ($stage === 'bank_details') {
            $loanApp->bank_details_status = 'approved';
            $loanApp->bank_details_approved_at = now();
            $loanApp->status = 'bank_details_approved';
        } else if ($stage === 'additional_docs') {
            $loanApp->additional_docs_status = 'approved';
            $loanApp->additional_docs_approved_at = now();
            $loanApp->status = 'additional_docs_approved';
        } else if ($stage === 'documents' || $stage === 'initial_docs') {
            $loanApp->documents_status = 'approved';
            $loanApp->documents_approved_at = now();
            $loanApp->status = 'documents_approved';
        } else if ($stage === 'disbursement') {
            $loanApp->disbursement_status = 'credited';
            $loanApp->final_decision = 'APPROVED';
            $loanApp->status = 'disbursed';
            $loanApp->disbursed_at = now();

            if ($loanApp->loan_type === 'virtual_loan' || $loanApp->loan_category === 'virtual_loan') {
                $mobile = preg_replace('/[^0-9]/', '', $loanApp->mobile_number);
                $wallet = \App\Models\UserWalletCard::where(function($q) use ($loanApp, $mobile) {
                    if ($loanApp->user_id) $q->where('user_id', $loanApp->user_id);
                    if ($mobile) $q->orWhere('mobile', $mobile);
                })->first();

                $loanAmount = (float) ($loanApp->selected_amount ?: $loanApp->required_amount ?: $loanApp->amount ?: 30000);

                if (!$wallet) {
                    $wallet = new \App\Models\UserWalletCard();
                    $wallet->user_id = $loanApp->user_id;
                    $wallet->mobile = $mobile ?: '9999999999';
                    $wallet->card_number = '4734 8912 ' . rand(1000, 9999) . ' ' . substr($mobile ?: '9999', -4);
                    $wallet->card_holder_name = strtoupper($loanApp->full_name ?: 'OPENSCORE USER');
                    $wallet->available_value = $loanAmount;
                    $wallet->bank_name = 'OpenScore Virtual Wallet';
                    $wallet->save();
                } else {
                    $wallet->available_value = $loanAmount;
                    $wallet->save();
                }
            }
        }

        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Stage (' . $stage . ') approved by admin successfully',
            'data' => $loanApp,
        ]);
    }

    public function adminApproveAllDocuments(Request $request, $id)
    {
        $loanApp = LoanApplication::findOrFail($id);
        $docs = is_array($loanApp->documents_uploaded) 
            ? $loanApp->documents_uploaded 
            : (json_decode($loanApp->documents_uploaded, true) ?: []);

        $updatedDocs = [];
        foreach ($docs as $k => $v) {
            if (is_array($v)) {
                $v['status'] = 'approved';
                $v['rejection_reason'] = null;
                $updatedDocs[$k] = $v;
            } else {
                $updatedDocs[$k] = ['uploaded' => true, 'status' => 'approved'];
            }
        }

        $loanApp->documents_uploaded = $updatedDocs;
        $loanApp->documents_status = 'approved';
        $loanApp->documents_approved_at = now();
        $loanApp->status = 'documents_approved';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'All uploaded documents approved successfully by admin.',
            'data' => $loanApp,
        ]);
    }

    public function adminUpdateDocumentItem(Request $request, $id)
    {
        $request->validate([
            'doc_key' => 'required|string',
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $docKey = $request->doc_key;
        $action = $request->action;
        $reason = $request->rejection_reason;

        $docs = is_array($loanApp->documents_uploaded) 
            ? $loanApp->documents_uploaded 
            : (json_decode($loanApp->documents_uploaded, true) ?: []);

        if (!isset($docs[$docKey])) {
            $docs[$docKey] = ['uploaded' => true];
        }

        if (is_string($docs[$docKey])) {
            $docs[$docKey] = ['uploaded' => true, 'name' => $docs[$docKey]];
        }

        if ($action === 'approve') {
            $docs[$docKey]['status'] = 'approved';
            $docs[$docKey]['rejection_reason'] = null;
        } else {
            $docs[$docKey]['status'] = 'rejected';
            $docs[$docKey]['rejection_reason'] = $reason ?: 'Document invalid or illegible. Please re-upload.';
        }

        $loanApp->documents_uploaded = $docs;

        // Check if any rejected or all approved
        $hasRejected = false;
        $allApproved = true;
        foreach ($docs as $k => $v) {
            $st = is_array($v) ? ($v['status'] ?? 'pending') : 'pending';
            if ($st === 'rejected') $hasRejected = true;
            if ($st !== 'approved') $allApproved = false;
        }

        if ($hasRejected) {
            $loanApp->documents_status = 'rejected_items';
        } else if ($allApproved) {
            $loanApp->documents_status = 'approved';
            $loanApp->documents_approved_at = now();
            $loanApp->status = 'documents_approved';
        } else {
            $loanApp->documents_status = 'pending';
        }

        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => "Document {$docKey} updated to {$action} successfully.",
            'data' => $loanApp,
        ]);
    }

    public function adminApproveCibilTier(Request $request, $id)
    {
        $request->validate([
            'cibil_tier' => 'required|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $tier = $request->cibil_tier;

        if ($tier === 'high_cibil' || $tier === 'good_cibil') {
            $assignedTier = 'good_cibil';
            $tierName = 'High CIBIL';
        } else {
            $assignedTier = 'low_cibil';
            $tierName = 'Low CIBIL';
        }

        if (str_contains($loanApp->loan_type, 'construction')) {
            $loanApp->loan_type = 'construction_' . $assignedTier;
        } else {
            $loanApp->loan_type = $assignedTier;
        }

        $loanApp->fee_payment_status = 'approved';
        $loanApp->payment_status = 'approved';
        $loanApp->cibil_tier_assigned = $assignedTier;
        $loanApp->status = 'cibil_tier_assigned';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Without CIBIL application approved by admin and assigned to ' . $tierName . ' tier.',
            'data' => $loanApp,
        ]);
    }

    public function adminRequestDocs(Request $request, $id)
    {
        $request->validate([
            'requested_docs' => 'required',
            'admin_remark' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->final_decision = 'ADDITIONAL_DOCS';
        $loanApp->status = 'additional_docs_required';

        if (is_array($request->requested_docs)) {
            $loanApp->additional_docs_request = json_encode($request->requested_docs);
        } else {
            $loanApp->additional_docs_request = $request->requested_docs;
        }

        if ($request->has('admin_remark')) {
            $loanApp->proof_remarks = $request->admin_remark;
        }
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Additional document request sent to user successfully',
            'data' => $loanApp,
        ]);
    }

    public function uploadAdditionalDocs(Request $request, $id)
    {
        $request->validate([
            'submitted_docs' => 'required',
            'remarks' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->additional_docs_submitted = is_array($request->submitted_docs) 
            ? json_encode($request->submitted_docs) 
            : $request->submitted_docs;
            
        $loanApp->status = 'additional_docs_submitted';
        $loanApp->final_decision = 'PROCESSING';
        if ($request->has('remarks')) {
            $loanApp->proof_remarks = $request->remarks;
        }
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Additional documents submitted successfully for admin review.',
            'data' => $loanApp,
        ]);
    }

    public static function calculateApplicableFee(string $loanType, float $principal, ?string $cibilType = null): float
    {
        // 1. Virtual Card / Loan / Voucher (Single Activation / Processing Fee)
        if (str_contains($loanType, 'virtual') || str_contains($loanType, 'voucher')) {
            $type = SystemSetting::get('virtual_loan_fee_type', 'fixed');
            $val = (float) SystemSetting::get('virtual_loan_fee_value', 299);
            return ($type === 'percentage') ? max(1, round($principal * ($val / 100), 2)) : $val;
        }

        // 2. Construction Loan (3 Tiers: Without CIBIL, Low CIBIL, High CIBIL >700)
        if (str_contains($loanType, 'construction')) {
            $isHigh = ($loanType === 'construction_good_cibil' || $loanType === 'construction_high_cibil' || $cibilType === 'good_cibil' || $cibilType === 'high_cibil');
            $isLow = ($loanType === 'construction_low_cibil' || $cibilType === 'low_cibil');

            if ($isHigh) {
                $type = SystemSetting::get('construction_loan_high_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed'));
                $val = (float) SystemSetting::get('construction_loan_high_cibil_fee_value', 499);
            } elseif ($isLow) {
                $type = SystemSetting::get('construction_loan_low_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed'));
                $val = (float) SystemSetting::get('construction_loan_low_cibil_fee_value', 999);
            } else {
                // Without CIBIL (or default construction loan)
                $type = SystemSetting::get('construction_loan_without_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed'));
                $val = (float) SystemSetting::get('construction_loan_without_cibil_fee_value', SystemSetting::get('construction_loan_fee_value', 999));
            }
            return ($type === 'percentage') ? max(1, round($principal * ($val / 100), 2)) : $val;
        }

        // 3. Cash Loan (3 Tiers: Without CIBIL, Low CIBIL, High CIBIL >700)
        $isHigh = ($loanType === 'good_cibil' || $loanType === 'high_cibil' || $cibilType === 'good_cibil' || $cibilType === 'high_cibil');
        $isLow = ($loanType === 'low_cibil' || $cibilType === 'low_cibil');

        if ($isHigh) {
            $type = SystemSetting::get('cash_loan_high_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed'));
            $val = (float) SystemSetting::get('cash_loan_high_cibil_fee_value', SystemSetting::get('cash_loan_good_cibil_fee_value', 499));
        } elseif ($isLow) {
            $type = SystemSetting::get('cash_loan_low_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed'));
            $val = (float) SystemSetting::get('cash_loan_low_cibil_fee_value', 999);
        } else {
            // Without CIBIL (or default cash loan)
            $type = SystemSetting::get('cash_loan_without_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed'));
            $val = (float) SystemSetting::get('cash_loan_without_cibil_fee_value', SystemSetting::get('cash_loan_fee_value', 999));
        }

        return ($type === 'percentage') ? max(1, round($principal * ($val / 100), 2)) : $val;
    }

    public static function getCooldownDays()
    {
        return (int) SystemSetting::get('reapplication_cooldown_days', 3);
    }

    public function getCooldownSettings()
    {
        $days = self::getCooldownDays();
        return response()->json([
            'status' => 'success',
            'data' => [
                'reapplication_cooldown_days' => $days,
            ],
        ]);
    }

    public function updateCooldownSettings(Request $request)
    {
        $request->validate([
            'reapplication_cooldown_days' => 'required|integer|min:0|max:365',
        ]);

        $days = (int) $request->reapplication_cooldown_days;
        SystemSetting::set('reapplication_cooldown_days', $days);

        return response()->json([
            'status' => 'success',
            'message' => "Re-application cooldown updated to {$days} days",
            'data' => [
                'reapplication_cooldown_days' => $days,
            ],
        ]);
    }

    public function getFeeConfig()
    {
        $cashLoginFee = (float) SystemSetting::get('cash_loan_login_fee', 500);
        $cashDocFee = (float) SystemSetting::get('cash_loan_doc_fee', 200);
        $cashVerifFee = (float) SystemSetting::get('cash_loan_verification_fee', 299);
        $cashTotalFee = $cashLoginFee + $cashDocFee + $cashVerifFee;

        $constLoginFee = (float) SystemSetting::get('construction_loan_login_fee', 500);
        $constDocFee = (float) SystemSetting::get('construction_loan_doc_fee', 300);
        $constSiteFee = (float) SystemSetting::get('construction_loan_site_verification_fee', 699);
        $constTotalFee = $constLoginFee + $constDocFee + $constSiteFee;

        return response()->json([
            'status' => 'success',
            'data' => [
                'upi_id' => SystemSetting::get('upi_id', 'flipflops@upi'),
                'upi_payee_name' => SystemSetting::get('upi_payee_name', 'OpenScore Finance'),

                // Cash Loan / Elite Loan Itemized Breakdown
                'cash_loan_login_fee' => $cashLoginFee,
                'cash_loan_doc_fee' => $cashDocFee,
                'cash_loan_verification_fee' => $cashVerifFee,
                'cash_loan_total_processing_fee' => $cashTotalFee,
                'cash_loan_breakdown' => [
                    ['label' => 'Login / Portal Activation Fee', 'amount' => $cashLoginFee],
                    ['label' => 'Document & KYC Processing Fee', 'amount' => $cashDocFee],
                    ['label' => 'Express Risk & Sanction Verification Fee', 'amount' => $cashVerifFee],
                ],

                // Construction Loan / Urgent Construction Itemized Breakdown
                'construction_loan_login_fee' => $constLoginFee,
                'construction_loan_doc_fee' => $constDocFee,
                'construction_loan_site_verification_fee' => $constSiteFee,
                'construction_loan_total_processing_fee' => $constTotalFee,
                'construction_loan_breakdown' => [
                    ['label' => 'Application Login & Portal Registration', 'amount' => $constLoginFee],
                    ['label' => 'Document & Title Verification Fee', 'amount' => $constDocFee],
                    ['label' => 'Site & Technical Inspection Fee', 'amount' => $constSiteFee],
                ],

                // Cash Loan - 3 Tiers
                'cash_loan_without_cibil_fee_type' => SystemSetting::get('cash_loan_without_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed')),
                'cash_loan_without_cibil_fee_value' => (float) SystemSetting::get('cash_loan_without_cibil_fee_value', $cashTotalFee),
                'cash_loan_low_cibil_fee_type' => SystemSetting::get('cash_loan_low_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed')),
                'cash_loan_low_cibil_fee_value' => (float) SystemSetting::get('cash_loan_low_cibil_fee_value', $cashTotalFee),
                'cash_loan_high_cibil_fee_type' => SystemSetting::get('cash_loan_high_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed')),
                'cash_loan_high_cibil_fee_value' => (float) SystemSetting::get('cash_loan_high_cibil_fee_value', SystemSetting::get('cash_loan_good_cibil_fee_value', 499)),

                // Construction Loan - 3 Tiers
                'construction_loan_without_cibil_fee_type' => SystemSetting::get('construction_loan_without_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed')),
                'construction_loan_without_cibil_fee_value' => (float) SystemSetting::get('construction_loan_without_cibil_fee_value', $constTotalFee),
                'construction_loan_low_cibil_fee_type' => SystemSetting::get('construction_loan_low_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed')),
                'construction_loan_low_cibil_fee_value' => (float) SystemSetting::get('construction_loan_low_cibil_fee_value', $constTotalFee),
                'construction_loan_high_cibil_fee_type' => SystemSetting::get('construction_loan_high_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed')),
                'construction_loan_high_cibil_fee_value' => (float) SystemSetting::get('construction_loan_high_cibil_fee_value', 499),

                // Virtual Card / Loan / Voucher - Single Fee
                'virtual_loan_fee_type' => SystemSetting::get('virtual_loan_fee_type', 'fixed'),
                'virtual_loan_fee_value' => (float) SystemSetting::get('virtual_loan_fee_value', 299),

                // Legacy aliases for backward compatibility
                'cash_loan_fee_type' => SystemSetting::get('cash_loan_without_cibil_fee_type', SystemSetting::get('cash_loan_fee_type', 'fixed')),
                'cash_loan_fee_value' => (float) SystemSetting::get('cash_loan_without_cibil_fee_value', $cashTotalFee),
                'cash_loan_good_cibil_fee_value' => (float) SystemSetting::get('cash_loan_high_cibil_fee_value', SystemSetting::get('cash_loan_good_cibil_fee_value', 499)),
                'construction_loan_fee_type' => SystemSetting::get('construction_loan_without_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed')),
                'construction_loan_fee_value' => (float) SystemSetting::get('construction_loan_without_cibil_fee_value', $constTotalFee),
            ],
        ]);
    }

    public function updateFeeConfig(Request $request)
    {
        $request->validate([
            'upi_id' => 'nullable|string',
            'upi_payee_name' => 'nullable|string',

            // Itemized breakdown
            'cash_loan_login_fee' => 'nullable|numeric|min:0',
            'cash_loan_doc_fee' => 'nullable|numeric|min:0',
            'cash_loan_verification_fee' => 'nullable|numeric|min:0',

            'construction_loan_login_fee' => 'nullable|numeric|min:0',
            'construction_loan_doc_fee' => 'nullable|numeric|min:0',
            'construction_loan_site_verification_fee' => 'nullable|numeric|min:0',

            'cash_loan_without_cibil_fee_type' => 'nullable|string|in:fixed,percentage',
            'cash_loan_without_cibil_fee_value' => 'nullable|numeric|min:0',
            'cash_loan_low_cibil_fee_type' => 'nullable|string|in:fixed,percentage',
            'cash_loan_low_cibil_fee_value' => 'nullable|numeric|min:0',
            'cash_loan_high_cibil_fee_type' => 'nullable|string|in:fixed,percentage',
            'cash_loan_high_cibil_fee_value' => 'nullable|numeric|min:0',

            'construction_loan_without_cibil_fee_type' => 'nullable|string|in:fixed,percentage',
            'construction_loan_without_cibil_fee_value' => 'nullable|numeric|min:0',
            'construction_loan_low_cibil_fee_type' => 'nullable|string|in:fixed,percentage',
            'construction_loan_low_cibil_fee_value' => 'nullable|numeric|min:0',
            'construction_loan_high_cibil_fee_type' => 'nullable|string|in:fixed,percentage',
            'construction_loan_high_cibil_fee_value' => 'nullable|numeric|min:0',

            'virtual_loan_fee_type' => 'nullable|string|in:fixed,percentage',
            'virtual_loan_fee_value' => 'nullable|numeric|min:0',

            // Legacy keys support
            'cash_loan_fee_type' => 'nullable|string|in:fixed,percentage',
            'cash_loan_fee_value' => 'nullable|numeric|min:0',
            'cash_loan_good_cibil_fee_value' => 'nullable|numeric|min:0',
            'construction_loan_fee_type' => 'nullable|string|in:fixed,percentage',
            'construction_loan_fee_value' => 'nullable|numeric|min:0',
        ]);

        $fields = [
            'upi_id', 'upi_payee_name',
            'cash_loan_login_fee', 'cash_loan_doc_fee', 'cash_loan_verification_fee',
            'construction_loan_login_fee', 'construction_loan_doc_fee', 'construction_loan_site_verification_fee',
            'cash_loan_without_cibil_fee_type', 'cash_loan_without_cibil_fee_value',
            'cash_loan_low_cibil_fee_type', 'cash_loan_low_cibil_fee_value',
            'cash_loan_high_cibil_fee_type', 'cash_loan_high_cibil_fee_value',
            'construction_loan_without_cibil_fee_type', 'construction_loan_without_cibil_fee_value',
            'construction_loan_low_cibil_fee_type', 'construction_loan_low_cibil_fee_value',
            'construction_loan_high_cibil_fee_type', 'construction_loan_high_cibil_fee_value',
            'virtual_loan_fee_type', 'virtual_loan_fee_value',
            'cash_loan_fee_type', 'cash_loan_fee_value', 'cash_loan_good_cibil_fee_value',
            'construction_loan_fee_type', 'construction_loan_fee_value',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                if (is_numeric($val)) {
                    SystemSetting::set($field, (float) $val);
                } else if ($val !== null && $val !== '') {
                    SystemSetting::set($field, trim($val));
                }
            }
        }

        // If itemized cash loan components were passed, sync without_cibil fee value if needed
        if ($request->has('cash_loan_login_fee') || $request->has('cash_loan_doc_fee') || $request->has('cash_loan_verification_fee')) {
            $totalCash = (float) SystemSetting::get('cash_loan_login_fee', 500) + 
                         (float) SystemSetting::get('cash_loan_doc_fee', 200) + 
                         (float) SystemSetting::get('cash_loan_verification_fee', 299);
            if (!$request->has('cash_loan_without_cibil_fee_value')) {
                SystemSetting::set('cash_loan_without_cibil_fee_value', $totalCash);
            }
        }

        // If itemized construction loan components were passed, sync without_cibil fee value if needed
        if ($request->has('construction_loan_login_fee') || $request->has('construction_loan_doc_fee') || $request->has('construction_loan_site_verification_fee')) {
            $totalConst = (float) SystemSetting::get('construction_loan_login_fee', 500) + 
                          (float) SystemSetting::get('construction_loan_doc_fee', 300) + 
                          (float) SystemSetting::get('construction_loan_site_verification_fee', 699);
            if (!$request->has('construction_loan_without_cibil_fee_value')) {
                SystemSetting::set('construction_loan_without_cibil_fee_value', $totalConst);
            }
        }

        return $this->getFeeConfig();
    }

    public function adminApproveDisbursement(Request $request, $id)
    {
        $cooldownDays = self::getCooldownDays();
        $loanApp = LoanApplication::findOrFail($id);

        $feeStat = strtolower($loanApp->fee_payment_status ?? $loanApp->payment_status ?? '');
        if ($feeStat !== 'approved' && $feeStat !== 'verified') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot disburse loan: Step 1 Processing Fee payment must be verified & approved first.',
            ], 422);
        }

        $loanApp->final_decision = 'APPROVED';
        $loanApp->disbursement_status = 'credited';
        $loanApp->disbursement_reference_no = 'HDFCLN' . rand(100000000, 999999999);
        $loanApp->disbursed_at = now();
        $loanApp->reapply_locked_until = now()->addDays($cooldownDays);
        $loanApp->status = 'disbursed';
        $loanApp->save();

        if ($loanApp->loan_type === 'virtual_loan' || $loanApp->loan_category === 'virtual_loan') {
            $mobile = preg_replace('/[^0-9]/', '', $loanApp->mobile_number);
            $wallet = \App\Models\UserWalletCard::firstOrCreate(
                ['mobile' => $mobile],
                [
                    'user_id' => $loanApp->user_id,
                    'card_number' => '4734 8912 ' . rand(1000, 9999) . ' ' . substr($mobile, -4),
                    'card_holder_name' => strtoupper($loanApp->full_name),
                    'available_value' => 0,
                    'bank_name' => 'Virtual Bank',
                ]
            );
            $wallet->available_value += (float) $loanApp->required_amount;
            $wallet->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => "Loan disbursement successfully approved & credited. {$cooldownDays}-Day reapply lock activated.",
            'data' => $loanApp,
        ]);
    }

    public function adminReject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string',
            'admin_remark' => 'nullable|string',
        ]);

        $cooldownDays = self::getCooldownDays();
        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->final_decision = 'REJECTED';
        $loanApp->status = 'Rejected';
        $loanApp->rejection_reason = $request->rejection_reason . ($request->admin_remark ? ': ' . $request->admin_remark : '');
        $loanApp->reapply_locked_until = now()->addDays($cooldownDays);
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => "Loan application rejected at current stage & {$cooldownDays}-day reapply lock activated",
            'data' => $loanApp,
        ]);
    }

    public function adminUndoReject(Request $request, $id)
    {
        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->final_decision = 'PROCESSING';
        $loanApp->status = 'under_review';
        $loanApp->reapply_locked_until = null;
        $loanApp->rejection_reason = null;
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Rejection undone successfully! Application restored to Processing status.',
            'data' => $loanApp,
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $loanApp = LoanApplication::findOrFail($id);
        $loanApp->final_decision = 'REJECTED';
        $loanApp->status = 'rejected';
        $loanApp->rejection_reason = 'Cancelled by User (Started New Application)';
        $loanApp->save();

        // Also cancel other uncompleted applications of the SAME CATEGORY for this user/mobile
        $userId = $loanApp->user_id;
        $cleanMobile = preg_replace('/[^0-9]/', '', $loanApp->mobile_number || '');
        $isConstruction = str_contains($loanApp->loan_type, 'construction');

        $cancelQuery = LoanApplication::where('final_decision', '!=', 'APPROVED')
            ->where('status', '!=', 'disbursed');

        if ($isConstruction) {
            $cancelQuery->where('loan_type', 'LIKE', '%construction%');
        } else {
            $cancelQuery->where('loan_type', 'NOT LIKE', '%construction%');
        }

        if ($userId) {
            $cancelQuery->where(function ($q) use ($userId, $cleanMobile) {
                $q->where('user_id', $userId);
                if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
            });
        } elseif ($cleanMobile) {
            $cancelQuery->where('mobile_number', $cleanMobile);
        }

        $cancelQuery->update([
            'final_decision' => 'REJECTED',
            'status' => 'rejected',
            'rejection_reason' => 'Cancelled by User (Started New ' . ($isConstruction ? 'Construction' : 'Cash') . ' Application)',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'All active loan applications for user marked as cancelled',
            'data' => $loanApp,
        ]);
    }


    public function adminConfigureTerms(Request $request, $id)
    {
        $request->validate([
            'indicative_interest_rate' => 'nullable|numeric|min:1|max:36',
            'interest_rate_pa' => 'nullable|string',
            'processing_fee' => 'nullable|numeric|min:0',
            'fee_amount' => 'nullable|numeric|min:0',
            'payment_upi_id' => 'nullable|string',
            'upi_id' => 'nullable|string',
            'approved_amount' => 'nullable|numeric|min:5000',
            'selected_amount' => 'nullable|numeric|min:5000',
            'selected_tenure' => 'nullable|integer',
            'tenure_months' => 'nullable|integer',
            'documents_status' => 'nullable|array',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        
        if ($request->has('indicative_interest_rate')) {
            $rate = (float) $request->indicative_interest_rate;
            $loanApp->indicative_interest_rate = $rate;
            $loanApp->interest_rate_pa = $rate . '% p.a.';
        } elseif ($request->has('interest_rate_pa')) {
            $loanApp->interest_rate_pa = (string) $request->interest_rate_pa;
            $rate = (float) preg_replace('/[^0-9.]/', '', $request->interest_rate_pa);
            if ($rate > 0) $loanApp->indicative_interest_rate = $rate;
        }

        if ($request->has('payment_upi_id') && !empty($request->payment_upi_id)) {
            $loanApp->payment_upi_id = trim($request->payment_upi_id);
        } elseif ($request->has('upi_id') && !empty($request->upi_id)) {
            $loanApp->payment_upi_id = trim($request->upi_id);
        }

        if ($request->has('processing_fee')) {
            $loanApp->processing_fee = (float) $request->processing_fee;
            $loanApp->fee_amount = (float) $request->processing_fee;
        }
        if ($request->has('fee_amount')) {
            $loanApp->fee_amount = (float) $request->fee_amount;
            $loanApp->processing_fee = (float) $request->fee_amount;
        }
        if ($request->has('approved_amount')) {
            $loanApp->approved_amount = (float) $request->approved_amount;
            $loanApp->selected_amount = (float) $request->approved_amount;
        }
        if ($request->has('selected_amount')) {
            $loanApp->selected_amount = (float) $request->selected_amount;
            $loanApp->approved_amount = (float) $request->selected_amount;
        }
        if ($request->has('selected_tenure')) {
            $loanApp->selected_tenure = (int) $request->selected_tenure;
            $loanApp->tenure_months = (int) $request->selected_tenure;
        }
        if ($request->has('tenure_months')) {
            $loanApp->tenure_months = (int) $request->tenure_months;
            $loanApp->selected_tenure = (int) $request->tenure_months;
        }
        if ($request->has('documents_status')) {
            $loanApp->documents_uploaded = $request->documents_status;
        }

        // Recalculate EMI & Total Repayment if amount and tenure are present
        $amount = (float) ($loanApp->selected_amount ?: $loanApp->approved_amount);
        $tenure = (int) ($loanApp->selected_tenure ?: $loanApp->tenure_months);
        $annualRate = (float) ($loanApp->indicative_interest_rate ?: 8.5);

        if ($amount > 0 && $tenure > 0) {
            $r = ($annualRate / 12) / 100;
            $emi = ($amount * $r * pow(1 + $r, $tenure)) / (pow(1 + $r, $tenure) - 1);
            $totalRepayment = $emi * $tenure;
            $totalInterest = $totalRepayment - $amount;

            $loanApp->estimated_emi = round($emi, 2);
            $loanApp->monthly_emi = round($emi, 2);
            $loanApp->total_repayment = round($totalRepayment, 2);
            $loanApp->total_interest = round($totalInterest, 2);
        }

        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Admin configured terms, interest rate, fee, and tenure successfully.',
            'data' => $loanApp,
        ]);
    }

    /**
     * Admin stage advancement (Steps 1 to 6)
     */
    public function updateStage(Request $request, $id)
    {
        $request->validate([
            'stage' => 'required|string',
            'timer_seconds' => 'nullable|integer',
        ]);

        $loanApp = LoanApplication::findOrFail($id);
        $stage = $request->stage;

        if ($request->has('timer_seconds')) {
            $loanApp->verification_timer_seconds = (int) $request->timer_seconds;
        }

        if ($stage === 'approved') {
            $loanApp->final_decision = 'APPROVED';
            $loanApp->status = 'approved';
            $loanApp->approved_amount = $loanApp->approved_amount ?: $loanApp->selected_amount ?: 200000;
            $loanApp->selected_tenure = $loanApp->selected_tenure ?: 24;
            $loanApp->indicative_interest_rate = $loanApp->indicative_interest_rate ?: 8.5;

            // Recalculate EMI
            $amount = $loanApp->approved_amount;
            $tenure = $loanApp->selected_tenure;
            $r = ($loanApp->indicative_interest_rate / 12) / 100;
            $emi = ($amount * $r * pow(1 + $r, $tenure)) / (pow(1 + $r, $tenure) - 1);
            $loanApp->estimated_emi = round($emi, 2);
            $loanApp->total_repayment = round($emi * $tenure, 2);
        } else if ($stage === 'under_review') {
            $loanApp->final_decision = 'PROCESSING';
            $loanApp->status = 'under_review';
            $loanApp->lender_status = 'under_review';
        } else if ($stage === 'proof_verified' || $stage === 'doc_verified') {
            $loanApp->lender_status = 'proof_verified';
            $loanApp->status = 'proof_verified';
        } else if ($stage === 'lender_process_completed') {
            $loanApp->lender_status = 'lender_process_completed';
        } else if ($stage === 'disbursed') {
            $loanApp->final_decision = 'APPROVED';
            $loanApp->disbursement_status = 'credited';
            $loanApp->disbursement_reference_no = 'HDFCLN' . rand(100000000, 999999999);
            $loanApp->disbursed_at = now();
            $loanApp->status = 'disbursed';
        }

        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Application stage updated to ' . $stage,
            'data' => $loanApp,
        ]);
    }

    /**
     * List user / admin applications with selective lightweight columns, search, and pagination
     */
    public function index(Request $request)
    {
        $query = LoanApplication::query();

        $isAdminRoute = str_contains($request->path(), 'admin') 
            || $request->header('X-Admin-Request') 
            || ($request->user() && $request->user()->role === 'admin');

        if (!$isAdminRoute) {
            $user = $request->user() ?: auth('sanctum')->user();
            $mobile = $request->get('mobile');
            $cleanMobile = $mobile ? preg_replace('/[^0-9]/', '', $mobile) : null;

            if ($user) {
                $userCleanMobile = preg_replace('/[^0-9]/', '', $user->mobile ?? '');
                $targetMobile = $cleanMobile ?: $userCleanMobile;

                if ($targetMobile) {
                    LoanApplication::whereNull('user_id')
                        ->where('mobile_number', $targetMobile)
                        ->update(['user_id' => $user->id]);
                }

                $query->where(function ($q) use ($user, $targetMobile) {
                    $q->where('user_id', $user->id);
                    if ($targetMobile) {
                        $q->orWhere('mobile_number', $targetMobile);
                    }
                });
            } elseif ($cleanMobile) {
                $query->where('mobile_number', $cleanMobile);
            } else {
                $hdrMobile = $request->header('X-User-Mobile');
                if ($hdrMobile) {
                    $cleanHdr = preg_replace('/[^0-9]/', '', $hdrMobile);
                    $query->where('mobile_number', $cleanHdr);
                } else {
                    return response()->json([
                        'status' => 'success',
                        'data' => [],
                        'pagination' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => 50,
                            'total' => 0,
                        ],
                    ]);
                }
            }
        }

        if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
            $status = $request->status;
            if ($status === 'new') {
                $query->whereIn('status', ['fee_payment_pending', 'indicative_approved', 'pending_partner_selection', 'documents_pending']);
            } else if ($status === 'in_review' || $status === 'in-review' || $status === 'processing') {
                $query->whereIn('status', ['proof_pending', 'proof_submitted', 'bank_details_pending', 'under_review', 'additional_docs_submitted', 'cibil_tier_assigned']);
            } else if ($status === 'approved') {
                $query->where(function($q) {
                    $q->where('final_decision', 'APPROVED')->orWhere('status', 'approved');
                });
            } else if ($status === 'rejected') {
                $query->where(function($q) {
                    $q->where('final_decision', 'REJECTED')->orWhere('status', 'rejected')->orWhere('status', 'Rejected');
                });
            } else if ($status === 'disbursement_pending' || $status === 'disbursement-pending') {
                $query->where(function($q) {
                    $q->where('final_decision', 'APPROVED')->where(function($q2) {
                        $q2->whereNull('disbursement_status')->orWhere('disbursement_status', '!=', 'credited');
                    });
                });
            } else if ($status === 'disbursed') {
                $query->where(function($q) {
                    $q->where('disbursement_status', 'credited')->orWhere('status', 'disbursed');
                });
            } else if ($status === 'reapply_3_days' || $status === 'reapply-3-days') {
                $query->whereNotNull('reapply_locked_until');
            } else if ($status === 'in_progress') {
                $query->whereNotIn('status', ['disbursed', 'Rejected', 'rejected']);
            } else if ($status === 'completed') {
                $query->whereIn('status', ['disbursed', 'Rejected', 'rejected']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->has('search') && !empty(trim($request->search))) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('application_number', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('mobile_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->get('per_page', 50);
        $applications = $query->latest()->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $applications->items(),
            'pagination' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    /**
     * Show single application
     */
    public function show(Request $request, $id)
    {
        $application = LoanApplication::findOrFail($id);

        if ($request->user() && $request->user()->role !== 'admin') {
            if ($application->user_id && (int) $application->user_id !== (int) $request->user()->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to loan application.',
                ], 403);
            }
        }

        if (!$application->fee_amount && !$application->processing_fee) {
            $principal = (float) ($application->required_amount ?: $application->selected_amount ?: 50000);
            $appFee = self::calculateApplicableFee((string) $application->loan_type, $principal);
            $application->fee_amount = $appFee;
            $application->processing_fee = $appFee;
        }

        $application->upi_id = $application->payment_upi_id ?: SystemSetting::get('upi_id', 'flipflops@upi');
        $application->upi_payee_name = SystemSetting::get('upi_payee_name', 'OpenScore Finance');

        return response()->json([
            'status' => 'success',
            'data' => $application,
        ]);
    }

    // =========================================================================
    // AFFILIATE LENDING PARTNERS & LINKS MANAGEMENT
    // =========================================================================

    public static function getDefaultAffiliatePartners()
    {
        return [
            [
                'id' => 'kotak',
                'name' => 'Kotak Mahindra Bank',
                'category' => 'both',
                'low_cibil_roi' => '14.99% p.a.',
                'high_cibil_roi' => '10.99% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹40,00,000',
                'amount' => 'Up to ₹40,00,000',
                'roi' => '10.99% p.a.',
                'badge' => 'POPULAR CHOICE',
                'url' => 'https://onboarding.kotak.bank.in/pl?utm_source=website&utm_medium=Apply_now&utm_campaign=Loans_page',
                'is_active' => true,
            ],
            [
                'id' => 'idfc_first',
                'name' => 'IDFC FIRST Bank',
                'category' => 'high_cibil',
                'low_cibil_roi' => '15.49% p.a.',
                'high_cibil_roi' => '10.49% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹50,00,000',
                'amount' => 'Up to ₹50,00,000',
                'roi' => '10.49% p.a.',
                'badge' => 'INSTANT APPROVAL',
                'url' => 'https://my.idfcfirst.bank.in/firstmoney/apply-loan?utm_source=website&utm_medium=PLLP&utm_campaign=PLLandingPage',
                'is_active' => true,
            ],
            [
                'id' => 'tata_neu',
                'name' => 'Tata Capital / Tata Neu',
                'category' => 'both',
                'low_cibil_roi' => '15.99% p.a.',
                'high_cibil_roi' => '10.99% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹35,00,000',
                'amount' => 'Up to ₹35,00,000',
                'roi' => '10.99% p.a.',
                'badge' => 'HIGH ELIGIBILITY',
                'url' => 'https://www.tataneu.com/v2/personal-loan',
                'is_active' => true,
            ],
            [
                'id' => 'incred',
                'name' => 'InCred Personal Loan',
                'category' => 'low_cibil',
                'low_cibil_roi' => '13.50% p.a.',
                'high_cibil_roi' => '11.49% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹15,00,000',
                'amount' => 'Up to ₹15,00,000',
                'roi' => '11.49% p.a.',
                'badge' => 'LOW DOCS / LOW CIBIL',
                'url' => 'https://incred.com/personal-loan/',
                'is_active' => true,
            ],
            [
                'id' => 'poonawalla',
                'name' => 'Poonawalla Fincorp Pocket Loan',
                'category' => 'low_cibil',
                'low_cibil_roi' => '12.0% p.a.',
                'high_cibil_roi' => '11.5% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹5,00,000',
                'amount' => 'Up to ₹5,00,000',
                'roi' => '12.0% p.a.',
                'badge' => 'FAST DISBURSAL',
                'url' => 'https://instant-pocket-loan.poonawallafincorp.com/',
                'is_active' => true,
            ],
            [
                'id' => 'hero_fincorp',
                'name' => 'Hero Fincorp Personal Loan',
                'category' => 'low_cibil',
                'low_cibil_roi' => '12.5% p.a.',
                'high_cibil_roi' => '11.9% p.a.',
                'low_cibil_amount' => 'Up to ₹3,00,000',
                'high_cibil_amount' => 'Up to ₹5,00,000',
                'amount' => 'Up to ₹3,00,000',
                'roi' => '12.5% p.a.',
                'badge' => 'LOW CIBIL SUITABLE',
                'url' => 'https://loans.apps.herofincorp.com/en/personal-loan',
                'is_active' => true,
            ],
            [
                'id' => 'hdfc_bank',
                'name' => 'HDFC Bank Personal Loan',
                'category' => 'high_cibil',
                'low_cibil_roi' => '16.0% p.a.',
                'high_cibil_roi' => '10.5% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹50,00,000',
                'amount' => 'Up to ₹50,00,000',
                'roi' => '10.5% p.a.',
                'badge' => 'HIGH MATCH',
                'url' => 'https://www.hdfcbank.com/personal/borrow/popular-loans/personal-loan',
                'is_active' => true,
            ],
            [
                'id' => 'bajaj_finserv',
                'name' => 'Bajaj Finserv Flexi Loan',
                'category' => 'both',
                'low_cibil_roi' => '15.0% p.a.',
                'high_cibil_roi' => '11.0% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹40,00,000',
                'amount' => 'Up to ₹40,00,000',
                'roi' => '11.0% p.a.',
                'badge' => 'INSTANT',
                'url' => 'https://www.bajajfinserv.in/personal-loan',
                'is_active' => true,
            ],
            [
                'id' => 'fundobaba',
                'name' => 'FundoBaba Loan Offers',
                'category' => 'both',
                'low_cibil_roi' => '13.99% p.a.',
                'high_cibil_roi' => '10.99% p.a.',
                'low_cibil_amount' => 'Up to ₹3,50,000',
                'high_cibil_amount' => 'Up to ₹25,00,000',
                'amount' => 'Up to ₹25,00,000',
                'roi' => '10.99% p.a.',
                'badge' => 'SPECIAL OFFER',
                'url' => 'https://offers.fundobaba.com/?utm_source=google&utm_medium=cpc&utm_campaignid=23635784131&utm_campaign={campaign}&utm_adgroupid=193999367957&utm_adgroup={adgroup}&gad_source=1&gad_campaignid=23635784131&gbraid=0AAAAA_yMxuF2eV6oZo5XYxITJNmUBdMZb&gclid=CjwKCAjwn67VBhBnEiwAXUIN1XzgpGsU24jxE0jFjJ__sq8Y6athM1xZ4x3oKydMJX_FGPW1n46wdBoC0_EQAvD_BwE',
                'is_active' => true,
            ],
            [
                'id' => 'rupeeraftaar',
                'name' => 'RupeeRaftaar Instant Loan',
                'category' => 'both',
                'low_cibil_roi' => '14.50% p.a.',
                'high_cibil_roi' => '11.25% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹30,00,000',
                'amount' => 'Up to ₹30,00,000',
                'roi' => '11.25% p.a.',
                'badge' => 'FAST APPROVAL',
                'url' => 'https://rupeeraftaar.com/apply/pan-mobile?utm_source=Lyxel_google&utm_medium=lyxel_cpc&utm_campaign=L%26F_Brand_Search_04AUG26&utm_source=Lyxel_Google&utm_medium=cpc&utm_campaign=24110917845&gad_source=1&gad_campaignid=24110917845&gbraid=0AAAABD0dB5joKZXXtskv6W4RUG0FbdEwG&gclid=CjwKCAjwn67VBhBnEiwAXUIN1a2EA2da1ywZZ-3a-XQhKm2aD0X3rE282fa_Fyk6e2xUFyoiaEccUBoCYzYQAvD_BwE',
                'is_active' => true,
            ],
            [
                'id' => 'dhanjyoti_capital',
                'name' => 'Dhanjyoti Capital',
                'category' => 'low_cibil',
                'low_cibil_roi' => '12.99% p.a.',
                'high_cibil_roi' => '11.99% p.a.',
                'low_cibil_amount' => 'Up to ₹5,00,000',
                'high_cibil_amount' => 'Up to ₹10,00,000',
                'amount' => 'Up to ₹5,00,000',
                'roi' => '12.99% p.a.',
                'badge' => 'INSTANT DISBURSAL',
                'url' => 'https://dhanjyoticapital.in/apply.php?gad_source=1&gad_campaignid=24258795760&gbraid=0AAAABAtgm6_tE3eiagXPXD48F1WZ_8nO1&gclid=CjwKCAjwn67VBhBnEiwAXUIN1dDJ6izy6Ghc1Ee9hL5zT-juTgbF-yKs6tgKZxZTlAunnt5on-rYBRoCnx8QAvD_BwE',
                'is_active' => true,
            ],
            [
                'id' => 'loansbazaar',
                'name' => 'LoansBazaar Personal Loan',
                'category' => 'both',
                'low_cibil_roi' => '15.00% p.a.',
                'high_cibil_roi' => '10.75% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹40,00,000',
                'amount' => 'Up to ₹40,00,000',
                'roi' => '10.75% p.a.',
                'badge' => 'HIGH MATCH',
                'url' => 'https://www.loansbazaar.com/personal-loans-in-chennai',
                'is_active' => true,
            ],
            [
                'id' => 'rarfincare',
                'name' => 'Rarfincare Personal Loan',
                'category' => 'low_cibil',
                'low_cibil_roi' => '13.50% p.a.',
                'high_cibil_roi' => '11.50% p.a.',
                'low_cibil_amount' => 'Up to ₹3,00,000',
                'high_cibil_amount' => 'Up to ₹15,00,000',
                'amount' => 'Up to ₹3,00,000',
                'roi' => '13.50% p.a.',
                'badge' => 'EASY APPROVAL',
                'url' => 'https://rarfincare.in/products/personal-loans/',
                'is_active' => true,
            ],
            [
                'id' => 'mymudra',
                'name' => 'MyMudra Personal Loan',
                'category' => 'both',
                'low_cibil_roi' => '14.25% p.a.',
                'high_cibil_roi' => '10.99% p.a.',
                'low_cibil_amount' => 'Up to ₹4,00,000',
                'high_cibil_amount' => 'Up to ₹35,00,000',
                'amount' => 'Up to ₹35,00,000',
                'roi' => '10.99% p.a.',
                'badge' => 'LOW INTEREST',
                'url' => 'https://www.mymudra.com/personal-loan',
                'is_active' => true,
            ],
            [
                'id' => 'payme_india',
                'name' => 'PayMe India Personal Loan',
                'category' => 'both',
                'low_cibil_roi' => '13.99% p.a.',
                'high_cibil_roi' => '11.50% p.a.',
                'low_cibil_amount' => 'Up to ₹2,50,000',
                'high_cibil_amount' => 'Up to ₹10,00,000',
                'amount' => 'Up to ₹10,00,000',
                'roi' => '11.50% p.a.',
                'badge' => 'QUICK CASH',
                'url' => 'https://www.paymeindia.in/personal-loan/',
                'is_active' => true,
            ],
        ];
    }

    public function getAffiliatePartners()
    {
        $partners = \Illuminate\Support\Facades\Cache::get('affiliate_partners', self::getDefaultAffiliatePartners());
        
        // Ensure legacy cached items have required fields
        $formatted = array_map(function ($p) {
            return [
                'id' => $p['id'] ?? Str::slug($p['name'] ?? 'partner_'.rand(100,999)),
                'name' => $p['name'] ?? 'Bank Partner',
                'category' => $p['category'] ?? 'both',
                'low_cibil_roi' => $p['low_cibil_roi'] ?? $p['roi'] ?? '14.99% p.a.',
                'high_cibil_roi' => $p['high_cibil_roi'] ?? $p['roi'] ?? '10.99% p.a.',
                'low_cibil_amount' => $p['low_cibil_amount'] ?? 'Up to ₹4,00,000',
                'high_cibil_amount' => $p['high_cibil_amount'] ?? $p['amount'] ?? 'Up to ₹50,00,000',
                'amount' => $p['amount'] ?? 'Up to ₹50,00,000',
                'roi' => $p['roi'] ?? '10.99% p.a.',
                'badge' => $p['badge'] ?? 'RECOMMENDED',
                'url' => $p['url'] ?? 'https://www.openscore.in',
                'is_active' => isset($p['is_active']) ? (bool)$p['is_active'] : true,
            ];
        }, $partners);

        return response()->json([
            'status' => 'success',
            'data' => $formatted,
        ]);
    }

    public function saveAffiliatePartners(Request $request)
    {
        $request->validate([
            'partners' => 'required|array',
        ]);

        \Illuminate\Support\Facades\Cache::forever('affiliate_partners', $request->partners);

        return response()->json([
            'status' => 'success',
            'message' => 'Affiliate lending partners and links saved successfully',
            'data' => $request->partners,
        ]);
    }

    public function deleteAffiliatePartner($id)
    {
        $partners = \Illuminate\Support\Facades\Cache::get('affiliate_partners', self::getDefaultAffiliatePartners());
        $filtered = array_values(array_filter($partners, function ($p) use ($id) {
            return $p['id'] !== $id;
        }));

        \Illuminate\Support\Facades\Cache::forever('affiliate_partners', $filtered);

        return response()->json([
            'status' => 'success',
            'message' => 'Partner deleted successfully',
            'data' => $filtered,
        ]);
    }

    public function getVirtualSettings()
    {
        $defaultSettings = [
            'fee_label' => 'Loan Processing / Service Fee',
            'fee_structure' => [
                ['amount' => 15000, 'fee' => 2000],
                ['amount' => 20000, 'fee' => 3000],
                ['amount' => 30000, 'fee' => 3000],
                ['amount' => 35000, 'fee' => 4000],
                ['amount' => 45000, 'fee' => 4000],
            ],
            'upi_id' => SystemSetting::get('upi_id', 'flipflops@upi'),
            'merchant_name' => SystemSetting::get('upi_payee_name', 'OpenScore Finance'),
            'qr_code_image' => '',
        ];

        $settings = \Illuminate\Support\Facades\Cache::get('virtual_loan_settings', $defaultSettings);

        // Merge defaults in case new fields were added
        if (is_array($settings)) {
            $settings = array_merge($defaultSettings, $settings);
        } else {
            $settings = $defaultSettings;
        }

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    public function updateVirtualSettings(Request $request)
    {
        $current = \Illuminate\Support\Facades\Cache::get('virtual_loan_settings', []);
        if (!is_array($current)) $current = [];

        $upiId = $request->input('upi_id', $current['upi_id'] ?? SystemSetting::get('upi_id', 'flipflops@upi'));
        $payee = $request->input('merchant_name', $current['merchant_name'] ?? SystemSetting::get('upi_payee_name', 'OpenScore Finance'));

        if ($request->has('upi_id') && !empty($request->upi_id)) {
            SystemSetting::set('upi_id', trim($request->upi_id));
        }
        if ($request->has('merchant_name')) {
            SystemSetting::set('upi_payee_name', trim($request->merchant_name));
        }

        $settings = [
            'fee_label' => $request->input('fee_label', $current['fee_label'] ?? 'Loan Processing / Service Fee'),
            'fee_structure' => $request->input('fee_structure', $current['fee_structure'] ?? [
                ['amount' => 15000, 'fee' => 2000],
                ['amount' => 20000, 'fee' => 3000],
                ['amount' => 30000, 'fee' => 3000],
                ['amount' => 35000, 'fee' => 4000],
                ['amount' => 45000, 'fee' => 4000],
            ]),
            'upi_id' => $upiId,
            'merchant_name' => $payee,
            'qr_code_image' => $request->input('qr_code_image', $current['qr_code_image'] ?? ''),
        ];

        \Illuminate\Support\Facades\Cache::forever('virtual_loan_settings', $settings);

        return response()->json([
            'status' => 'success',
            'message' => 'Virtual loan & payment gateway settings updated successfully',
            'data' => $settings,
        ]);
    }

    public function virtualApply(Request $request)
    {
        $amount = (float) $request->input('required_amount', $request->input('amount', 30000));
        $fullName = $request->input('full_name', $request->input('name'));
        $mobile = $request->input('mobile_number', $request->input('mobile', $request->input('phone')));
        $email = $request->input('email');
        $address = $request->input('address', '');
        $processingFee = (float) $request->input('processing_fee', self::calculateApplicableFee('virtual_loan', $amount));

        if (!$amount) {
            return response()->json([
                'status' => 'error',
                'message' => 'The loan amount field is required.',
            ], 422);
        }

        if (!$fullName || !$mobile || !$email) {
            return response()->json([
                'status' => 'error',
                'message' => 'Full Name, Mobile Number, and Email Address are required.',
            ], 422);
        }

        $user = $request->user() ?: auth('sanctum')->user();
        $userId = $user ? $user->id : null;
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);

        $existingQuery = LoanApplication::where(function ($q) {
            $q->where('loan_category', 'virtual_loan')
              ->orWhere('loan_type', 'virtual_loan')
              ->orWhere('loan_type', 'LIKE', '%virtual%');
        })->whereNotIn('status', ['rejected', 'cancelled', 'disbursed']);

        if ($userId) {
            $existingQuery->where(function ($q) use ($userId, $cleanMobile) {
                $q->where('user_id', $userId);
                if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
            });
        } elseif ($cleanMobile) {
            $existingQuery->where('mobile_number', $cleanMobile);
        }

        $existing = $existingQuery->latest()->first();

        if ($existing) {
            $existing->amount = $amount;
            $existing->required_amount = $amount;
            $existing->selected_amount = $amount;
            $existing->processing_fee = $processingFee;
            $existing->fee_amount = $processingFee;
            if (!$existing->payment_upi_id) {
                $existing->payment_upi_id = SystemSetting::get('upi_id', 'flipflops@upi');
            }
            $existing->full_name = $fullName;
            $existing->phone = $cleanMobile;
            $existing->mobile_number = $cleanMobile;
            $existing->email = $email;
            if ($address) $existing->address = $address;
            if ($userId && !$existing->user_id) $existing->user_id = $userId;
            $existing->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Virtual loan application updated successfully.',
                'application_id' => $existing->id,
                'data' => $existing,
            ]);
        }

        $applicationNo = 'OSV' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $loanApp = new LoanApplication();
        $loanApp->user_id = $userId;
        $loanApp->application_no = $applicationNo;
        $loanApp->application_number = $applicationNo;
        $loanApp->loan_type = 'virtual_loan';
        $loanApp->loan_category = 'virtual_loan';
        $loanApp->amount = $amount;
        $loanApp->required_amount = $amount;
        $loanApp->selected_amount = $amount;
        $loanApp->processing_fee = $processingFee;
        $loanApp->fee_amount = $processingFee;
        $loanApp->payment_upi_id = SystemSetting::get('upi_id', 'flipflops@upi');
        $loanApp->full_name = $fullName;
        $loanApp->phone = $cleanMobile;
        $loanApp->mobile_number = $cleanMobile;
        $loanApp->email = $email;
        $loanApp->address = $address;
        $loanApp->dob = $user ? ($user->dob ?? '1995-01-01') : '1995-01-01';
        $loanApp->pan_number = $user ? ($user->pan_number ?? 'XXXXX0000X') : 'XXXXX0000X';
        $loanApp->aadhaar_number = $user ? ($user->aadhaar_number ?? '000000000000') : '000000000000';
        $loanApp->employment_type = 'Salaried';
        $loanApp->monthly_income = 30000;
        $loanApp->indicative_min_amount = $amount;
        $loanApp->indicative_max_amount = $amount;
        $loanApp->status = 'documents_pending';
        $loanApp->stage = 'documents';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Virtual loan application created successfully.',
            'application_id' => $loanApp->id,
            'data' => $loanApp,
        ]);
    }

    public function uploadVirtualDocuments(Request $request, $id)
    {
        try {
            $user = $request->user() ?: auth('sanctum')->user();
            $userId = $user ? $user->id : null;

            $loanApp = LoanApplication::find($id);
            if (!$loanApp && $userId) {
                $loanApp = LoanApplication::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->where('loan_category', 'virtual_loan')->orWhere('loan_type', 'virtual_loan');
                    })
                    ->latest()
                    ->first();
            }
            if (!$loanApp) {
                $loanApp = LoanApplication::where('loan_category', 'virtual_loan')
                    ->orWhere('loan_type', 'virtual_loan')
                    ->latest()
                    ->first();
            }
            if (!$loanApp) {
                $applicationNo = 'OSV' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $loanApp = new LoanApplication();
                $loanApp->user_id = $userId;
                $loanApp->application_no = $applicationNo;
                $loanApp->application_number = $applicationNo;
                $loanApp->loan_type = 'virtual_loan';
                $loanApp->loan_category = 'virtual_loan';
                $loanApp->amount = 30000;
                $loanApp->indicative_min_amount = 30000;
                $loanApp->indicative_max_amount = 30000;
                $loanApp->status = 'documents_pending';
                $loanApp->stage = 'documents';
                $loanApp->save();
            }

            $docs = $loanApp->documents_uploaded;
            if (is_string($docs)) {
                $docs = json_decode($docs, true) ?: [];
            }
            if (!is_array($docs)) {
                $docs = [];
            }

            $possibleKeys = ['aadhaar_card', 'pan_card', 'selfie', 'agent_selfie', 'selfie_with_agent'];
            foreach ($possibleKeys as $key) {
                if ($request->hasFile($key)) {
                    $file = $request->file($key);
                    $path = $file->store('documents', 'public');
                    $docs[$key] = [
                        'uploaded' => true,
                        'name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'status' => 'Uploaded',
                        'uploaded_at' => now()->toDateTimeString()
                    ];
                    if ($key === 'agent_selfie' || $key === 'selfie_with_agent') {
                        $loanApp->selfie_with_agent = $path;
                    }
                }
            }

            if ($request->has('documents')) {
                $incomingDocs = is_array($request->documents) ? $request->documents : (json_decode($request->documents, true) ?: []);
                foreach ($incomingDocs as $k => $v) {
                    if (is_array($v) && (!empty($v['name']) || !empty($v['uploaded']))) {
                        $savedPath = $v['path'] ?? null;

                        // If preview contains base64 image, decode and save to file to prevent MySQL column overflow
                        if (!empty($v['preview']) && is_string($v['preview']) && str_starts_with($v['preview'], 'data:image')) {
                            try {
                                $dataParts = explode(',', $v['preview']);
                                if (count($dataParts) === 2) {
                                    $imageRaw = base64_decode($dataParts[1]);
                                    if ($imageRaw !== false) {
                                        $extension = 'jpg';
                                        if (str_contains($dataParts[0], 'image/png')) $extension = 'png';
                                        elseif (str_contains($dataParts[0], 'image/webp')) $extension = 'webp';
                                        elseif (str_contains($dataParts[0], 'image/jpeg')) $extension = 'jpg';

                                        $fileName = 'documents/' . $k . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
                                        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $imageRaw);
                                        $savedPath = $fileName;
                                    }
                                }
                            } catch (\Throwable $e) {
                                // Ignore base64 decode failure
                            }
                        }

                        $cleanItem = [
                            'uploaded' => true,
                            'name' => $v['name'] ?? ($k . '.jpg'),
                            'size' => $v['size'] ?? '',
                            'status' => 'Uploaded',
                            'uploaded_at' => now()->toDateTimeString(),
                        ];
                        if ($savedPath) {
                            $cleanItem['path'] = $savedPath;
                        }

                        $docs[$k] = $cleanItem;

                        if ($k === 'agent_selfie' || $k === 'selfie_with_agent') {
                            $loanApp->selfie_with_agent = $savedPath ?: ($v['name'] ?? 'agent_selfie.jpg');
                        }
                    }
                }
            }

            $loanApp->documents_uploaded = $docs;
            $loanApp->documents_status = 'under_review';
            $loanApp->status = 'documents_submitted';
            $loanApp->stage = 'documents';
            $loanApp->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Virtual loan documents uploaded successfully and submitted for admin verification.',
                'application_id' => $loanApp->id,
                'data' => $loanApp,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('uploadVirtualDocuments error: ' . $e->getMessage());
            return response()->json([
                'status' => 'success',
                'message' => 'Virtual loan documents processed.',
                'application_id' => isset($loanApp) && $loanApp ? $loanApp->id : $id,
                'data' => isset($loanApp) ? $loanApp : null,
            ]);
        }
    }

    public function payVirtualFee(Request $request, $id)
    {
        try {
            $user = $request->user() ?: auth('sanctum')->user();
            $userId = $user ? $user->id : null;

            $loanApp = LoanApplication::find($id);
            if (!$loanApp && $userId) {
                $loanApp = LoanApplication::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->where('loan_category', 'virtual_loan')->orWhere('loan_type', 'virtual_loan');
                    })
                    ->latest()
                    ->first();
            }
            if (!$loanApp) {
                $loanApp = LoanApplication::where('loan_category', 'virtual_loan')
                    ->orWhere('loan_type', 'virtual_loan')
                    ->latest()
                    ->first();
            }
            if (!$loanApp) {
                $applicationNo = 'OSV' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $loanApp = new LoanApplication();
                $loanApp->user_id = $userId;
                $loanApp->application_no = $applicationNo;
                $loanApp->application_number = $applicationNo;
                $loanApp->loan_type = 'virtual_loan';
                $loanApp->loan_category = 'virtual_loan';
                $loanApp->amount = 30000;
                $loanApp->indicative_min_amount = 30000;
                $loanApp->indicative_max_amount = 30000;
                $loanApp->status = 'documents_pending';
                $loanApp->stage = 'documents';
                $loanApp->save();
            }

            $txId = $request->input('transaction_id', $request->input('utr', 'TXN' . rand(10000000, 99999999)));
            $loanApp->transaction_id = $txId;
            $loanApp->payment_status = 'paid';
            $loanApp->fee_payment_status = 'pending_approval';
            $loanApp->status = 'documents_submitted';
            $loanApp->stage = 'documents';

            // Check if payment proof screenshot is uploaded
            $docs = is_array($loanApp->documents_uploaded)
                ? $loanApp->documents_uploaded
                : (json_decode($loanApp->documents_uploaded, true) ?: []);

            if ($request->hasFile('payment_proof')) {
                $file = $request->file('payment_proof');
                $path = $file->store('documents', 'public');
                $docs['payment_proof'] = [
                    'uploaded' => true,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'status' => 'Uploaded',
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            } elseif ($request->hasFile('payment_screenshot')) {
                $file = $request->file('payment_screenshot');
                $path = $file->store('documents', 'public');
                $docs['payment_proof'] = [
                    'uploaded' => true,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'status' => 'Uploaded',
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            } elseif ($request->input('payment_proof')) {
                $proofVal = $request->input('payment_proof');
                $savedPath = null;
                if (is_string($proofVal) && str_starts_with($proofVal, 'data:image')) {
                    try {
                        $dataParts = explode(',', $proofVal);
                        if (count($dataParts) === 2) {
                            $imageRaw = base64_decode($dataParts[1]);
                            if ($imageRaw !== false) {
                                $fileName = 'documents/payment_proof_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
                                \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $imageRaw);
                                $savedPath = $fileName;
                            }
                        }
                    } catch (\Throwable $e) {}
                }
                $docs['payment_proof'] = [
                    'uploaded' => true,
                    'name' => 'Payment Screenshot Receipt',
                    'path' => $savedPath ?: 'documents/payment_proof.jpg',
                    'status' => 'Uploaded',
                    'uploaded_at' => now()->toDateTimeString(),
                ];
            }

            $loanApp->documents_uploaded = $docs;
            $loanApp->save();

            return response()->json([
                'status' => 'success',
                'auto_verified' => false,
                'message' => 'Virtual loan fee payment & screenshot submitted! Wallet balance will be credited after admin approval.',
                'data' => $loanApp,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('payVirtualFee error: ' . $e->getMessage());
            return response()->json([
                'status' => 'success',
                'auto_verified' => false,
                'message' => 'Payment submitted.',
                'data' => isset($loanApp) ? $loanApp : null,
            ]);
        }
    }

    public function getVirtualDashboard(Request $request)
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $mobile = $request->get('mobile') ?: $request->header('X-User-Mobile');
        $cleanMobile = $mobile ? preg_replace('/[^0-9]/', '', $mobile) : null;

        if ($user && !$cleanMobile && !empty($user->mobile)) {
            $cleanMobile = preg_replace('/[^0-9]/', '', $user->mobile);
        }

        $loanApp = null;
        $query = LoanApplication::where(function ($q) {
            $q->where('loan_category', 'virtual_loan')
              ->orWhere('loan_type', 'virtual_loan')
              ->orWhere('loan_type', 'LIKE', '%virtual%');
        })->whereNotIn('status', ['rejected', 'cancelled']);

        if ($user) {
            $loanApp = (clone $query)->where('user_id', $user->id)->latest()->first();
        }
        if (!$loanApp && $cleanMobile) {
            $loanApp = (clone $query)->where('mobile_number', $cleanMobile)->latest()->first();
        }
        if (!$loanApp) {
            $loanApp = $query->latest()->first();
        }

        $wallet = null;
        if ($user || $cleanMobile || ($loanApp && !empty($loanApp->mobile_number))) {
            $walletMob = $cleanMobile ?: ($loanApp ? preg_replace('/[^0-9]/', '', $loanApp->mobile_number) : null);
            $wallet = \App\Models\UserWalletCard::where(function($q) use ($user, $walletMob) {
                if ($user) $q->where('user_id', $user->id);
                if ($walletMob) $q->orWhere('mobile', $walletMob);
            })->first();
        }

        $approvedAmount = (float) ($loanApp ? ($loanApp->approved_amount ?: $loanApp->selected_amount ?: $loanApp->required_amount ?: $loanApp->amount ?: 30000) : 30000);
        $userName = ($user && !empty($user->name)) ? $user->name : ($loanApp && !empty($loanApp->full_name) ? $loanApp->full_name : 'Rahul');

        $isDisbursed = $loanApp && ($loanApp->status === 'disbursed' || $loanApp->fee_payment_status === 'approved' || ($wallet && $wallet->available_value > 0));
        $isPendingApproval = $loanApp && ($loanApp->payment_status === 'paid' && $loanApp->fee_payment_status !== 'approved');

        $availableAmount = ($wallet && $isDisbursed) ? (float) $wallet->available_value : ($isDisbursed ? $approvedAmount : 0);
        $usedAmount = max(0, $approvedAmount - $availableAmount);
        $todaysRepayment = ($isDisbursed && $usedAmount > 0) ? min(1000, $usedAmount) : 0;
        $nextDueDate = $isDisbursed ? now()->addDays(30)->format('d M Y') : 'Pending Admin Review';

        // Parse docs
        $docsList = [];
        if ($loanApp && $loanApp->documents_uploaded) {
            $rawDocs = is_array($loanApp->documents_uploaded) ? $loanApp->documents_uploaded : (json_decode($loanApp->documents_uploaded, true) ?: []);
            $docTitles = [
                'aadhaar_card' => 'Aadhaar Card Copy',
                'pan_card' => 'PAN Card Copy',
                'selfie' => 'Applicant Selfie',
                'agent_selfie' => 'Selfie with Agent',
                'selfie_with_agent' => 'Selfie with Agent',
                'address_proof' => 'Address Proof',
                'business_proof' => 'Business Proof',
                'payment_proof' => 'Fee Payment Screenshot',
            ];
            foreach ($rawDocs as $key => $val) {
                $isApproved = (is_array($val) && ($val['status'] ?? '') === 'approved') || ($loanApp->documents_status === 'approved');
                $docsList[] = [
                    'key' => $key,
                    'name' => $docTitles[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                    'status' => $isApproved ? 'Verified ✓' : 'Under Review',
                    'is_verified' => $isApproved,
                    'uploaded_at' => is_array($val) ? ($val['uploaded_at'] ?? now()->toDateTimeString()) : now()->toDateTimeString(),
                ];
            }
        }

        if (empty($docsList)) {
            $docsList = [
                ['key' => 'aadhaar_card', 'name' => 'Aadhaar Card Copy', 'status' => $isDisbursed ? 'Verified ✓' : 'Pending', 'is_verified' => $isDisbursed],
                ['key' => 'pan_card', 'name' => 'PAN Card Copy', 'status' => $isDisbursed ? 'Verified ✓' : 'Pending', 'is_verified' => $isDisbursed],
                ['key' => 'agent_selfie', 'name' => 'Selfie with Agent', 'status' => $isDisbursed ? 'Verified ✓' : 'Pending', 'is_verified' => $isDisbursed],
            ];
        }

        // Transactions
        $transactions = [];
        if ($wallet) {
            $txList = \App\Models\WalletTransaction::where('receiver_card_id', $wallet->id)
                ->orWhere('sender_card_id', $wallet->id)
                ->latest()
                ->limit(10)
                ->get();

            foreach ($txList as $tx) {
                $isCredit = $tx->receiver_card_id === $wallet->id || $tx->type === 'credit';
                $transactions[] = [
                    'id' => $tx->transaction_id,
                    'title' => $tx->remarks ?: ($isCredit ? 'Virtual Loan Activation Credit' : 'QR Scan Payment'),
                    'date' => $tx->created_at ? $tx->created_at->format('d M Y, h:i A') : now()->format('d M Y, h:i A'),
                    'amount' => (float) $tx->amount,
                    'type' => $isCredit ? 'credit' : 'debit',
                ];
            }
        }

        if (empty($transactions) && $isDisbursed) {
            $transactions = [
                [
                    'id' => 'VLTX' . rand(100000, 999999),
                    'title' => 'Virtual Loan Activation Credit',
                    'date' => now()->format('d M Y, h:i A'),
                    'amount' => $approvedAmount,
                    'type' => 'credit',
                ]
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'user_name' => $userName,
                'application_number' => $loanApp ? ($loanApp->application_number ?: $loanApp->application_no) : 'OSV' . date('Ymd') . '0001',
                'approved_amount' => $approvedAmount,
                'available_amount' => $availableAmount,
                'used_amount' => $usedAmount,
                'todays_repayment' => $todaysRepayment,
                'next_due_date' => $nextDueDate,
                'is_active' => $isDisbursed,
                'is_pending_approval' => $isPendingApproval,
                'loan_status' => $isDisbursed ? 'Active & Usable' : ($isPendingApproval ? 'Under Admin Review' : 'Pending Fee Payment'),
                'interest_rate' => '0% for 30 Days (Interest-Free)',
                'fee_payment_status' => $loanApp ? $loanApp->fee_payment_status : 'unpaid',
                'documents' => $docsList,
                'transactions' => $transactions,
            ],
        ]);
    }
}
