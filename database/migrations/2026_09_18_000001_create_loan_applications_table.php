<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('loan_type'); // 'low_cibil' or 'good_cibil'
            $table->boolean('consent_accepted')->default(true);
            
            // Applicant Details
            $table->string('full_name');
            $table->string('dob');
            $table->string('mobile_number');
            $table->string('email');
            $table->string('pan_number');
            $table->string('aadhaar_number');
            $table->string('gender')->default('Male');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pin_code')->nullable();
            
            // Employment / Income
            $table->string('employment_type');
            $table->string('company_name')->nullable();
            $table->decimal('monthly_income', 15, 2);
            $table->decimal('existing_emi', 15, 2)->default(0);
            $table->string('work_experience')->nullable();
            
            // Loan Requirement & Selected Terms
            $table->decimal('required_amount', 15, 2);
            $table->string('loan_purpose')->nullable();
            $table->decimal('selected_amount', 15, 2)->nullable();
            $table->integer('selected_tenure')->nullable(); // Months: 12, 18, 24, 36, 48, 60
            
            // Indicative Eligibility & Repayment Calculations
            $table->decimal('indicative_min_amount', 15, 2);
            $table->decimal('indicative_max_amount', 15, 2);
            $table->decimal('indicative_interest_rate', 5, 2)->default(8.5);
            $table->decimal('estimated_emi', 15, 2)->nullable();
            $table->decimal('total_repayment', 15, 2)->nullable();
            $table->decimal('total_interest', 15, 2)->nullable();

            // Documents
            $table->json('documents_uploaded')->nullable();

            // Processing / Service Fee Payment
            $table->decimal('processing_fee', 10, 2)->default(999.00);
            $table->string('payment_status')->default('unpaid'); // 'unpaid', 'paid'
            $table->string('transaction_id')->nullable();

            // Partner Integration Dashboard & Single Partner Lock Logic
            $table->json('partner_options')->nullable();
            $table->string('selected_partner_id')->nullable();
            $table->string('selected_partner_name')->nullable();
            $table->boolean('partner_locked')->default(false);
            $table->string('lender_status')->default('pending_partner_selection');
            $table->decimal('lender_charge', 10, 2)->default(250.00);

            // Screen 15-17 Process Completion Proof & Agent Verification
            $table->string('bank_application_no')->nullable(); // e.g. 'HDPL987654321'
            $table->string('proof_screenshot')->nullable();
            $table->string('bank_portal_status')->default('Application Submitted');
            $table->text('proof_remarks')->nullable();
            $table->string('selfie_with_agent')->nullable();

            // Screen 18-21 Decision & Approved Terms
            $table->string('final_decision')->default('PROCESSING'); // 'PROCESSING', 'APPROVED', 'REJECTED', 'ADDITIONAL_DOCS'
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('additional_docs_request')->nullable();
            $table->json('additional_docs_submitted')->nullable();

            // Screen 22-26 Disbursement & Reapply Lock
            $table->string('bank_account_holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc_code')->nullable();
            $table->string('bank_account_type')->default('Savings');
            $table->string('disbursement_status')->default('pending'); // 'pending', 'submitted', 'credited'
            $table->string('disbursement_reference_no')->nullable(); // e.g. 'HDFCLN258963741'
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('reapply_locked_until')->nullable(); // 3-Day Lock

            $table->string('status')->default('indicative_approved'); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
