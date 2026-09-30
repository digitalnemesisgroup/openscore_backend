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
        Schema::create('user_wallet_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('mobile')->nullable()->index();
            $table->string('card_number')->default('4734 8912 3456 9012');
            $table->string('card_holder_name')->default('OpenScore User');
            $table->string('valid_thru')->default('08/29');
            $table->decimal('available_value', 15, 2)->default(200000.00);
            $table->decimal('incremental_value', 15, 2)->default(501.00);
            $table->string('daily_increment')->default('+0.67');
            $table->string('verifying_status')->default('VERIFYING');
            $table->string('card_type')->default('PREMIUM METAL CARD');
            $table->string('bank_name')->default('HDFC Bank');
            $table->string('bank_account_number')->default('•••• •••• •••• 4734');
            $table->string('bank_reference_no')->default('HDFCLN258963741');
            $table->string('bank_account_holder_name')->nullable();
            $table->string('bank_ifsc_code')->nullable();
            $table->string('settlement_status')->default('LOCKED & SECURED');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_wallet_cards');
    }
};

