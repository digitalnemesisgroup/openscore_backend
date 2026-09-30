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
                if (!Schema::hasColumn('loan_applications', 'fee_amount')) {
                    $table->decimal('fee_amount', 12, 2)->nullable()->after('processing_fee');
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
                if (Schema::hasColumn('loan_applications', 'fee_amount')) {
                    $table->dropColumn('fee_amount');
                }
            });
        }
    }
};
