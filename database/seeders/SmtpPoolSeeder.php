<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SmtpPool;

class SmtpPoolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = 'Password@&2026';
        $host = 'smtp.hostinger.com';
        $port = 465;
        $encryption = 'ssl';

        // 22 OTP Dedicated Accounts
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

        // 62 General & Alert Accounts
        $generalEmails = [];
        for ($i = 1; $i <= 30; $i++) {
            $generalEmails[] = "{$i}@msmeloan.sbs";
        }

        $generalEmails[] = 'alert.openscore@msmeloan.sbs';
        for ($i = 1; $i <= 10; $i++) {
            $generalEmails[] = "alert.openscore.{$i}@msmeloan.sbs";
        }

        $generalEmails[] = 'imp.alert@msmeloan.sbs';
        for ($i = 1; $i <= 20; $i++) {
            $generalEmails[] = "imp.alert.{$i}@msmeloan.sbs";
        }

        foreach ($otpEmails as $email) {
            SmtpPool::updateOrCreate(
                ['email' => $email],
                [
                    'password' => $password,
                    'purpose' => 'otp',
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'is_active' => true,
                ]
            );
        }

        foreach ($generalEmails as $email) {
            SmtpPool::updateOrCreate(
                ['email' => $email],
                [
                    'password' => $password,
                    'purpose' => 'general',
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'is_active' => true,
                ]
            );
        }
    }
}

