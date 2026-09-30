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
            if (!Schema::hasColumn('loan_applications', 'cibil_type')) {
                $table->string('cibil_type')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'cibil_tier_assigned')) {
                $table->string('cibil_tier_assigned')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'tenure_months')) {
                $table->integer('tenure_months')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'monthly_emi')) {
                $table->decimal('monthly_emi', 15, 2)->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'interest_rate_pa')) {
                $table->string('interest_rate_pa')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'requested_amount')) {
                $table->decimal('requested_amount', 15, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $cols = [];
            foreach (['cibil_type', 'cibil_tier_assigned', 'tenure_months', 'monthly_emi', 'interest_rate_pa', 'requested_amount'] as $c) {
                if (Schema::hasColumn('loan_applications', $c)) {
                    $cols[] = $c;
                }
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
