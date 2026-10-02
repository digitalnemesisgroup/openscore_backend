<?php
/**
 * Script to completely erase and re-migrate the database.
 * WARNING: This will destroy all data in the database and re-run migrations and seeders!
 */

// Safety check for terminal
if (php_sapi_name() === 'cli' && !in_array('--confirm', $argv ?? [])) {
    echo "\n⚠️ DANGER: This will WIPE the entire database and re-migrate from scratch.\n";
    echo "Run with --confirm to proceed: php reset_db.php --confirm\n\n";
    exit(1);
}

// Ensure it's not accidentally run in a browser without some protection (like a secret key).
// E.g. reset_db.php?secret=my_secret
if (php_sapi_name() !== 'cli') {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'openscore_admin_reset') {
        die("Unauthorized.");
    }
}

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

// Bootstrap the console kernel to allow Artisan commands
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Running migrate:fresh --seed...\n";

try {
    \Illuminate\Support\Facades\Artisan::call('migrate:fresh', [
        '--seed' => true,
        '--force' => true
    ]);
    
    $output = \Illuminate\Support\Facades\Artisan::output();
    echo "<pre>" . htmlspecialchars($output) . "</pre>";
    echo "\n✅ SUCCESS: Database has been completely erased, migrated, and seeded with tables!\n";
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
}
