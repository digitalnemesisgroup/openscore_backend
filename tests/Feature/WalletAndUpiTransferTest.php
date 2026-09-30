<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserWalletCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class WalletAndUpiTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }
    public function test_user_creation_auto_assigns_upi_id_and_wallet_card()
    {
        $user = User::create([
            'name' => 'Suresh Patel',
            'email' => 'suresh@test.com',
            'mobile' => '9822334455',
            'password' => 'password123',
            'account_type' => 'personal',
        ]);

        $this->assertNotEmpty($user->upi_id);
        $this->assertEquals('9822334455@openscore', $user->upi_id);

        $card = UserWalletCard::where('user_id', $user->id)->first();
        $this->assertNotNull($card);
        $this->assertEquals('SURESH PATEL', $card->card_holder_name);
    }

    /**
     * Test 2: Transfer from Personal Account to Business Account SUCCEEDS
     */
    public function test_transfer_to_business_account_succeeds()
    {
        $sender = User::where('mobile', '9876543210')->first(); // personal
        $receiver = User::where('mobile', '9800000001')->first(); // business

        $senderCard = UserWalletCard::firstOrCreate(
            ['user_id' => $sender->id],
            ['available_value' => 5000.00, 'card_holder_name' => 'RAHUL SHARMA', 'mobile' => '9876543210', 'card_number' => '4734 8912 1111 3210']
        );
        $senderCard->available_value = 5000.00;
        $senderCard->save();

        $receiverCard = UserWalletCard::firstOrCreate(
            ['user_id' => $receiver->id],
            ['available_value' => 1000.00, 'card_holder_name' => 'APEX RETAIL', 'mobile' => '9800000001', 'card_number' => '4734 8912 2222 0001']
        );
        $receiverCard->available_value = 1000.00;
        $receiverCard->save();

        // 1. Resolve Recipient Check
        $resolveRes = $this->postJson('/api/wallet-card/qr-resolve', [
            'recipient_identifier' => '9800000001@openscore',
        ]);

        $resolveRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'can_receive' => true,
                'is_business' => true,
            ]);

        // 2. Execute Payment
        $payRes = $this->actingAs($sender)->postJson('/api/wallet-card/qr-pay', [
            'recipient_identifier' => '9800000001@openscore',
            'amount' => 500.00,
            'idempotency_key' => 'TEST_' . Str::random(10),
        ]);

        $payRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertEquals(4500.00, $senderCard->fresh()->available_value);
        $this->assertEquals(1500.00, $receiverCard->fresh()->available_value);
    }

    /**
     * Test 3: Transfer to Non-Business (Personal/Student) Account is REJECTED
     */
    public function test_transfer_to_personal_account_is_rejected()
    {
        $sender = User::where('mobile', '9800000001')->first(); // business or any
        $receiver = User::where('mobile', '9812345678')->first(); // student

        // 1. Resolve Check fails with 422
        $resolveRes = $this->postJson('/api/wallet-card/qr-resolve', [
            'recipient_identifier' => '9812345678',
        ]);

        $resolveRes->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'can_receive' => false,
                'is_business' => false,
            ]);

        // 2. Pay Check fails with 422
        $payRes = $this->actingAs($sender)->postJson('/api/wallet-card/qr-pay', [
            'recipient_identifier' => '9812345678@openscore',
            'amount' => 200.00,
            'idempotency_key' => 'TEST_' . Str::random(10),
        ]);

        $payRes->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }
}
