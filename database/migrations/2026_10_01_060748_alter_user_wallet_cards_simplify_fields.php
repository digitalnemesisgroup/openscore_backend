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
        Schema::table('user_wallet_cards', function (Blueprint $table) {
            $table->string('valid_thru')->nullable()->change();
            $table->string('daily_increment')->nullable()->change();
            $table->string('bank_name')->nullable()->change();
            $table->string('bank_reference_no')->nullable()->change();
            $table->string('verifying_status')->nullable()->change();
            $table->string('settlement_status')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_wallet_cards', function (Blueprint $table) {
            $table->string('valid_thru')->default('08/29')->nullable(false)->change();
            $table->string('daily_increment')->default('+0.00')->nullable(false)->change();
            $table->string('bank_name')->default('HDFC Bank')->nullable(false)->change();
            $table->string('bank_reference_no')->default('HDFCLN258963741')->nullable(false)->change();
            $table->string('verifying_status')->default('VERIFYING')->nullable(false)->change();
            $table->string('settlement_status')->default('LOCKED & SECURED')->nullable(false)->change();
        });
    }
};
