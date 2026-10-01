<?php

namespace App\Http\Controllers;

use App\Models\LoanApplication;
use App\Models\ApplicantProfile;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UrgentConstructionLoanController extends Controller
{
    /**
     * Store new or updated Urgent Construction Loan application
     */
    public function apply(Request $request)
    {
        try {
            $validated = $request->validate([
                // 1. Loan & Project Details
                'required_amount' => 'required|numeric|min:10000',
                'construction_purpose' => 'required|string',
                'estimated_project_cost' => 'nullable|numeric',

                // 2. Property Details
                'property_type' => 'required|string',
                'property_address' => 'required|string',
                'property_city' => 'nullable|string',
                'property_state' => 'nullable|string',
                'property_pincode' => 'nullable|string',

                // 3. Applicant Details
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

                // 4. Income Details
                'employment_type' => 'required|string',
                'company_name' => 'nullable|string',
                'monthly_income' => 'required|numeric|min:1000',
                'existing_emi' => 'nullable|numeric',
                'work_experience' => 'nullable|string',

                // 5. Document Uploads (JSON or Array)
                'documents_uploaded' => 'nullable',

                // 6. Bank Details
                'bank_account_holder_name' => 'required|string',
                'bank_name' => 'required|string',
                'bank_account_number' => 'required|string',
                'bank_ifsc_code' => 'required|string',
                'bank_account_type' => 'nullable|string',
            ]);

            $userId = $request->user() ? $request->user()->id : null;
            $cleanMobile = preg_replace('/[^0-9]/', '', $validated['mobile_number']);
            $loanAmount = (float) $validated['required_amount'];

            // Calculate processing fee using dynamic fee config
            $feeType = SystemSetting::get('construction_loan_without_cibil_fee_type', SystemSetting::get('construction_loan_fee_type', 'fixed'));
            $feeValue = (float) SystemSetting::get('construction_loan_without_cibil_fee_value', SystemSetting::get('construction_loan_fee_value', 999));
            $calculatedFee = ($feeType === 'percentage') ? max(1, round($loanAmount * ($feeValue / 100), 2)) : $feeValue;

            $applicationNo = 'UCL' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Check if user already has an active uncompleted urgent construction loan
            $existingApp = LoanApplication::where('is_urgent', true)
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
                $loanApp->status = 'fee_payment_pending';
                $loanApp->urgent_stage = 'fee_payment_pending';
            }

            $loanApp->is_urgent = true;
            $loanApp->loan_type = 'urgent_construction_loan';
            $loanApp->loan_category = 'urgent_construction_loan';
            $loanApp->cibil_type = 'urgent';

            // Loan & Project Details
            $loanApp->required_amount = $loanAmount;
            $loanApp->amount = $loanAmount;
            $loanApp->selected_amount = $loanAmount;
            $loanApp->indicative_min_amount = $loanAmount;
            $loanApp->indicative_max_amount = $loanAmount;
            $loanApp->construction_purpose = $validated['construction_purpose'];
            $loanApp->estimated_project_cost = (float) ($validated['estimated_project_cost'] ?? ($loanAmount * 1.25));

            // Property Details
            $loanApp->property_type = $validated['property_type'];
            $loanApp->property_address = $validated['property_address'];
            $loanApp->property_city = $validated['property_city'] ?? $validated['city'] ?? null;
            $loanApp->property_state = $validated['property_state'] ?? $validated['state'] ?? null;
            $loanApp->property_pincode = $validated['property_pincode'] ?? $validated['pin_code'] ?? null;
            $loanApp->property_verification_status = 'pending';

            // Applicant Details
            $loanApp->full_name = $validated['full_name'];
            $loanApp->dob = $validated['dob'];
            $loanApp->gender = $validated['gender'];
            $loanApp->mobile_number = $cleanMobile;
            $loanApp->phone = $cleanMobile;
            $loanApp->email = $validated['email'];
            $loanApp->pan_number = strtoupper($validated['pan_number']);
            $loanApp->aadhaar_number = $validated['aadhaar_number'];
            $loanApp->address = $validated['address'] ?? $validated['property_address'];
            $loanApp->city = $validated['city'] ?? $validated['property_city'] ?? null;
            $loanApp->state = $validated['state'] ?? $validated['property_state'] ?? null;
            $loanApp->pin_code = $validated['pin_code'] ?? $validated['property_pincode'] ?? null;

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
                                        $fileName = 'documents/urgent_const_' . $docKey . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
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
                        'loan_purpose' => 'Urgent Construction Loan',
                    ]
                );
            } catch (\Throwable $e) {}

            return response()->json([
                'status' => 'success',
                'message' => 'Urgent Construction Loan application saved! Please complete processing fee payment.',
                'data' => $loanApp,
            ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('UrgentConstructionLoanController apply error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process construction loan application: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit Fee Payment (UTR + Screenshot) for Urgent Construction Loan
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
                                $fileName = 'payment_receipts/const_receipt_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
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
            \Illuminate\Support\Facades\Log::error('UrgentConstructionLoanController submitPayment error: ' . $e->getMessage());
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
     * Get Urgent Application details for applicant with user portal status translation
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
                'status_subtitle' => 'Property Verification Running',
                'badge_color' => 'bg-indigo-100 text-indigo-800 border-indigo-300',
                'step_index' => 4,
            ],
            'bank_processing' => [
                'status_title' => 'Bank Processing',
                'status_subtitle' => 'Bank Review Stage',
                'badge_color' => 'bg-cyan-100 text-cyan-800 border-cyan-300',
                'step_index' => 5,
            ],
            'sanction_approved' => [
                'status_title' => 'Sanction Approved',
                'status_subtitle' => 'Loan Approved',
                'badge_color' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                'step_index' => 6,
            ],
            'disbursement_pending' => [
                'status_title' => 'Disbursement Pending',
                'status_subtitle' => 'Payment Processing',
                'badge_color' => 'bg-teal-100 text-teal-800 border-teal-300',
                'step_index' => 7,
            ],
            'amount_released' => [
                'status_title' => 'Amount Released',
                'status_subtitle' => 'Loan Disbursed',
                'badge_color' => 'bg-emerald-200 text-emerald-900 border-emerald-400',
                'step_index' => 8,
            ],
            'rejected' => [
                'status_title' => 'Rejected',
                'status_subtitle' => 'Application Not Approved',
                'badge_color' => 'bg-rose-100 text-rose-900 border-rose-400',
                'step_index' => 0,
            ],
        ];

        $currentStageInfo = $stageMapping[$stageKey] ?? $stageMapping['under_review'];

        return response()->json([
            'status' => 'success',
            'data' => $loanApp,
            'stage_info' => $currentStageInfo,
            'all_stages' => $stageMapping,
        ]);
    }

    /**
     * Get currently active urgent application for logged-in user
     */
    public function getActiveUserApp(Request $request)
    {
        $user = $request->user();
        $mobile = $request->query('mobile') ?: $request->header('X-User-Mobile');
        $cleanMobile = $mobile ? preg_replace('/[^0-9]/', '', $mobile) : null;

        $query = LoanApplication::where('is_urgent', true)
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
     * Admin list urgent construction loan & virtual loan applications
     */
    public function adminIndex(Request $request)
    {
        $query = LoanApplication::where(function($q) {
            $q->where('is_urgent', true)
              ->orWhere('loan_type', 'virtual_loan')
              ->orWhere('loan_category', 'virtual_loan')
              ->orWhere('loan_type', 'LIKE', '%virtual%');
        })->latest();

        if ($request->has('search') && !empty($request->search)) {
            $s = trim($request->search);
            $query->where(function($q) use ($s) {
                $q->where('full_name', 'LIKE', "%{$s}%")
                  ->orWhere('mobile_number', 'LIKE', "%{$s}%")
                  ->orWhere('phone', 'LIKE', "%{$s}%")
                  ->orWhere('application_number', 'LIKE', "%{$s}%")
                  ->orWhere('application_no', 'LIKE', "%{$s}%")
                  ->orWhere('pan_number', 'LIKE', "%{$s}%")
                  ->orWhere('transaction_id', 'LIKE', "%{$s}%");
            });
        }

        if ($request->has('stage') && !empty($request->stage) && $request->stage !== 'all') {
            $stage = $request->stage;
            $query->where(function($q) use ($stage) {
                $q->where('urgent_stage', $stage)
                  ->orWhere('status', $stage);
            });
        }

        if ($request->has('loan_type') && !empty($request->loan_type) && $request->loan_type !== 'all') {
            $type = $request->loan_type;
            if ($type === 'virtual_loan') {
                $query->where(function($q) {
                    $q->where('loan_type', 'virtual_loan')
                      ->orWhere('loan_category', 'virtual_loan')
                      ->orWhere('loan_type', 'LIKE', '%virtual%');
                });
            } else {
                $query->where('loan_type', $type);
            }
        }

        $baseCountQuery = LoanApplication::where(function($q) {
            $q->where('is_urgent', true)
              ->orWhere('loan_type', 'virtual_loan')
              ->orWhere('loan_category', 'virtual_loan')
              ->orWhere('loan_type', 'LIKE', '%virtual%');
        });

        if ($request->has('loan_type') && !empty($request->loan_type) && $request->loan_type !== 'all') {
            $type = $request->loan_type;
            if ($type === 'virtual_loan') {
                $baseCountQuery->where(function($q) {
                    $q->where('loan_type', 'virtual_loan')
                      ->orWhere('loan_category', 'virtual_loan')
                      ->orWhere('loan_type', 'LIKE', '%virtual%');
                });
            } else {
                $baseCountQuery->where('loan_type', $type);
            }
        }

        $totalCount = (clone $baseCountQuery)->count();
        $underReviewCount = (clone $baseCountQuery)->where(function($q) {
            $q->where('urgent_stage', 'under_review')
              ->orWhere('status', 'under_review')
              ->orWhere('status', 'documents_submitted');
        })->count();
        $docsRequiredCount = (clone $baseCountQuery)->where(function($q) {
            $q->where('urgent_stage', 'docs_required')
              ->orWhere('status', 'docs_required')
              ->orWhere('status', 'documents_pending');
        })->count();
        $technicalCount = (clone $baseCountQuery)->where('urgent_stage', 'technical_verification')->count();
        $bankProcessingCount = (clone $baseCountQuery)->where('urgent_stage', 'bank_processing')->count();
        $approvedCount = (clone $baseCountQuery)->where(function($q) {
            $q->where('urgent_stage', 'sanction_approved')
              ->orWhere('status', 'sanction_approved')
              ->orWhere('status', 'approved');
        })->count();
        $disbursedCount = (clone $baseCountQuery)->where(function($q) {
            $q->where('urgent_stage', 'amount_released')
              ->orWhere('status', 'disbursed');
        })->count();
        $rejectedCount = (clone $baseCountQuery)->where(function($q) {
            $q->where('urgent_stage', 'rejected')
              ->orWhere('status', 'rejected');
        })->count();

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
     * Admin Action Execution (Approve, Reject, Request Docs, Update Stage, Verify Property, Verify Payment)
     */
    public function adminAction(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|string|in:approve,reject,need_more_docs,update_stage,verify_property,verify_payment,verify_docs',
            'stage' => 'nullable|string',
            'rejection_reason' => 'nullable|string',
            'requested_docs' => 'nullable',
            'admin_remark' => 'nullable|string',
            'approved_amount' => 'nullable|numeric',
            'property_notes' => 'nullable|string',
        ]);

        $loanApp = LoanApplication::where(function($q) {
            $q->where('is_urgent', true)
              ->orWhere('loan_type', 'virtual_loan')
              ->orWhere('loan_category', 'virtual_loan')
              ->orWhere('loan_type', 'LIKE', '%virtual%');
        })->findOrFail($id);

        $action = $request->action;

        if ($action === 'approve') {
            $loanApp->urgent_stage = 'sanction_approved';
            $loanApp->status = 'sanction_approved';
            $loanApp->final_decision = 'APPROVED';
            $loanApp->fee_payment_status = 'approved';
            $loanApp->payment_status = 'paid';
            $loanApp->fee_payment_approved_at = now();
            $loanApp->approved_amount = (float) ($request->approved_amount ?: $loanApp->selected_amount ?: $loanApp->required_amount);
        } elseif ($action === 'reject') {
            $loanApp->urgent_stage = 'rejected';
            $loanApp->status = 'rejected';
            $loanApp->final_decision = 'REJECTED';
            $loanApp->rejection_reason = $request->rejection_reason ?: 'Application does not meet lending guidelines.';
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
            if ($stage === 'sanction_approved' || $stage === 'amount_released' || $stage === 'approved') {
                $loanApp->final_decision = 'APPROVED';
                $loanApp->fee_payment_status = 'approved';
                $loanApp->approved_amount = (float) ($request->approved_amount ?: $loanApp->selected_amount ?: $loanApp->required_amount);
                if ($stage === 'amount_released') {
                    $loanApp->disbursement_status = 'credited';
                    $loanApp->disbursed_at = now();
                    $loanApp->disbursement_reference_no = 'OS' . rand(100000000, 999999999);
                }
            } elseif ($stage === 'rejected') {
                $loanApp->final_decision = 'REJECTED';
            } else {
                $loanApp->final_decision = 'PROCESSING';
            }
        } elseif ($action === 'verify_property') {
            $loanApp->property_verification_status = 'verified';
            if ($request->has('property_notes')) {
                $loanApp->property_verification_notes = $request->property_notes;
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

        // Unlock UserWalletCard if loan/fee is approved or disbursed
        try {
            $walletMob = preg_replace('/[^0-9]/', '', $loanApp->mobile_number ?? $loanApp->phone ?? '');
            $wallets = \App\Models\UserWalletCard::where(function($q) use ($loanApp, $walletMob) {
                if ($loanApp->user_id) $q->where('user_id', $loanApp->user_id);
                if ($walletMob) $q->orWhere('mobile', $walletMob);
            })->get();

            if ($action === 'approve' || in_array($loanApp->urgent_stage, ['sanction_approved', 'amount_released', 'approved']) || $loanApp->fee_payment_status === 'approved' || $loanApp->status === 'approved') {
                foreach ($wallets as $wallet) {
                    $wallet->verifying_status = 'approved';
                    $wallet->is_active = true;
                    $wallet->is_blocked = false;
                    $wallet->is_frozen = false;
                    
                    if ($loanApp->loan_type === 'virtual_loan' || $loanApp->loan_category === 'virtual_loan' || str_contains(strtolower($loanApp->loan_type), 'virtual')) {
                        $loanAmount = (float) ($loanApp->approved_amount ?: $loanApp->selected_amount ?: $loanApp->required_amount ?: $loanApp->amount ?: 30000);
                        if ($loanAmount > 0) {
                            $wallet->available_value = $loanAmount;
                        }
                    }
                    
                    $wallet->save();
                }
            }
        } catch (\Throwable $we) {
            \Illuminate\Support\Facades\Log::warning('adminAction wallet sync notice: ' . $we->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => "Loan application action ({$action}) applied successfully.",
            'data' => $loanApp,
        ]);
    }
}
