<?php
/**
 * Migration 13: Populate unique EAN-13 barcodes for existing billing product variants
 */

require_once __DIR__ . '/../../config/database.php';

$db = get_db_connection();

function generate_migration_barcode($db) {
    do {
        // EAN-13 restricted prefix for internal use (200) + 9 random digits
        $barcode = '200' . str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        
        // Calculate EAN-13 check digit
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $num = (int)$barcode[$i];
            if ($i % 2 === 0) { // 0-indexed positions: 1st, 3rd, 5th, 7th, 9th, 11th (odd EAN positions)
                $sum += $num * 1;
            } else {            // 0-indexed positions: 2nd, 4th, 6th, 8th, 10th, 12th (even EAN positions)
                $sum += $num * 3;
            }
        }
        $checksum = (10 - ($sum % 10)) % 10;
        $final_barcode = $barcode . $checksum;
        
        // Verify uniqueness
        $stmt = $db->prepare("SELECT id FROM billing_product_variants WHERE barcode = :barcode");
        $stmt->execute(['barcode' => $final_barcode]);
    } while ($stmt->fetch());
    
    return $final_barcode;
}

try {
    $db->beginTransaction();
    
    // Find all variants that do not have a barcode
    $stmt = $db->query("SELECT id FROM billing_product_variants WHERE barcode IS NULL");
    $variants = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($variants)) {
        echo "ℹ️ Migration 13 - No existing product variants found without barcodes.\n";
    } else {
        $stmt_up = $db->prepare("UPDATE billing_product_variants SET barcode = :barcode WHERE id = :id");
        $count = 0;
        foreach ($variants as $id) {
            $barcode = generate_migration_barcode($db);
            $stmt_up->execute(['barcode' => $barcode, 'id' => $id]);
            $count++;
        }
        echo "✅ Migration 13 - Successfully populated unique barcodes for $count existing variants.\n";
    }
    
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo "❌ Error in Migration 13: " . $e->getMessage() . "\n";
}
