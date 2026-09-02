<?php
/**
 * AJAX API Endpoint to handle Drag-and-Drop Category & Item Reordering
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized session access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $input = $_POST;
}

$type  = trim($input['type'] ?? '');
$order = $input['order'] ?? [];

if (empty($type) || !is_array($order) || empty($order)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters provided.']);
    exit;
}

$table_map = [
    'catering'   => 'menu_categories',
    'billing'    => 'billing_categories',
    'rental'     => 'rental_categories',
    'stage_item' => 'stage_items'
];

if (!isset($table_map[$type])) {
    echo json_encode(['success' => false, 'error' => 'Unsupported entity type.']);
    exit;
}

$table_name = $table_map[$type];

try {
    $db = get_db_connection();
    $db->beginTransaction();

    $stmt = $db->prepare("UPDATE {$table_name} SET display_order = :order_val WHERE id = :id");

    foreach ($order as $index => $id) {
        $clean_id = (int)$id;
        $order_val = $index + 1;
        if ($clean_id > 0) {
            $stmt->execute(['order_val' => $order_val, 'id' => $clean_id]);
        }
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Order updated successfully!'
    ]);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Failed to save order: ' . $e->getMessage()]);
}
