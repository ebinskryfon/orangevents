<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
check_admin_auth();

$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    
    if ($event_id > 0) {
        // Check if a related invoice exists in the invoices table
        $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE event_id = :event_id");
        $stmt->execute(['event_id' => $event_id]);
        $has_invoice = (int)$stmt->fetchColumn() > 0;
        
        if ($has_invoice) {
            // Cannot delete event because it has a related invoice
            header("Location: index.php?error=has_invoice");
            exit;
        } else {
            // No related invoice, safe to delete event
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("DELETE FROM events WHERE id = :event_id");
                $stmt->execute(['event_id' => $event_id]);
                $db->commit();
                header("Location: index.php?success=event_deleted");
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                header("Location: index.php?error=delete_failed");
                exit;
            }
        }
    }
}

// Redirect back to dashboard if accessed directly or invalid request
header("Location: index.php");
exit;
