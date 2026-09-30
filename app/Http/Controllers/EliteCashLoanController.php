<?php

namespace App\Http\Controllers;

use App\Models\LoanApplication;
use App\Models\ApplicantProfile;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EliteCashLoanController extends Controller
{
    /**
     * Store new or updated Elite Cash Loan application
     */
    public function apply(Request $request)
    {
        try {
            $validated = $request->validate([
                // 1. Loan Details
                'required_amount' => 'required|numeric|min:5000',
                'loan_purpose' => 'nullable|string',

                // 2. Applicant Details
                'full_name' => 'required|string|max:255',
                'dob' => 'required|string',
                'gender' => 'required|string',
                'mobile_number' => 'required|string',
                'email' => 'required|email',
                'pan_number' => 'required|string',
                'aadhaar_number' => 'required|string',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'pin_code' => 'nullable|string',

                // 3. Income Details
                'employment_type' => 'required|string',
                'company_name' => 'nullable|string',
                'monthly_income' => 'required|numeric|min:1000',
                'existing_emi' => 'nullable|numeric',
                'work_experience' => 'nullable|string',

                // 4. Simple Document Uploads (JSON or Array)
                'documents_uploaded' => 'nullable',

                // 5. Bank Details
                'bank_account_holder_name' => 'required|string',
                'bank_name' => 'required|string',
                'bank_account_number' => 'required|string',
                'bank_ifsc_code' => 'required|string',
                'bank_account_type' => 'nullable|string',
            ]);

            $userId = $request->user() ? $request->user()->id : null;
            $cleanMobile = preg_replace('/[^0-9]/', '', $validated['mobile_number']);
            $loanAmount = (float) $validated['required_amount'];

            // Calculate dynamic processing fee for Elite Cash Loan
            $loginFee = (float) SystemSetting::get('cash_loan_login_fee', 500);
            $docFee = (float) SystemSetting::get('cash_loan_doc_fee', 200);
            $verifFee = (float) SystemSetting::get('cash_loan_verification_fee', 299);
            $totalFee = $loginFee + $docFee + $verifFee;

            // Check if admin set a percentage or override
            $feeType = SystemSetting::get('cash_loan_without_cibil_fee_type', 'fixed');
            $feeValue = (float) SystemSetting::get('cash_loan_without_cibil_fee_value', $totalFee);
            $calculatedFee = ($feeType === 'percentage') ? max(1, round($loanAmount * ($feeValue / 100), 2)) : $feeValue;

            $applicationNo = 'ECL' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Check if user already has an active uncompleted elite cash loan
            $existingApp = LoanApplication::where('loan_type', 'elite_cash_loan')
                ->where(function($q) use ($userId, $cleanMobile) {
                    if ($userId) $q->where('user_id', $userId);
                    if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
                })
                ->whereNotIn('status', ['amount_released', 'rejected', 'cancelled'])
                ->latest()
                ->first();

            $loanApp = $existingApp ?: new LoanApplication();

            if (!$loanApp->id) {
                $loanApp->application_number = $applicationNo;
                $loanApp->application_no = $applicationNo;
                $loanApp->user_id = $userId;

                // 1 out of 5 (20%) automated rejection rule with cooling period
                $isAutoRejected = (mt_rand(1, 5) === 1);
                if ($isAutoRejected) {
                    $loanApp->validation_status = 'rejected_cooling';
                    $loanApp->rejection_reason = 'Credit risk underwriting threshold not met. A 30-day cooling period is active.';
                    $loanApp->status = 'rejected_cooling';
                    $loanApp->urgent_stage = 'rejected_cooling';
                } else {
                    $loanApp->validation_status = 'approved';
                    $loanApp->status = 'fee_payment_pending';
                    $loanApp->urgent_stage = 'fee_payment_pending';
                }
            }

            $loanApp->is_urgent = true;
            $loanApp->loan_type = 'elite_cash_loan';
            $loanApp->loan_category = 'personal_loan';
            $loanApp->cibil_type = 'urgent';

            // Loan Details
            $loanApp->required_amount = $loanAmount;
            $loanApp->amount = $loanAmount;
            $loanApp->selected_amount = $loanAmount;
            $loanApp->indicative_min_amount = $loanAmount;
            $loanApp->indicative_max_amount = $loanAmount;
            $loanApp->loan_purpose = $validated['loan_purpose'] ?? 'Instant Express Personal Loan';

            // Applicant Details
            $loanApp->full_name = $validated['full_name'];
            $loanApp->dob = $validated['dob'];
            $loanApp->gender = $validated['gender'];
            $loanApp->mobile_number = $cleanMobile;
            $loanApp->phone = $cleanMobile;
            $loanApp->email = $validated['email'];
            $loanApp->pan_number = strtoupper($validated['pan_number']);
            $loanApp->aadhaar_number = $validated['aadhaar_number'];
            $loanApp->address = $validated['address'] ?? null;
            $loanApp->city = $validated['city'] ?? null;
            $loanApp->state = $validated['state'] ?? null;
            $loanApp->pin_code = $validated['pin_code'] ?? null;

            // Income Details
            $loanApp->employment_type = $validated['employment_type'];
            $loanApp->company_name = $validated['company_name'] ?? null;
            $loanApp->monthly_income = (float) $validated['monthly_income'];
            $loanApp->existing_emi = (float) ($validated['existing_emi'] ?? 0);
            $loanApp->work_experience = $validated['work_experience'] ?? null;

            // Documents Processing - decode any base64 previews safely to disk
            if (!empty($validated['documents_uploaded'])) {
                $rawDocs = is_array($validated['documents_uploaded'])
                    ? $validated['documents_uploaded']
                    : (json_decode($validated['documents_uploaded'], true) ?: []);

                $processedDocs = [];
                foreach ($rawDocs as $docKey => $docVal) {
                    if (is_array($docVal)) {
                        $docName = $docVal['name'] ?? ($docKey . '.jpg');
                        $preview = $docVal['preview'] ?? null;
                        $savedPath = $docVal['path'] ?? null;

                        if ($preview && is_string($preview) && str_starts_with($preview, 'data:image')) {
                            try {
                                $dataParts = explode(',', $preview);
                                if (count($dataParts) === 2) {
                                    $imageRaw = base64_decode($dataParts[1]);
                                    if ($imageRaw !== false) {
                                        $ext = str_contains($dataParts[0], 'png') ? 'png' : (str_contains($dataParts[0], 'webp') ? 'webp' : 'jpg');
                                        $fileName = 'documents/elite_' . $docKey . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                                        \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $imageRaw);
                                        $savedPath = '/storage/' . $fileName;
                                    }
                                }
                            } catch (\Throwable $e) {}
                        }

                        $processedDocs[$docKey] = [
                            'name' => $docName,
                            'path' => $savedPath ?: ($docVal['path'] ?? null),
                            'size' => $docVal['size'] ?? 'Uploaded',
                            'status' => 'pending',
                            'uploaded_at' => now()->toDateTimeString(),
                        ];
                    } else {
                        $processedDocs[$docKey] = $docVal;
                    }
                }

                $loanApp->documents_uploaded = $processedDocs;
                $loanApp->documents_status = 'pending';
            }

            // Bank Details
            $loanApp->bank_account_holder_name = $validated['bank_account_holder_name'];
            $loanApp->bank_name = $validated['bank_name'];
            $loanApp->bank_account_number = $validated['bank_account_number'];
            $loanApp->bank_ifsc_code = strtoupper($validated['bank_ifsc_code']);
            $loanApp->bank_account_type = $validated['bank_account_type'] ?? 'Savings';
            $loanApp->bank_details_status = 'pending';

            // Fee & Gateway
            $loanApp->processing_fee = $calculatedFee;
            $loanApp->fee_amount = $calculatedFee;
            $loanApp->payment_upi_id = SystemSetting::get('upi_id', 'flipflops@upi');

            $loanApp->save();

            // Update profile
            try {
                ApplicantProfile::updateOrCreate(
                    ['loan_application_id' => $loanApp->id],
                    [
                        'user_id' => $userId,
                        'full_name' => $loanApp->full_name,
                        'dob' => $loanApp->dob,
                        'mobile_number' => $loanApp->mobile_number,
                        'email' => $loanApp->email,
                        'pan_number' => $loanApp->pan_number,
                        'aadhaar_number' => $loanApp->aadhaar_number,
                        'gender' => $loanApp->gender,
                        'address' => $loanApp->address,
                        'city' => $loanApp->city,
                        'state' => $loanApp->state,
                        'pin_code' => $loanApp->pin_code,
                        'employment_type' => $loanApp->employment_type,
                        'company_name' => $loanApp->company_name,
                        'monthly_income' => $loanApp->monthly_income,
                        'existing_emi' => $loanApp->existing_emi,
                        'required_amount' => $loanApp->required_amount,
                        'loan_purpose' => 'Elite Cash Loan',
                    ]
                );
            } catch (\Throwable $e) {}

            return response()->json([
                'status' => 'success',
                'message' => 'Elite Cash Loan application submitted! Please complete the 2-minute validation.',
                'data' => $loanApp,
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('EliteCashLoanController apply error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process loan application: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit Fee Payment (UTR + Screenshot) for Elite Cash Loan
     */
    public function submitPayment(Request $request, $id)
    {
        try {
            $request->validate([
                'transaction_id' => 'required|string|min:6',
                'payment_screenshot' => 'required|string',
            ], [
                'payment_screenshot.required' => 'Payment receipt screenshot is strictly required to process your application.',
                'transaction_id.required' => 'UTR / Transaction reference number is required.',
            ]);

            $loanApp = LoanApplication::findOrFail($id);

            $txId = trim($request->input('transaction_id'));
            $loanApp->transaction_id = $txId;

            if ($request->has('payment_screenshot') && !empty($request->payment_screenshot)) {
                $screenshotVal = $request->payment_screenshot;
                if (is_string($screenshotVal) && str_starts_with($screenshotVal, 'data:image')) {
                    try {
                        $dataParts = explode(',', $screenshotVal);
                        if (count($dataParts) === 2) {
                            $imageRaw = base64_decode($dataParts[1]);
                            if ($imageRaw !== false) {
                                $ext = str_contains($dataParts[0], 'png') ? 'png' : (str_contains($dataParts[0], 'webp') ? 'webp' : 'jpg');
                                $fileName = 'payment_receipts/elite_receipt_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
                                \Illuminate\Support\Facades\Storage::disk('public')->put($fileName, $imageRaw);
                                $loanApp->payment_screenshot = '/storage/' . $fileName;
                            }
                        }
                    } catch (\Throwable $e) {
                        $loanApp->payment_screenshot = $screenshotVal;
                    }
                } else {
                    $loanApp->payment_screenshot = $screenshotVal;
                }
            } elseif ($request->hasFile('payment_screenshot')) {
                $path = $request->file('payment_screenshot')->store('payment_receipts', 'public');
                $loanApp->payment_screenshot = '/storage/' . $path;
            }

            $loanApp->payment_status = 'paid';
            $loanApp->fee_payment_status = 'pending_approval';
            $loanApp->fee_paid_at = now();

            // Sets application to Under Review (Admin Verification Pending)
            $loanApp->status = 'under_review';
            $loanApp->urgent_stage = 'under_review';
            $loanApp->final_decision = 'PROCESSING';
            $loanApp->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Your application has been submitted successfully and is awaiting admin verification.',
                'data' => $loanApp,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('EliteCashLoanController submitPayment error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit payment verification: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * User uploads additional requested documents
     */
    public function submitAdditionalDocs(Request $request, $id)
    {
        $request->validate([
            'submitted_docs' => 'required',
            'user_remarks' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::findOrFail($id);

        $submittedDocs = is_array($request->submitted_docs) 
            ? $request->submitted_docs 
            : (json_decode($request->submitted_docs, true) ?: []);

        $currentDocs = is_array($loanApp->documents_uploaded) 
            ? $loanApp->documents_uploaded 
            : (json_decode($loanApp->documents_uploaded, true) ?: []);

        // Merge additional docs
        foreach ($submittedDocs as $k => $v) {
            $currentDocs[$k] = $v;
        }

        $loanApp->documents_uploaded = $currentDocs;
        $loanApp->additional_docs_submitted = $submittedDocs;
        if ($request->has('user_remarks')) {
            $loanApp->proof_remarks = $request->user_remarks;
        }

        // Return to Under Review status
        $loanApp->status = 'under_review';
        $loanApp->urgent_stage = 'under_review';
        $loanApp->documents_status = 'pending';
        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Additional documents uploaded successfully! Your application is now under admin review.',
            'data' => $loanApp,
        ]);
    }

    /**
     * Get Elite Cash Loan application details with stage mapping
     */
    public function show(Request $request, $id)
    {
        $loanApp = LoanApplication::findOrFail($id);

        $stageKey = $loanApp->urgent_stage ?: ($loanApp->status === 'approved' ? 'sanction_approved' : ($loanApp->status ?: 'under_review'));

        $stageMapping = [
            'fee_payment_pending' => [
                'status_title' => 'Fee Payment Pending',
                'status_subtitle' => 'Complete Nominal Processing Fee Payment',
                'badge_color' => 'bg-amber-100 text-amber-800 border-amber-300',
                'step_index' => 1,
            ],
            'under_review' => [
                'status_title' => 'Under Review',
                'status_subtitle' => 'Admin Verification Pending',
                'badge_color' => 'bg-blue-100 text-blue-800 border-blue-300',
                'step_index' => 2,
            ],
            'docs_required' => [
                'status_title' => 'Documents Required',
                'status_subtitle' => 'Additional Documents Needed',
                'badge_color' => 'bg-rose-100 text-rose-800 border-rose-300',
                'step_index' => 2,
            ],
            'file_created' => [
                'status_title' => 'File Created',
                'status_subtitle' => 'Loan File Generated',
                'badge_color' => 'bg-purple-100 text-purple-800 border-purple-300',
                'step_index' => 3,
            ],
            'technical_verification' => [
                'status_title' => 'Technical Verification',
                'status_subtitle' => 'Express Risk Verification Running',
                'badge_color' => 'bg-indigo-100 text-indigo-800 border-indigo-300',
                'step_index' => 4,
            ],
            'bank_processing' => [
                'status_title' => 'Bank Processing',
                'status_subtitle' => 'Disbursement Bank Processing Stage',
                'badge_color' => 'bg-cyan-100 text-cyan-800 border-cyan-300',
                'step_index' => 5,
            ],
            'sanction_approved' => [
                'status_title' => 'Sanction Approved',
                'status_subtitle' => 'Elite Loan Approved',
                'badge_color' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                'step_index' => 6,
            ],
            'disbursement_pending' => [
                'status_title' => 'Disbursement Pending',
                'status_subtitle' => 'Express Transfer Initiated',
                'badge_color' => 'bg-teal-100 text-teal-800 border-teal-300',
                'step_index' => 7,
            ],
            'amount_released' => [
                'status_title' => 'Amount Released',
                'status_subtitle' => 'Loan Disbursed into Bank Account',
                'badge_color' => 'bg-emerald-200 text-emerald-900 border-emerald-400',
                'step_index' => 8,
            ],
            'rejected_cooling' => [
                'status_title' => 'Application Not Approved',
                'status_subtitle' => 'Cooling Period Active (Try After Sometime)',
                'badge_color' => 'bg-rose-100 text-rose-900 border-rose-400',
                'step_index' => 0,
            ],
            'rejected' => [
                'status_title' => 'Rejected',
                'status_subtitle' => 'Application Not Approved',
                'badge_color' => 'bg-rose-100 text-rose-900 border-rose-400',
                'step_index' => 0,
            ],
        ];

        $currentStageInfo = $stageMapping[$stageKey] ?? $stageMapping['under_review'];

        // Get live fee breakdown
        $loginFee = (float) SystemSetting::get('cash_loan_login_fee', 500);
        $docFee = (float) SystemSetting::get('cash_loan_doc_fee', 200);
        $verifFee = (float) SystemSetting::get('cash_loan_verification_fee', 299);

        return response()->json([
            'status' => 'success',
            'data' => $loanApp,
            'stage_info' => $currentStageInfo,
            'all_stages' => $stageMapping,
            'fee_breakdown' => [
                ['label' => 'Login / Portal Activation Fee', 'amount' => $loginFee],
                ['label' => 'Document & KYC Processing Fee', 'amount' => $docFee],
                ['label' => 'Express Risk & Sanction Verification', 'amount' => $verifFee],
            ],
            'total_fee' => $loginFee + $docFee + $verifFee,
        ]);
    }

    /**
     * Get currently active Elite Cash Loan application for logged-in user
     */
    public function getActiveUserApp(Request $request)
    {
        $user = $request->user();
        $mobile = $request->query('mobile') ?: $request->header('X-User-Mobile');
        $cleanMobile = $mobile ? preg_replace('/[^0-9]/', '', $mobile) : null;

        $query = LoanApplication::where('loan_type', 'elite_cash_loan')
            ->whereNotIn('status', ['cancelled']);

        if ($user) {
            $query->where(function($q) use ($user, $cleanMobile) {
                $q->where('user_id', $user->id);
                if ($cleanMobile) $q->orWhere('mobile_number', $cleanMobile);
            });
        } elseif ($cleanMobile) {
            $query->where('mobile_number', $cleanMobile);
        } else {
            return response()->json([
                'status' => 'success',
                'data' => null,
            ]);
        }

        $app = $query->latest()->first();

        if (!$app) {
            return response()->json([
                'status' => 'success',
                'data' => null,
            ]);
        }

        return $this->show($request, $app->id);
    }

    // =========================================================================
    // ADMIN PANEL CONTROLS
    // =========================================================================

    /**
     * Admin list elite cash loan applications
     */
    public function adminIndex(Request $request)
    {
        $query = LoanApplication::where('loan_type', 'elite_cash_loan')->latest();

        if ($request->has('search') && !empty($request->search)) {
            $s = trim($request->search);
            $query->where(function($q) use ($s) {
                $q->where('full_name', 'LIKE', "%{$s}%")
                  ->orWhere('mobile_number', 'LIKE', "%{$s}%")
                  ->orWhere('application_number', 'LIKE', "%{$s}%")
                  ->orWhere('pan_number', 'LIKE', "%{$s}%")
                  ->orWhere('transaction_id', 'LIKE', "%{$s}%");
            });
        }

        if ($request->has('stage') && !empty($request->stage) && $request->stage !== 'all') {
            $query->where('urgent_stage', $request->stage);
        }

        $totalCount = LoanApplication::where('loan_type', 'elite_cash_loan')->count();
        $underReviewCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'under_review')->count();
        $docsRequiredCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'docs_required')->count();
        $technicalCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'technical_verification')->count();
        $bankProcessingCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'bank_processing')->count();
        $approvedCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'sanction_approved')->count();
        $disbursedCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'amount_released')->count();
        $rejectedCount = LoanApplication::where('loan_type', 'elite_cash_loan')->where('urgent_stage', 'rejected')->count();

        $perPage = (int) ($request->get('per_page', 20));
        $apps = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $apps->items(),
            'stats' => [
                'total' => $totalCount,
                'under_review' => $underReviewCount,
                'docs_required' => $docsRequiredCount,
                'technical_verification' => $technicalCount,
                'bank_processing' => $bankProcessingCount,
                'sanction_approved' => $approvedCount,
                'amount_released' => $disbursedCount,
                'rejected' => $rejectedCount,
            ],
            'pagination' => [
                'current_page' => $apps->currentPage(),
                'last_page' => $apps->lastPage(),
                'per_page' => $apps->perPage(),
                'total' => $apps->total(),
            ],
        ]);
    }

    /**
     * Admin Action Execution for Elite Cash Loan
     */
    public function adminAction(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|string|in:approve,reject,need_more_docs,update_stage,verify_payment,verify_docs',
            'stage' => 'nullable|string',
            'rejection_reason' => 'nullable|string',
            'requested_docs' => 'nullable',
            'admin_remark' => 'nullable|string',
            'approved_amount' => 'nullable|numeric',
        ]);

        $loanApp = LoanApplication::where('loan_type', 'elite_cash_loan')->findOrFail($id);
        $action = $request->action;

        if ($action === 'approve') {
            $loanApp->urgent_stage = 'sanction_approved';
            $loanApp->status = 'sanction_approved';
            $loanApp->final_decision = 'APPROVED';
            $loanApp->approved_amount = (float) ($request->approved_amount ?: $loanApp->selected_amount ?: $loanApp->required_amount);
        } elseif ($action === 'reject') {
            $loanApp->urgent_stage = 'rejected';
            $loanApp->status = 'rejected';
            $loanApp->final_decision = 'REJECTED';
            $loanApp->rejection_reason = $request->rejection_reason ?: 'Application does not meet elite express cash loan guidelines.';
        } elseif ($action === 'need_more_docs') {
            $loanApp->urgent_stage = 'docs_required';
            $loanApp->status = 'docs_required';
            $loanApp->final_decision = 'ADDITIONAL_DOCS';
            $loanApp->additional_docs_request = is_array($request->requested_docs)
                ? json_encode($request->requested_docs)
                : ($request->requested_docs ?: 'Please upload additional requested verification documents.');
            if ($request->has('admin_remark')) {
                $loanApp->proof_remarks = $request->admin_remark;
            }
        } elseif ($action === 'update_stage') {
            $stage = $request->stage;
            $loanApp->urgent_stage = $stage;
            $loanApp->status = $stage;
            if ($stage === 'sanction_approved' || $stage === 'amount_released') {
                $loanApp->final_decision = 'APPROVED';
                $loanApp->approved_amount = (float) ($request->approved_amount ?: $loanApp->selected_amount ?: $loanApp->required_amount);
                if ($stage === 'amount_released') {
                    $loanApp->disbursement_status = 'credited';
                    $loanApp->disbursed_at = now();
                    $loanApp->disbursement_reference_no = 'ECL' . rand(100000000, 999999999);
                }
            } elseif ($stage === 'rejected') {
                $loanApp->final_decision = 'REJECTED';
            } else {
                $loanApp->final_decision = 'PROCESSING';
            }
        } elseif ($action === 'verify_payment') {
            $loanApp->fee_payment_status = 'approved';
            $loanApp->payment_status = 'paid';
            $loanApp->fee_payment_approved_at = now();
        } elseif ($action === 'verify_docs') {
            $loanApp->documents_status = 'approved';
            $loanApp->documents_approved_at = now();
        }

        $loanApp->save();

        return response()->json([
            'status' => 'success',
            'message' => "Elite Cash Loan action ({$action}) applied successfully.",
            'data' => $loanApp,
        ]);
    }
}
