<?php
require 'config/database.php';
$db = get_db_connection();

echo "Before update, quantity of item 5:\n";
print_r($db->query('SELECT quantity_in_stock FROM rental_items WHERE id=5')->fetchColumn());

// Simulate the same code from view-rental.php
$id = 3;
$new_status = 'active';
$current_status = 'draft';

$db->exec("UPDATE rental_orders SET status='draft' WHERE id=$id");

$order_items = $db->query("SELECT rental_item_id, quantity FROM rental_order_items WHERE order_id=$id AND rental_item_id IS NOT NULL")->fetchAll();
if ($new_status === 'active' && $current_status === 'draft') {
    $upd = $db->prepare("UPDATE rental_items SET quantity_in_stock = quantity_in_stock - :qty WHERE id = :id");
    foreach($order_items as $oi) {
        $upd->execute(['qty' => $oi['quantity'], 'id' => $oi['rental_item_id']]);
    }
} 

echo "\nAfter update, quantity of item 5:\n";
print_r($db->query('SELECT quantity_in_stock FROM rental_items WHERE id=5')->fetchColumn());

