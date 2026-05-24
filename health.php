<?php
// Set the content type so the dashboard knows it's receiving JSON
header('Content-Type: application/json');
// Get CPU Load (Works on Linux servers)
$cpu_load = 0;
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $cpu_load = round($load[0] * 10, 2); // 1-minute load average scaled
}
// Get Memory Usage of this PHP script (in Megabytes)
$memory_usage = round(memory_get_usage(true) / 1024 / 1024, 2);
// Get Active Users from Database
require_once __DIR__ . '/config/database.php';
$active_users = 0;
try {
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $active_users = (int) $stmt->fetchColumn();
} catch (Exception $e) {
    // Fallback if DB is unavailable
}
$response = array(
    "status" => "ok",
    "cpu_usage" => $cpu_load,
    "memory_usage" => $memory_usage,
    "active_users" => $active_users,
    "version" => "1.0.0"
);
echo json_encode($response);
?>
