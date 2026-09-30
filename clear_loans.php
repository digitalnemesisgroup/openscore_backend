<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    Schema::disableForeignKeyConstraints();
    
    $tables = ['loan_applications', 'applicant_profiles', 'disbursement_details', 'partner_submissions'];
    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            DB::table($table)->truncate();
            echo "Truncated table: $table\n";
        }
    }
    
    Schema::enableForeignKeyConstraints();
    
    echo "✓ All loan applications and profiles have been safely cleared from database.\n";
} catch (\Exception $e) {
    echo "Error clearing loans: " . $e->getMessage() . "\n";
}
