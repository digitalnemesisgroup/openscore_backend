<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('smtp_pools', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->default('Password@&2026');
            $table->string('purpose')->default('otp'); // 'otp' or 'general'
            $table->string('host')->default('smtp.hostinger.com');
            $table->integer('port')->default(465);
            $table->string('encryption')->default('ssl');
            $table->boolean('is_active')->default(true);
            $table->integer('dispatch_count')->default(0);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();
        });

        // POOL 1: OTP Emails (22 Accounts)
        $otpEmails = [
            'otp@msmeloan.sbs',
            'otp0@msmeloan.sbs',
            'otp1@msmeloan.sbs',
            'otp2@msmeloan.sbs',
            'otp3@msmeloan.sbs',
            'otp4@msmeloan.sbs',
            'otp5@msmeloan.sbs',
            'otp6@msmeloan.sbs',
            'otp7@msmeloan.sbs',
            'otp8@msmeloan.sbs',
            'otp9@msmeloan.sbs',
            'otp10@msmeloan.sbs',
            'otp11@msmeloan.sbs',
            'otp12@msmeloan.sbs',
            'otp13@msmeloan.sbs',
            'otp14@msmeloan.sbs',
            'otp15@msmeloan.sbs',
            'otp16@msmeloan.sbs',
            'otp17@msmeloan.sbs',
            'otp18@msmeloan.sbs',
            'otp19@msmeloan.sbs',
            'otp20@msmeloan.sbs',
        ];

        foreach ($otpEmails as $email) {
            DB::table('smtp_pools')->insert([
                'email' => $email,
                'password' => 'Password@&2026',
                'purpose' => 'otp',
                'host' => 'smtp.hostinger.com',
                'port' => 465,
                'encryption' => 'ssl',
                'is_active' => true,
                'dispatch_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // POOL 2: Loan Documents, Alerts & Auto Mails (62 Accounts)
        $generalEmails = [];

        // 1@msmeloan.sbs to 30@msmeloan.sbs (30 accounts)
        for ($i = 1; $i <= 30; $i++) {
            $generalEmails[] = "{$i}@msmeloan.sbs";
        }

        // alert.openscore@msmeloan.sbs & alert.openscore.1 to 10 (11 accounts)
        $generalEmails[] = 'alert.openscore@msmeloan.sbs';
        for ($i = 1; $i <= 10; $i++) {
            $generalEmails[] = "alert.openscore.{$i}@msmeloan.sbs";
        }

        // imp.alert@msmeloan.sbs & imp.alert.1 to 20 (21 accounts)
        $generalEmails[] = 'imp.alert@msmeloan.sbs';
        for ($i = 1; $i <= 20; $i++) {
            $generalEmails[] = "imp.alert.{$i}@msmeloan.sbs";
        }

        foreach ($generalEmails as $email) {
            DB::table('smtp_pools')->insert([
                'email' => $email,
                'password' => 'Password@&2026',
                'purpose' => 'general',
                'host' => 'smtp.hostinger.com',
                'port' => 465,
                'encryption' => 'ssl',
                'is_active' => true,
                'dispatch_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_pools');
    }
};
