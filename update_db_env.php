<?php
/**
 * Safe Hostinger .env Database Configuration Updater
 * Updates MySQL credentials safely without modifying other keys or wiping data.
 */

$envFile = __DIR__ . '/.env';

if (!file_exists($envFile)) {
    if (file_exists(__DIR__ . '/.env.example')) {
        copy(__DIR__ . '/.env.example', $envFile);
    } else {
        file_put_contents($envFile, "APP_NAME=OpenScore\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://server.msmeloan.sbs\n");
    }
}

$envContent = file_get_contents($envFile);

$dbConfigs = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'u910898544_msmeloan2026',
    'DB_USERNAME' => 'u910898544_msmeloan_user',
    'DB_PASSWORD' => 'Msmeloan@2026',
];

foreach ($dbConfigs as $key => $val) {
    if (preg_match("/^{$key}=.*/m", $envContent)) {
        $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$val}", $envContent);
    } else {
        $envContent .= "\n{$key}={$val}";
    }
}

file_put_contents($envFile, $envContent);
echo "✓ MySQL database configuration safely synchronized in .env\n";
