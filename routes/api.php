<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LoanApplicationController;
use App\Http\Controllers\UserWalletCardController;
use Illuminate\Support\Facades\Route;

// Public Auth & OTP Routes (Rate Limits Removed as Requested)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password/reset', [AuthController::class, 'resetPassword']);
Route::post('/otp/send', [AuthController::class, 'sendEmailOtp']);
Route::post('/otp/verify', [AuthController::class, 'verifyEmailOtp']);
Route::post('/auth/send-otp', [AuthController::class, 'sendLoginOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyLoginOtp']);
Route::post('/auth/check-mobile', [AuthController::class, 'checkMobile']);
Route::post('/auth/complete-registration', [AuthController::class, 'completeRegistration']);
Route::post('/auth/login-pin', [AuthController::class, 'loginWithPin']);
Route::post('/auth/pin/send-recovery-otp', [AuthController::class, 'sendPinRecoveryOtp']);
Route::post('/auth/pin/verify-recovery-otp', [AuthController::class, 'verifyPinRecoveryOtpAndReset']);
Route::post('/auth/refresh', [AuthController::class, 'refreshToken']);
Route::post('/admin/refresh', [AuthController::class, 'refreshToken']);

// User Wallet & Card Endpoints (Per-User Balance, Card Number, Settlement Bank)
Route::get('/user/wallet-card', [UserWalletCardController::class, 'getWalletCard']);
Route::get('/wallet-card', [UserWalletCardController::class, 'getWalletCard']);
Route::get('/user/wallet-card/qr-code', [UserWalletCardController::class, 'getQrCode']);
Route::get('/wallet-card/qr-code', [UserWalletCardController::class, 'getQrCode']);
Route::post('/user/wallet-card/qr-resolve', [UserWalletCardController::class, 'resolveQrRecipient']);
Route::post('/wallet-card/qr-resolve', [UserWalletCardController::class, 'resolveQrRecipient']);
Route::post('/user/wallet-card/qr-pay', [UserWalletCardController::class, 'payViaQr']);
Route::post('/wallet-card/qr-pay', [UserWalletCardController::class, 'payViaQr']);
Route::post('/user/wallet-card/transfer', [UserWalletCardController::class, 'transfer']);
Route::post('/wallet-card/transfer', [UserWalletCardController::class, 'transfer']);
Route::put('/admin/wallet-card/{id}', [UserWalletCardController::class, 'adminUpdateWalletCard']);

// Admin & Application Routes
Route::get('/admin/smtp-pool', [AuthController::class, 'getSmtpPool']);
Route::post('/admin/smtp-pool', [AuthController::class, 'addSmtpAccount']);
Route::post('/admin/smtp-pool/add', [AuthController::class, 'addSmtpAccount']);
Route::put('/admin/smtp-pool/{id}', [AuthController::class, 'updateSmtpAccount']);
Route::post('/admin/smtp-pool/{id}/update', [AuthController::class, 'updateSmtpAccount']);
Route::post('/admin/smtp-pool/{id}/toggle', [AuthController::class, 'toggleSmtpAccount']);
Route::delete('/admin/smtp-pool/{id}', [AuthController::class, 'deleteSmtpAccount']);
Route::get('/admin/users', [AuthController::class, 'getUsers']);
Route::put('/admin/users/{id}', [AuthController::class, 'updateUser']);
Route::delete('/admin/users/{id}', [AuthController::class, 'deleteUser']);
Route::get('/users', [AuthController::class, 'getUsers']);
Route::get('/admin/applications', [LoanApplicationController::class, 'index']);
Route::get('/admin/stats', [LoanApplicationController::class, 'adminStats']);
Route::post('/admin/applications/{id}/approve-stage', [LoanApplicationController::class, 'adminApproveStage']);
Route::post('/admin/applications/{id}/configure-terms', [LoanApplicationController::class, 'adminConfigureTerms']);
Route::post('/admin/applications/{id}/approve-all-documents', [LoanApplicationController::class, 'adminApproveAllDocuments']);
Route::post('/admin/applications/{id}/update-document-item', [LoanApplicationController::class, 'adminUpdateDocumentItem']);
Route::post('/admin/applications/{id}/request-docs', [LoanApplicationController::class, 'adminRequestDocs']);
Route::post('/admin/applications/{id}/approve-cibil-tier', [LoanApplicationController::class, 'adminApproveCibilTier']);
Route::post('/admin/applications/{id}/reject', [LoanApplicationController::class, 'adminReject']);
Route::get('/admin/settings/cooldown', [LoanApplicationController::class, 'getCooldownSettings']);
Route::post('/admin/settings/cooldown', [LoanApplicationController::class, 'updateCooldownSettings']);
Route::get('/settings/cooldown', [LoanApplicationController::class, 'getCooldownSettings']);
Route::get('/admin/settings/otp', [AuthController::class, 'getOtpSettings']);
Route::post('/admin/settings/otp', [AuthController::class, 'updateOtpSettings']);
Route::get('/settings/otp', [AuthController::class, 'getOtpSettings']);
Route::get('/admin/settings/fee-config', [LoanApplicationController::class, 'getFeeConfig']);
Route::post('/admin/settings/fee-config', [LoanApplicationController::class, 'updateFeeConfig']);
Route::get('/settings/fee-config', [LoanApplicationController::class, 'getFeeConfig']);


