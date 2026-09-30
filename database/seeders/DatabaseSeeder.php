<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\OtpLog;
use App\Models\MailAlert;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Admin Account
        $admin = User::updateOrCreate(
            ['mobile' => '9999999999'],
            [
                'name' => 'OpenScore Admin',
                'email' => 'admin@msmeloan.sbs',
                'mobile' => '9999999999',
                'security_pin' => Hash::make('1234'),
                'is_pin_set' => true,
                'role' => 'admin',
                'password' => Hash::make('password123'),
            ]
        );

        // 2. Seed Default Demo User (Personal Account)
        $user = User::updateOrCreate(
            ['mobile' => '9876543210'],
            [
                'name' => 'Rahul Sharma',
                'email' => 'user@openscore.com',
                'mobile' => '9876543210',
                'upi_id' => '9876543210@openscore',
                'security_pin' => Hash::make('1234'),
                'is_pin_set' => true,
                'account_type' => 'personal',
                'role' => 'user',
                'password' => Hash::make('password123'),
            ]
        );

        // 3. Seed Verified Business Account 1 (Merchant)
        $business1 = User::updateOrCreate(
            ['mobile' => '9800000001'],
            [
                'name' => 'Apex Retail & Electronics',
                'email' => 'apex.business@openscore.com',
                'mobile' => '9800000001',
                'upi_id' => '9800000001@openscore',
                'security_pin' => Hash::make('1234'),
                'is_pin_set' => true,
                'account_type' => 'business',
                'role' => 'user',
                'password' => Hash::make('password123'),
            ]
        );

        // 4. Seed Verified Business Account 2 (Merchant)
        $business2 = User::updateOrCreate(
            ['mobile' => '9800000002'],
            [
                'name' => 'QuickStore Supermarket',
                'email' => 'quickstore@openscore.com',
                'mobile' => '9800000002',
                'upi_id' => 'quickstore@openscore',
                'security_pin' => Hash::make('1234'),
                'is_pin_set' => true,
                'account_type' => 'business',
                'role' => 'user',
                'password' => Hash::make('password123'),
            ]
        );

        // 5. Seed Additional Student Borrower Account
        $studentUser = User::updateOrCreate(
            ['mobile' => '9812345678'],
            [
                'name' => 'Ankit Verma',
                'email' => 'ankit@openscore.com',
                'mobile' => '9812345678',
                'upi_id' => '9812345678@openscore',
                'security_pin' => Hash::make('1234'),
                'is_pin_set' => true,
                'account_type' => 'student',
                'role' => 'user',
                'password' => Hash::make('password123'),
            ]
        );

        // 3. Seed Sample Voice Call OTP Logs
        OtpLog::create([
            'user_id' => $user->id,
            'mobile' => '9876543210',
            'otp_code' => '123456',
            'type' => 'voice_call_pin_recovery',
            'status' => 'verified',
            'expires_at' => now()->addMinutes(10),
        ]);

        // 4. Seed Sample Mail Alerts
        MailAlert::create([
            'user_id' => $user->id,
            'recipient_email' => $user->email,
            'subject' => 'Welcome to OpenScore Loan Application Portal',
            'message' => 'Dear Rahul, your OpenScore account is activated. Use your 4-digit Security PIN to log in anytime.',
            'status' => 'sent',
        ]);

        MailAlert::create([
            'user_id' => $admin->id,
            'recipient_email' => 'admin@msmeloan.sbs',
            'subject' => 'System Alert: OpenScore Portal Active',
            'message' => 'Admin dashboard initialized. Real-time application tracking active.',
            'status' => 'sent',
        ]);

        // 5. Seed 84 SMTP Pool Accounts
        $this->call(SmtpPoolSeeder::class);
    }
}
