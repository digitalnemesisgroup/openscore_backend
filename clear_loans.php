<?php
$dbHost = '127.0.0.1';
$dbName = 'u910898544_msmeloan2026';
$dbUser = 'u910898544_msmeloan_user';
$dbPass = 'Msmeloan@2026';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $tables = ['loan_applications', 'applicant_profiles', 'disbursement_details', 'partner_submissions'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`;");
            echo "Truncated table: $table\n";
        } catch (\Exception $ex) {
            // Table may not exist or already empty
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "✓ All loan applications and applicant records cleared from database.\n";
} catch (\Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
