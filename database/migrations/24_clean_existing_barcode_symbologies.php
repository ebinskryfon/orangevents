<?php
/**
 * Migration 24: Clean AIM Symbology Identifiers (e.g. ]C1, ]C0, ]Q1) from existing variant barcodes
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

function clean_db_barcode($val) {
    if ($val === null || $val === '') return null;
    $str = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', (string)$val);
    $str = trim($str);
    if ($str === '') return null;

    $str = preg_replace('/^(?:\][A-Za-z][0-9A-Za-z]?\s*)+/', '', $str);
    $str = preg_replace('/(?:\s*\][A-Za-z][0-9A-Za-z]?)+$/', '', $str);
    $str = preg_replace('/\][A-Za-z][0-9A-Za-z]?/', '', $str);
    $str = trim(preg_replace('/^\]+|\]+$/', '', $str));

    return $str !== '' ? $str : null;
}

try {
    $db->beginTransaction();

    $stmt = $db->query("SELECT id, barcode FROM billing_product_variants WHERE barcode IS NOT NULL AND barcode != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cleaned_count = 0;
    $stmt_update = $db->prepare("UPDATE billing_product_variants SET barcode = :barcode WHERE id = :id");

    foreach ($rows as $row) {
        $raw = $row['barcode'];
        $clean = clean_db_barcode($raw);

        if ($clean !== null && $clean !== $raw) {
            $stmt_update->execute(['barcode' => $clean, 'id' => $row['id']]);
            $cleaned_count++;
            echo "Cleaned barcode for variant ID {$row['id']}: '{$raw}' -> '{$clean}'\n";
        }
    }

    $db->commit();
    echo "✅ Migration 24 completed. Cleaned {$cleaned_count} variant barcode(s).\n";
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "❌ Migration 24 failed: " . $e->getMessage() . "\n";
}
