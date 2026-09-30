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
        if (Schema::hasTable('loan_applications')) {
            Schema::table('loan_applications', function (Blueprint $table) {
                if (!Schema::hasColumn('loan_applications', 'payment_upi_id')) {
                    $table->string('payment_upi_id')->nullable()->after('fee_amount');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('loan_applications')) {
            Schema::table('loan_applications', function (Blueprint $table) {
                if (Schema::hasColumn('loan_applications', 'payment_upi_id')) {
                    $table->dropColumn('payment_upi_id');
                }
            });
        }
    }
};
