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
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->string('idempotency_key')->unique()->index();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('receiver_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('sender_card_id')->nullable()->constrained('user_wallet_cards')->onDelete('cascade');
            $table->foreignId('receiver_card_id')->nullable()->constrained('user_wallet_cards')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('type')->default('qr_pay'); // 'qr_pay', 'transfer', 'receive', 'settlement'
            $table->string('payment_method')->default('wallet_card'); // 'wallet_card', 'qr_code', 'upi_qr'
            $table->string('recipient_identifier')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('status')->default('completed'); // 'completed', 'failed', 'pending'
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};