// Loan Application Routes
Route::get('/loan/track', [LoanApplicationController::class, 'trackApplication']);
Route::get('/loan/applicant-profile', [LoanApplicationController::class, 'getApplicantProfile']);
Route::post('/loan/apply', [LoanApplicationController::class, 'store']);
Route::post('/loan/apply/{id}/tenure', [LoanApplicationController::class, 'updateTenure']);
Route::post('/loan/apply/{id}/repayment', [LoanApplicationController::class, 'updateTenure']);
Route::post('/loan/apply/{id}/documents', [LoanApplicationController::class, 'uploadDocuments']);
Route::post('/loan/apply/{id}/payment', [LoanApplicationController::class, 'processPayment']);
Route::post('/loan/apply/{id}/partner', [LoanApplicationController::class, 'verifyPartner']);
Route::post('/loan/apply/{id}/verify-partner', [LoanApplicationController::class, 'verifyPartner']);
Route::post('/loan/apply/{id}/proof', [LoanApplicationController::class, 'submitProof']);
Route::post('/loan/apply/{id}/submit-proof', [LoanApplicationController::class, 'submitProof']);
Route::post('/loan/apply/{id}/agent-selfie', [LoanApplicationController::class, 'submitAgentSelfie']);
Route::post('/loan/apply/{id}/bank-details', [LoanApplicationController::class, 'submitDisbursementBank']);
Route::post('/loan/apply/{id}/disbursement', [LoanApplicationController::class, 'submitDisbursementBank']);
Route::post('/loan/apply/{id}/additional-documents', [LoanApplicationController::class, 'uploadAdditionalDocs']);
Route::get('/loan/applications/{id}', [LoanApplicationController::class, 'show']);
Route::post('/loan/applications/{id}/cancel', [LoanApplicationController::class, 'cancel']);
Route::get('/loan/applications', [LoanApplicationController::class, 'index']);
Route::get('/partners', [LoanApplicationController::class, 'getAffiliatePartners']);
Route::get('/admin/partners', [LoanApplicationController::class, 'getAffiliatePartners']);
Route::post('/admin/partners', [LoanApplicationController::class, 'saveAffiliatePartners']);
Route::delete('/admin/partners/{id}', [LoanApplicationController::class, 'deleteAffiliatePartner']);

use App\Http\Controllers\UrgentConstructionLoanController;
use App\Http\Controllers\EliteCashLoanController;

