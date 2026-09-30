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
                if (!Schema::hasColumn('loan_applications', 'payment_screenshot')) {
                    $table->longText('payment_screenshot')->nullable()->after('transaction_id');
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
                if (Schema::hasColumn('loan_applications', 'payment_screenshot')) {
                    $table->dropColumn('payment_screenshot');
                }
            });
        }
    }
};
