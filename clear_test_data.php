<?php
/**
 * OpenScore — Production DB Cleaner (Pure PHP/PDO)
 * ==================================================
 * No Laravel bootstrap required. Reads .env directly.
 * Run: php clear_test_data.php --confirm
 *
 * CLEARS:    loan_applications, user_wallet_cards, wallet_transactions,
 *            applicant_profiles, disbursement_details, partner_submissions,
 *            otp_logs, mail_alerts, personal_access_tokens,
 *            non-admin users, sessions, cache, jobs, password_reset_tokens
 * PRESERVES: admin users, system_settings, smtp_pools
 */

// ── Safety gate ──────────────────────────────────────────────────────────────
if (!in_array('--confirm', $argv ?? [])) {
    echo "\n⚠️  DANGER ZONE — This script deletes all loan/user/wallet data!\n";
    echo "Run with --confirm flag to proceed:\n\n";
    echo "  php clear_test_data.php --confirm\n\n";
    exit(0);
}

// ── Read .env ────────────────────────────────────────────────────────────────
function readEnv(string $path): array
{
    $vars = [];
    if (!file_exists($path)) return $vars;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $vars[trim($key)] = trim($val, " \t\n\r\0\x0B\"'");
    }
    return $vars;
}

$env = readEnv(__DIR__ . '/.env');

$host   = $env['DB_HOST']     ?? '127.0.0.1';
$port   = $env['DB_PORT']     ?? '3306';
$dbname = $env['DB_DATABASE'] ?? '';
$user   = $env['DB_USERNAME'] ?? '';
$pass   = $env['DB_PASSWORD'] ?? '';

if (!$dbname || !$user) {
    echo "❌  Could not read DB credentials from .env\n";
    exit(1);
}

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "❌  DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n========================================\n";
echo "  OpenScore — DB Cleaner (--confirm)\n";
echo "  DB: {$dbname} @ {$host}\n";
echo "========================================\n\n";

// ── Disable FK checks ─────────────────────────────────────────────────────────
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');

// ── Tables to TRUNCATE ────────────────────────────────────────────────────────
$tables = [
    'wallet_transactions'    => 'Wallet Transactions',
    'user_wallet_cards'      => 'User Wallet Cards',
    'loan_applications'      => 'Loan Applications',
    'applicant_profiles'     => 'Applicant Profiles',
    'disbursement_details'   => 'Disbursement Details',
    'partner_submissions'    => 'Partner Submissions',
    'otp_logs'               => 'OTP Logs',
    'mail_alerts'            => 'Mail Alerts',
    'personal_access_tokens' => 'Auth Tokens',
    'password_reset_tokens'  => 'Password Resets',
    'sessions'               => 'Sessions',
    'cache'                  => 'Cache',
    'cache_locks'            => 'Cache Locks',
    'jobs'                   => 'Jobs',
    'job_batches'            => 'Job Batches',
    'failed_jobs'            => 'Failed Jobs',
];

foreach ($tables as $table => $label) {
    try {
        // check table exists
        $check = $pdo->query("SHOW TABLES LIKE '{$table}'")->rowCount();
        if ($check === 0) {
            echo "  ⏭️  {$label} ({$table}) — table not found, skipped\n";
            continue;
        }
        $before = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $pdo->exec("TRUNCATE TABLE `{$table}`");
        echo "  ✓  {$label} ({$table}) — {$before} rows cleared\n";
    } catch (PDOException $e) {
        echo "  ⚠️  {$label} ({$table}) — " . $e->getMessage() . "\n";
    }
}

// ── Delete non-admin users ────────────────────────────────────────────────────
try {
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'")->fetchColumn();
    $stmt = $pdo->prepare("DELETE FROM `users` WHERE `role` != 'admin'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "  ✓  Users — {$deleted} non-admin rows deleted (kept {$adminCount} admin accounts)\n";
} catch (PDOException $e) {
    echo "  ⚠️  Users — " . $e->getMessage() . "\n";
}

// ── Re-enable FK checks ───────────────────────────────────────────────────────
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');

// ── Reset AUTO_INCREMENT ──────────────────────────────────────────────────────
foreach (['loan_applications', 'user_wallet_cards', 'wallet_transactions', 'applicant_profiles'] as $t) {
    try {
        $pdo->exec("ALTER TABLE `{$t}` AUTO_INCREMENT = 1");
    } catch (PDOException $e) {}
}

echo "\n========================================\n";
echo "  ✅  Done! DB is clean for fresh testing.\n";
echo "  🔒  Admin users & system_settings kept.\n";
echo "========================================\n\n";
