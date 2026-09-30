<?php

namespace App\Http\Controllers;

use App\Models\UserWalletCard;
use App\Models\WalletTransaction;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class UserWalletCardController extends Controller
{
    /**
     * Get or Auto-provision authenticated user's personal Wallet Card details
     */
    public function getWalletCard(Request $request)
    {
        $user = $request->user();
        $mobile = $request->get('mobile');
        $applicationId = $request->get('application_id');

        $cleanMobile = null;
        if ($user && $user->mobile) {
            $cleanMobile = preg_replace('/[^0-9]/', '', $user->mobile);
        } elseif ($mobile) {
            $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
        }

        // Try to find active loan application to sync dynamic bank & amount details
        $loanApp = null;
        if ($applicationId) {
            $loanApp = LoanApplication::find($applicationId);
        }
        if (!$loanApp && $user) {
            $loanApp = LoanApplication::where('user_id', $user->id)->latest()->first();
        }
        if (!$loanApp && $cleanMobile) {
            $loanApp = LoanApplication::where('mobile_number', $cleanMobile)->latest()->first();
        }

        if ($loanApp && !$cleanMobile) {
            $cleanMobile = preg_replace('/[^0-9]/', '', $loanApp->mobile_number);
        }

        // Query existing wallet card
        $card = null;
        if ($user) {
            $card = UserWalletCard::where('user_id', $user->id)->first();
        }
        if (!$card && $cleanMobile) {
            $card = UserWalletCard::where('mobile', $cleanMobile)->first();
        }

        // If not found, provision a unique personalized wallet card for this user
        if (!$card) {
            $card = $this->autoProvisionCard($user, $cleanMobile, $loanApp);
        } else {
            // Keep card synced with latest loan application & user profile if available
            $updated = false;

            $targetName = null;
            if ($loanApp && !empty($loanApp->full_name)) {
                $targetName = $loanApp->full_name;
            } elseif ($user && !empty($user->name)) {
                $targetName = $user->name;
            }

            if ($targetName) {
                $upperTarget = strtoupper($targetName);
                $currentHolder = strtoupper($card->card_holder_name ?? '');
                if ($currentHolder === 'OPENSCORE BORROWER' || $currentHolder === 'OPENSCORE USER' || empty($currentHolder)) {
                    $card->card_holder_name = $upperTarget;
                    $updated = true;
                }
            }

            if ($loanApp) {
                if ($loanApp->approved_amount || $loanApp->selected_amount) {
                    $appAmount = (float) ($loanApp->approved_amount ?: $loanApp->selected_amount);
                    if ($card->available_value == 200000.00 && $appAmount > 0) {
                        $card->available_value = $appAmount;
                        $updated = true;
                    }
                }
                if ($loanApp->bank_name && $card->bank_name !== $loanApp->bank_name) {
                    $card->bank_name = $loanApp->bank_name;
                    $updated = true;
                }
                if ($loanApp->bank_account_number && $card->bank_account_number !== $loanApp->bank_account_number) {
                    $card->bank_account_number = $loanApp->bank_account_number;
                    $updated = true;
                }
                if ($loanApp->disbursement_reference_no && $card->bank_reference_no !== $loanApp->disbursement_reference_no) {
                    $card->bank_reference_no = $loanApp->disbursement_reference_no;
                    $updated = true;
                }
            }

            if ($updated) {
                $card->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $card,
        ]);
    }

    /**
     * Helper to auto-provision user wallet card
     */
    private function autoProvisionCard($user, $cleanMobile, $loanApp = null)
    {
        $holderName = 'OpenScore Borrower';
        if ($user && !empty($user->name)) {
            $holderName = $user->name;
        } elseif ($loanApp && !empty($loanApp->full_name)) {
            $holderName = $loanApp->full_name;
        }

        $suffix = $cleanMobile ? substr($cleanMobile, -4) : (string) rand(1000, 9999);
        $cardNumber = '4734 8912 ' . rand(1000, 9999) . ' ' . $suffix;

        $availValue = 0.00;
        if ($loanApp && $loanApp->approved_amount) {
            $availValue = (float) $loanApp->approved_amount;
        }

        $bankName = $loanApp && $loanApp->bank_name ? $loanApp->bank_name : 'HDFC Bank';
        $bankAcc = $loanApp && $loanApp->bank_account_number ? $loanApp->bank_account_number : '•••• •••• •••• ' . $suffix;
        $bankRef = $loanApp && $loanApp->disbursement_reference_no ? $loanApp->disbursement_reference_no : 'HDFCLN' . rand(100000000, 999999999);

        return UserWalletCard::create([
            'user_id' => $user ? $user->id : ($loanApp ? $loanApp->user_id : null),
            'mobile' => $cleanMobile,
            'card_number' => $cardNumber,
            'card_holder_name' => strtoupper($holderName),
            'valid_thru' => '08/29',
            'available_value' => $availValue,
            'incremental_value' => 0.00,
            'daily_increment' => '+0.00',
            'verifying_status' => 'VERIFYING',
            'card_type' => 'PREMIUM METAL CARD',
            'bank_name' => $bankName,
            'bank_account_number' => $bankAcc,
            'bank_reference_no' => $bankRef,
            'settlement_status' => 'LOCKED & SECURED',
        ]);
    }

    /**
     * Get Personalized QR Code payload & UPI ID for current user to receive money
     */
    public function getQrCode(Request $request)
    {
        $user = $request->user();
        $mobile = $request->get('mobile');
        $cleanMobile = $user && $user->mobile ? preg_replace('/[^0-9]/', '', $user->mobile) : ($mobile ? preg_replace('/[^0-9]/', '', $mobile) : '9876543210');

        $card = null;
        if ($user) {
            $card = UserWalletCard::where('user_id', $user->id)->first();
        }
        if (!$card) {
            $card = UserWalletCard::where('mobile', $cleanMobile)->first();
        }
        if (!$card) {
            $card = $this->autoProvisionCard($user, $cleanMobile);
        }

        $accountType = $user ? ($user->account_type ?? 'personal') : 'personal';
        $upiId = ($user && !empty($user->upi_id)) ? $user->upi_id : ($cleanMobile . '@openscore');
        $qrPayload = "openscore://pay?upi_id={$upiId}&mobile={$cleanMobile}&name=" . urlencode($card->card_holder_name) . "&type={$accountType}&card=" . str_replace(' ', '', $card->card_number);

        return response()->json([
            'status' => 'success',
            'data' => [
                'upi_id' => $upiId,
                'qr_payload' => $qrPayload,
                'card_holder_name' => $card->card_holder_name,
                'card_number' => $card->card_number,
                'available_value' => (float) $card->available_value,
                'mobile' => $cleanMobile,
                'account_type' => $accountType,
                'is_business' => $accountType === 'business',
            ],
        ]);
    }

    /**
     * Helper to parse and extract recipient mobile or identifier from raw string / QR payload
     */
    private function parseRecipientIdentifier(string $rawInput): array
    {
        $input = trim($rawInput);

        // Check if QR URL/URI scheme
        if (str_starts_with($input, 'openscore://pay') || str_starts_with($input, 'upi://pay') || str_contains($input, '?')) {
            $parsedQuery = [];
            $queryString = parse_url($input, PHP_URL_QUERY);
            if ($queryString) {
                parse_str($queryString, $parsedQuery);
            }
            $upiId = $parsedQuery['upi_id'] ?? $parsedQuery['pa'] ?? null;
            $mobile = $parsedQuery['mobile'] ?? null;
            if ($upiId || $mobile) {
                return [
                    'upi_id' => $upiId,
                    'mobile' => $mobile ? preg_replace('/[^0-9]/', '', $mobile) : null,
                    'clean' => $upiId ?: $mobile,
                ];
            }
        }

        // Direct UPI ID (e.g. 9800000001@openscore, shop@upi)
        if (str_contains($input, '@')) {
            $extractedMobile = preg_replace('/[^0-9]/', '', explode('@', $input)[0]);
            return [
                'upi_id' => strtolower($input),
                'mobile' => (strlen($extractedMobile) === 10) ? $extractedMobile : null,
                'clean' => strtolower($input),
            ];
        }

        // 10-digit mobile number
        $cleanNumber = preg_replace('/[^0-9]/', '', $input);
        if (strlen($cleanNumber) >= 10) {
            $tenDigit = substr($cleanNumber, -10);
            return [
                'upi_id' => $tenDigit . '@openscore',
                'mobile' => $tenDigit,
                'clean' => $tenDigit,
            ];
        }

        return [
            'upi_id' => strtolower($input),
            'mobile' => null,
            'clean' => strtolower($input),
        ];
    }

    /**
     * Resolve QR Code payload or mobile recipient before payment
     * Strictly verifies that Recipient is a Business Account
     */
    public function resolveQrRecipient(Request $request)
    {
        $request->validate([
            'recipient_identifier' => 'required|string',
        ]);

        $rawIdentifier = trim($request->recipient_identifier);
        $parsed = $this->parseRecipientIdentifier($rawIdentifier);

        $receiverUser = null;

        // 1. Search by UPI ID
        if (!empty($parsed['upi_id'])) {
            $receiverUser = User::where('upi_id', $parsed['upi_id'])->first();
        }

        // 2. Search by Mobile
        if (!$receiverUser && !empty($parsed['mobile'])) {
            $receiverUser = User::where('mobile', $parsed['mobile'])->first();
        }

        // 3. Search by Email or clean string
        if (!$receiverUser && filter_var($parsed['clean'], FILTER_VALIDATE_EMAIL)) {
            $receiverUser = User::where('email', $parsed['clean'])->first();
        }

        // 4. Fallback search by like mobile/upi
        if (!$receiverUser && !empty($parsed['mobile'])) {
            $receiverUser = User::where('mobile', 'like', '%' . $parsed['mobile'])->first();
        }

        if (!$receiverUser) {
            return response()->json([
                'status' => 'error',
                'can_receive' => false,
                'is_business' => false,
                'message' => 'No registered account found for this UPI ID / Mobile number (' . $rawIdentifier . ').',
                'data' => [
                    'recipient_identifier' => $rawIdentifier,
                    'is_verified' => false,
                    'can_receive' => false,
                ],
            ], 404);
        }

        $accountType = $receiverUser->account_type ?? 'personal';
        $isBusiness = ($accountType === 'business');

        $card = UserWalletCard::where('user_id', $receiverUser->id)->first();
        $name = $receiverUser->name;
        if ($card && !empty($card->card_holder_name)) {
            $name = $card->card_holder_name;
        }

        if (!$isBusiness) {
            return response()->json([
                'status' => 'error',
                'can_receive' => false,
                'is_business' => false,
                'account_type' => $accountType,
                'message' => "Recipient '{$name}' has a " . ucfirst($accountType) . " account. Transfers can ONLY be sent to verified Business / Merchant accounts.",
                'data' => [
                    'recipient_name' => strtoupper($name),
                    'recipient_identifier' => $rawIdentifier,
                    'upi_id' => $receiverUser->upi_id ?? ($receiverUser->mobile . '@openscore'),
                    'account_type' => $accountType,
                    'is_business' => false,
                    'can_receive' => false,
                ],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'can_receive' => true,
            'is_business' => true,
            'message' => "Verified Business Account found.",
            'data' => [
                'recipient_name' => strtoupper($name),
                'recipient_identifier' => $rawIdentifier,
                'upi_id' => $receiverUser->upi_id ?? ($receiverUser->mobile . '@openscore'),
                'account_type' => 'business',
                'is_business' => true,
                'is_verified' => true,
                'can_receive' => true,
                'badge' => 'VERIFIED BUSINESS MERCHANT',
            ],
        ]);
    }

    /**
     * Send & Receive Money between User Accounts via QR Code / Mobile Pay ID
     * Enforces Idempotency Keys, Double-Click Throttling, DB Lock,
     * and MUST HAVE Business type account as receiver.
     */
    public function payViaQr(Request $request)
    {
        $request->validate([
            'recipient_identifier' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'idempotency_key' => 'required|string|max:255',
            'mobile' => 'nullable|string',
        ]);

        $idempotencyKey = trim($request->input('idempotency_key'));

        // 1. IDEMPOTENCY CHECK: If transaction with this key exists, return stored response immediately!
        $existingTxn = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existingTxn) {
            $senderCard = UserWalletCard::find($existingTxn->sender_card_id);
            return response()->json([
                'status' => 'success',
                'idempotent_replay' => true,
                'message' => "Payment of ₹" . number_format($existingTxn->amount, 2) . " to {$existingTxn->recipient_name} was already processed.",
                'transaction_id' => $existingTxn->transaction_id,
                'data' => [
                    'transaction' => $existingTxn,
                    'available_value' => $senderCard ? $senderCard->available_value : 0,
                ],
            ]);
        }

        $user = $request->user();
        $mobile = $request->input('mobile');
        $cleanSenderMobile = $user && $user->mobile ? preg_replace('/[^0-9]/', '', $user->mobile) : ($mobile ? preg_replace('/[^0-9]/', '', $mobile) : null);

        // Find Sender Card
        $senderCard = null;
        if ($user) {
            $senderCard = UserWalletCard::where('user_id', $user->id)->first();
        }
        if (!$senderCard && $cleanSenderMobile) {
            $senderCard = UserWalletCard::where('mobile', $cleanSenderMobile)->first();
        }
        if (!$senderCard) {
            $senderCard = $this->autoProvisionCard($user, $cleanSenderMobile);
        }

        // Parse recipient identifier (UPI ID, mobile, QR string)
        $rawRecipient = trim($request->input('recipient_identifier'));
        $parsed = $this->parseRecipientIdentifier($rawRecipient);

        // Find Recipient User
        $receiverUser = null;
        if (!empty($parsed['upi_id'])) {
            $receiverUser = User::where('upi_id', $parsed['upi_id'])->first();
        }
        if (!$receiverUser && !empty($parsed['mobile'])) {
            $receiverUser = User::where('mobile', $parsed['mobile'])->first();
        }
        if (!$receiverUser && filter_var($parsed['clean'], FILTER_VALIDATE_EMAIL)) {
            $receiverUser = User::where('email', $parsed['clean'])->first();
        }

        if (!$receiverUser) {
            return response()->json([
                'status' => 'error',
                'message' => "Recipient account not found for '{$rawRecipient}'. Money transfers require a registered Business account.",
            ], 404);
        }

        // RULE: Receiver MUST be a Business type account!
        if ($receiverUser->account_type !== 'business') {
            return response()->json([
                'status' => 'error',
                'message' => "Payment Rejected: Transfers can ONLY be sent to verified Business accounts. The recipient ({$receiverUser->name}) is registered as a " . ucfirst($receiverUser->account_type ?: 'Personal') . " account.",
            ], 422);
        }

        // Find or provision Receiver Wallet Card
        $receiverCard = UserWalletCard::where('user_id', $receiverUser->id)->first();
        if (!$receiverCard) {
            $receiverCard = $this->autoProvisionCard($receiverUser, $receiverUser->mobile);
        }

        // Prevent paying oneself
        if ($senderCard->id === $receiverCard->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot transfer money to your own wallet card.',
            ], 422);
        }

        $payAmount = (float) $request->amount;

        // 2. DB TRANSACTION & PESSIMISTIC LOCKING: Atomic Double-Click Safe Payment
        try {
            $txnResult = DB::transaction(function () use ($senderCard, $receiverCard, $payAmount, $idempotencyKey, $rawRecipient) {
                // Lock Sender & Receiver records for update to prevent concurrent race conditions
                $lockedSender = UserWalletCard::where('id', $senderCard->id)->lockForUpdate()->first();
                $lockedReceiver = UserWalletCard::where('id', $receiverCard->id)->lockForUpdate()->first();

                if ($lockedSender->available_value < $payAmount) {
                    throw new \Exception('INSUFFICIENT_BALANCE');
                }

                // Atomic balance transfer
                $lockedSender->available_value -= $payAmount;
                $lockedSender->save();

                $lockedReceiver->available_value += $payAmount;
                $lockedReceiver->save();

                $txnId = 'TXN' . strtoupper(Str::random(3)) . rand(100000, 999999);

                $transaction = WalletTransaction::create([
                    'transaction_id' => $txnId,
                    'idempotency_key' => $idempotencyKey,
                    'sender_user_id' => $lockedSender->user_id,
                    'receiver_user_id' => $lockedReceiver->user_id,
                    'sender_card_id' => $lockedSender->id,
                    'receiver_card_id' => $lockedReceiver->id,
                    'amount' => $payAmount,
                    'type' => 'qr_pay',
                    'payment_method' => 'upi_qr',
                    'recipient_identifier' => $rawRecipient,
                    'recipient_name' => $lockedReceiver->card_holder_name,
                    'status' => 'completed',
                    'remarks' => "OpenScore Transfer of ₹{$payAmount} to Business Merchant {$lockedReceiver->card_holder_name}",
                ]);

                return [
                    'transaction' => $transaction,
                    'sender_card' => $lockedSender,
                    'receiver_card' => $lockedReceiver,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => "Payment of ₹" . number_format($payAmount, 2) . " to Business Merchant {$txnResult['receiver_card']->card_holder_name} successful!",
                'transaction_id' => $txnResult['transaction']->transaction_id,
                'data' => [
                    'transaction' => $txnResult['transaction'],
                    'available_value' => $txnResult['sender_card']->available_value,
                    'recipient_name' => $txnResult['receiver_card']->card_holder_name,
                    'recipient_upi' => $receiverUser->upi_id ?? ($receiverUser->mobile . '@openscore'),
                    'account_type' => 'business',
                ],
            ]);

        } catch (\Exception $e) {
            if ($e->getMessage() === 'INSUFFICIENT_BALANCE') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient wallet balance for this transaction.',
                ], 422);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Payment processing error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transfer funds from User Wallet Card to Settlement Bank Account
     */
    public function transfer(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'mobile' => 'nullable|string',
        ]);

        $user = $request->user();
        $mobile = $request->input('mobile');
        $cleanMobile = $user && $user->mobile ? preg_replace('/[^0-9]/', '', $user->mobile) : ($mobile ? preg_replace('/[^0-9]/', '', $mobile) : null);

        $card = null;
        if ($user) {
            $card = UserWalletCard::where('user_id', $user->id)->first();
        }
        if (!$card && $cleanMobile) {
            $card = UserWalletCard::where('mobile', $cleanMobile)->first();
        }

        if (!$card) {
            return response()->json([
                'status' => 'error',
                'message' => 'No wallet card record found for this user account.',
            ], 404);
        }

        $transferAmt = (float) $request->amount;
        if ($transferAmt > $card->available_value) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient available balance on your OpenScore Smart Value Card.',
                'available_value' => $card->available_value,
            ], 422);
        }

        // Deduct transfer amount and update status
        $card->available_value = max(0, $card->available_value - $transferAmt);
        $card->verifying_status = 'TRANSFER_INITIATED';
        $card->save();

        return response()->json([
            'status' => 'success',
            'message' => "Transfer of ₹" . number_format($transferAmt, 2) . " initiated successfully to your verified bank account ({$card->bank_name}).",
            'data' => $card,
        ]);
    }

    /**
     * Admin: Update User Wallet Card details
     */
    public function adminUpdateWalletCard(Request $request, $id)
    {
        $request->validate([
            'available_value' => 'nullable|numeric|min:0',
            'incremental_value' => 'nullable|numeric|min:0',
            'card_holder_name' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_reference_no' => 'nullable|string|max:255',
            'verifying_status' => 'nullable|string|max:50',
        ]);

        $card = UserWalletCard::findOrFail($id);

        if ($request->has('available_value')) {
            $card->available_value = (float) $request->available_value;
        }
        if ($request->has('incremental_value')) {
            $card->incremental_value = (float) $request->incremental_value;
        }
        if ($request->has('card_holder_name')) {
            $card->card_holder_name = strtoupper($request->card_holder_name);
        }
        if ($request->has('bank_name')) {
            $card->bank_name = $request->bank_name;
        }
        if ($request->has('bank_account_number')) {
            $card->bank_account_number = $request->bank_account_number;
        }
        if ($request->has('bank_reference_no')) {
            $card->bank_reference_no = $request->bank_reference_no;
        }
        if ($request->has('verifying_status')) {
            $card->verifying_status = $request->verifying_status;
        }

        $card->save();

        return response()->json([
            'status' => 'success',
            'message' => 'User wallet card details updated successfully by Admin.',
            'data' => $card,
        ]);
    }
}
