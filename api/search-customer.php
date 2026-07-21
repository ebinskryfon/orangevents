<?php
/**
 * Customer Search & Live Auto-Fetch API Endpoint
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

session_start();

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized session.']);
    exit;
}

$db = get_db_connection();
$query = trim($_REQUEST['phone'] ?? $_REQUEST['query'] ?? '');

if (empty($query)) {
    echo json_encode(['success' => false, 'found' => false, 'error' => 'Query parameter missing.']);
    exit;
}

// Clean phone digits for phone search
$clean_digits = preg_replace('/[^0-9]/', '', $query);

try {
    $customer = null;

    if (!empty($clean_digits)) {
        // Search exact or partial phone match
        $stmt = $db->prepare("
            SELECT id, name, phone, email, address, city, gstin, total_orders, total_spent, notes
              FROM customers
             WHERE phone = :p OR phone LIKE :p_like
          ORDER BY (phone = :p) DESC, id DESC
             LIMIT 1
        ");
        $stmt->execute([
            'p'      => $clean_digits,
            'p_like' => '%' . $clean_digits . '%'
        ]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$customer && strlen($query) >= 2) {
        // Search by name
        $stmt_name = $db->prepare("
            SELECT id, name, phone, email, address, city, gstin, total_orders, total_spent, notes
              FROM customers
             WHERE name LIKE :q
          ORDER BY id DESC
             LIMIT 1
        ");
        $stmt_name->execute(['q' => '%' . $query . '%']);
        $customer = $stmt_name->fetch(PDO::FETCH_ASSOC);
    }

    if ($customer) {
        echo json_encode([
            'success'  => true,
            'found'    => true,
            'customer' => [
                'id'           => (int)$customer['id'],
                'name'         => $customer['name'],
                'phone'        => $customer['phone'],
                'email'        => $customer['email'] ?? '',
                'address'      => $customer['address'] ?? '',
                'city'         => $customer['city'] ?? '',
                'gstin'        => $customer['gstin'] ?? '',
                'total_orders' => (int)$customer['total_orders'],
                'total_spent'  => (float)$customer['total_spent'],
                'notes'        => $customer['notes'] ?? ''
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'found'   => false,
            'message' => 'No matching customer record found.'
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
