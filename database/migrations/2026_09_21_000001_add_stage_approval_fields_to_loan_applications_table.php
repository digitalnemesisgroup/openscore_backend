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
        Schema::table('loan_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('loan_applications', 'fee_payment_status')) {
                $table->string('fee_payment_status')->default('unpaid')->nullable();
                $table->timestamp('fee_payment_approved_at')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'site_selfie_status')) {
                $table->string('site_selfie_status')->default('pending')->nullable();
                $table->timestamp('site_selfie_approved_at')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'cibil_tier_assigned')) {
                $table->string('cibil_tier_assigned')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'proof_status')) {
                $table->string('proof_status')->default('not_submitted')->nullable();
                $table->timestamp('proof_approved_at')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'bank_details_status')) {
                $table->string('bank_details_status')->default('not_submitted')->nullable();
                $table->timestamp('bank_details_approved_at')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'additional_docs_status')) {
                $table->string('additional_docs_status')->default('not_submitted')->nullable();
                $table->timestamp('additional_docs_approved_at')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'documents_status')) {
                $table->string('documents_status')->default('not_submitted')->nullable();
                $table->timestamp('documents_approved_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'fee_payment_status',
                'fee_payment_approved_at',
                'site_selfie_status',
                'site_selfie_approved_at',
                'cibil_tier_assigned',
                'proof_status',
                'proof_approved_at',
                'bank_details_status',
                'bank_details_approved_at',
                'additional_docs_status',
                'additional_docs_approved_at',
                'documents_status',
                'documents_approved_at',
            ]);
        });
    }
};