// Urgent Construction Loan Endpoints
Route::post('/loan/urgent-construction/apply', [UrgentConstructionLoanController::class, 'apply']);
Route::post('/loan/urgent-construction/{id}/payment', [UrgentConstructionLoanController::class, 'submitPayment']);
Route::post('/loan/urgent-construction/{id}/additional-docs', [UrgentConstructionLoanController::class, 'submitAdditionalDocs']);
Route::get('/loan/urgent-construction/active', [UrgentConstructionLoanController::class, 'getActiveUserApp']);
Route::get('/loan/urgent-construction/{id}', [UrgentConstructionLoanController::class, 'show']);
Route::get('/admin/urgent-construction-loans', [UrgentConstructionLoanController::class, 'adminIndex']);
Route::post('/admin/urgent-construction-loans/{id}/action', [UrgentConstructionLoanController::class, 'adminAction']);

// Elite Cash Loan Endpoints
Route::post('/loan/elite-cash/apply', [EliteCashLoanController::class, 'apply']);
Route::post('/loan/elite-cash/{id}/payment', [EliteCashLoanController::class, 'submitPayment']);
Route::post('/loan/elite-cash/{id}/additional-docs', [EliteCashLoanController::class, 'submitAdditionalDocs']);
Route::get('/loan/elite-cash/active', [EliteCashLoanController::class, 'getActiveUserApp']);
Route::get('/loan/elite-cash/{id}', [EliteCashLoanController::class, 'show']);
Route::get('/admin/elite-cash-loans', [EliteCashLoanController::class, 'adminIndex']);
Route::post('/admin/elite-cash-loans/{id}/action', [EliteCashLoanController::class, 'adminAction']);

// Virtual Loan Application Endpoints
Route::post('/loan/virtual-apply', [LoanApplicationController::class, 'virtualApply']);
Route::post('/loan/virtual-apply/{id}/documents', [LoanApplicationController::class, 'uploadVirtualDocuments']);
Route::post('/loan/virtual-apply/{id}/pay-fee', [LoanApplicationController::class, 'payVirtualFee']);
Route::get('/loan/virtual-loan/dashboard', [LoanApplicationController::class, 'getVirtualDashboard']);
Route::get('/loan/virtual-loan/details', [LoanApplicationController::class, 'getVirtualDashboard']);
Route::get('/settings/virtual-loan', [LoanApplicationController::class, 'getVirtualSettings']);
Route::get('/admin/settings/virtual-loan', [LoanApplicationController::class, 'getVirtualSettings']);
Route::post('/admin/settings/virtual-loan', [LoanApplicationController::class, 'updateVirtualSettings']);

// Admin Protected Routes (Authentication & Admin Role Required)
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // Admin Hostinger SMTP Pool Routes
    Route::get('/admin/smtp-pool', [AuthController::class, 'getSmtpPool']);
    Route::post('/admin/smtp-pool/add', [AuthController::class, 'addSmtpAccount']);
    Route::post('/admin/smtp-pool/{id}/toggle', [AuthController::class, 'toggleSmtpAccount']);
    Route::delete('/admin/smtp-pool/{id}', [AuthController::class, 'deleteSmtpAccount']);

    // Admin Affiliate Partners Hub Routes
    Route::get('/admin/partners', [LoanApplicationController::class, 'getAffiliatePartners']);
    Route::post('/admin/partners', [LoanApplicationController::class, 'saveAffiliatePartners']);
    Route::delete('/admin/partners/{id}', [LoanApplicationController::class, 'deleteAffiliatePartner']);

    // Admin Action Routes
    Route::post('/admin/applications/{id}/request-docs', [LoanApplicationController::class, 'adminRequestDocs']);
    Route::post('/admin/applications/{id}/configure-terms', [LoanApplicationController::class, 'adminConfigureTerms']);
    Route::post('/admin/applications/{id}/update-stage', [LoanApplicationController::class, 'updateStage']);
    Route::post('/admin/applications/{id}/approve-disbursement', [LoanApplicationController::class, 'adminApproveDisbursement']);
    Route::post('/admin/applications/{id}/undo-reject', [LoanApplicationController::class, 'adminUndoReject']);
});

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/auth/set-pin', [AuthController::class, 'setPin']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
