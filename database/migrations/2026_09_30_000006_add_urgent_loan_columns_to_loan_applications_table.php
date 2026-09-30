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
            if (!Schema::hasColumn('loan_applications', 'is_urgent')) {
                $table->boolean('is_urgent')->default(false);
            }
            if (!Schema::hasColumn('loan_applications', 'property_type')) {
                $table->string('property_type')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'property_address')) {
                $table->text('property_address')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'property_city')) {
                $table->string('property_city')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'property_state')) {
                $table->string('property_state')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'property_pincode')) {
                $table->string('property_pincode')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'estimated_project_cost')) {
                $table->decimal('estimated_project_cost', 15, 2)->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'construction_purpose')) {
                $table->string('construction_purpose')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'urgent_stage')) {
                $table->string('urgent_stage')->default('under_review');
            }
            if (!Schema::hasColumn('loan_applications', 'property_verification_status')) {
                $table->string('property_verification_status')->default('pending');
            }
            if (!Schema::hasColumn('loan_applications', 'property_verification_notes')) {
                $table->text('property_verification_notes')->nullable();
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
            foreach ([
                'is_urgent', 'property_type', 'property_address', 'property_city',
                'property_state', 'property_pincode', 'estimated_project_cost',
                'construction_purpose', 'urgent_stage', 'property_verification_status',
                'property_verification_notes'
            ] as $c) {
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
