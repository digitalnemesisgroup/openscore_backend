<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Applicant Profiles Table
        Schema::create('applicant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained('loan_applications')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
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
            $table->string('employment_type');
            $table->string('company_name')->nullable();
            $table->decimal('monthly_income', 15, 2);
            $table->decimal('existing_emi', 15, 2)->default(0);
            $table->string('work_experience')->nullable();
            $table->decimal('required_amount', 15, 2);
            $table->string('loan_purpose')->nullable();
            $table->timestamps();
        });

        // 2. Disbursement Details Table
        Schema::create('disbursement_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained('loan_applications')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('bank_account_holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_ifsc_code')->nullable();
            $table->string('bank_account_type')->default('Savings');
            $table->string('disbursement_status')->default('pending');
            $table->string('disbursement_reference_no')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();
        });

        // 3. Partner Submissions Table
        Schema::create('partner_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')->constrained('loan_applications')->onDelete('cascade');
            $table->string('selected_partner_id')->nullable();
            $table->string('selected_partner_name')->nullable();
            $table->boolean('partner_locked')->default(false);
            $table->string('bank_application_no')->nullable();
            $table->string('proof_screenshot')->nullable();
            $table->string('bank_portal_status')->default('Application Submitted');
            $table->text('proof_remarks')->nullable();
            $table->string('selfie_with_agent')->nullable();
            $table->timestamps();
        });

        // 4. OTP Logs Table (Seeded for recovery & auditing)
        Schema::create('otp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('mobile');
            $table->string('otp_code');
            $table->string('type')->default('voice_call_pin_recovery'); // 'voice_call_pin_recovery', 'sms'
            $table->string('status')->default('sent'); // 'sent', 'verified', 'expired'
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // 5. Mail Alerts Table (Seeded for system & admin alerts)
        Schema::create('mail_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('sent'); // 'sent', 'queued', 'failed'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_alerts');
        Schema::dropIfExists('otp_logs');
        Schema::dropIfExists('partner_submissions');
        Schema::dropIfExists('disbursement_details');
        Schema::dropIfExists('applicant_profiles');
    }
};

