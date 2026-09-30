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
            if (!Schema::hasColumn('loan_applications', 'application_no')) {
                $table->string('application_no')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'loan_category')) {
                $table->string('loan_category')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'amount')) {
                $table->decimal('amount', 15, 2)->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'phone')) {
                $table->string('phone')->nullable();
            }
            if (!Schema::hasColumn('loan_applications', 'stage')) {
                $table->string('stage')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn(['application_no', 'loan_category', 'amount', 'phone', 'stage']);
        });
    }
};
