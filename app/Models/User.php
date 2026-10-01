<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'upi_id',
        'password',
        'security_pin',
        'plain_pin',
        'pin_attempts',
        'pin_locked_until',
        'is_pin_set',
        'role',
        'account_type',
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            if (empty($user->upi_id)) {
                $cleanMobile = $user->mobile ? preg_replace('/[^0-9]/', '', $user->mobile) : null;
                $username = $cleanMobile ?: ($user->email ? explode('@', $user->email)[0] : 'user' . rand(1000, 9999));
                $user->upi_id = strtolower($username) . '@openscore';
            }
            if (empty($user->account_type)) {
                $user->account_type = 'personal';
            }
        });

        static::created(function ($user) {
            try {
                if (!\App\Models\UserWalletCard::where('user_id', $user->id)->exists()) {
                    $cleanMobile = $user->mobile ? preg_replace('/[^0-9]/', '', $user->mobile) : null;
                    $suffix = $cleanMobile ? substr($cleanMobile, -4) : (string) rand(1000, 9999);
                    \App\Models\UserWalletCard::create([
                        'user_id' => $user->id,
                        'mobile' => $cleanMobile,
                        'card_number' => '4734 8912 ' . rand(1000, 9999) . ' ' . $suffix,
                        'card_holder_name' => strtoupper($user->name ?: 'OpenScore User'),
                        'valid_thru' => '',
                        'available_value' => 0.00, // Default initial wallet balance
                        'incremental_value' => 0.00,
                        'daily_increment' => '',
                        'card_type' => $user->account_type === 'business' ? 'MERCHANT BUSINESS CARD' : 'PREMIUM METAL CARD',
                        'bank_name' => '',
                        'bank_account_number' => '00' . rand(10000000, 99999999) . $suffix,
                        'bank_reference_no' => '',
                        'settlement_status' => '',
                    ]);
                }
            } catch (\Throwable $e) {
                // Ignore if already created
            }
        });
    }

    protected $hidden = [
        'password',
        'security_pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'is_pin_set' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function loanApplications()
    {
        return $this->hasMany(LoanApplication::class);
    }
}
