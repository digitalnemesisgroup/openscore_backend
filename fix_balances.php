<?php
$mysqli = new mysqli('153.92.15.11', 'u910898544_msmeloan2026', 'Msmeloan@2026', 'u910898544_msmeloan2026');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
$mysqli->query("UPDATE user_wallet_cards SET available_value = 0 WHERE verifying_status = 'PENDING_ADMIN_APPROVAL' AND available_value > 0");
echo "Updated " . $mysqli->affected_rows . " rows.\n";
$mysqli->close();
?>
