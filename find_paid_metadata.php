<?php
$lines = file('admin/view-invoice.php');
foreach ($lines as $num => $line) {
    if (stripos($line, 'advance_paid_at') !== false || stripos($line, 'balance_paid_at') !== false) {
        echo ($num + 1) . ": " . trim($line) . "\n";
    }
}
