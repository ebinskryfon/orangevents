<?php
/**
 * AJAX API: Verify venue and date availability
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$date = $_GET['date'] ?? '';
$venue = trim($_GET['venue'] ?? '');
$exclude_id = (int)($_GET['exclude_id'] ?? 0);

if (empty($date) || empty($venue)) {
    echo json_encode(['available' => true, 'message' => 'Missing parameter']);
    exit;
}

try {
    $db = get_db_connection();
    
    // Check if there's an event on the same day at the same venue
    $sql = "SELECT id, title, event_time FROM events WHERE event_date = :date AND LOWER(TRIM(venue)) = LOWER(TRIM(:venue))";
    $params = ['date' => $date, 'venue' => $venue];
    
    if ($exclude_id > 0) {
        $sql .= " AND id != :exclude_id";
        $params['exclude_id'] = $exclude_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clash = $stmt->fetch();
    
    if ($clash) {
        echo json_encode([
            'available' => false, 
            'message' => "Slot Clash: \"{$clash['title']}\" is already booked at this venue on this day at " . date('h:i A', strtotime($clash['event_time'])) . "."
        ]);
    } else {
        echo json_encode(['available' => true]);
    }
} catch (PDOException $e) {
    echo json_encode(['available' => false, 'message' => 'Database query failed: ' . $e->getMessage()]);
}
