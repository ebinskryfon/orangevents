<?php
$lines = file('admin/view-invoice.php');
foreach ($lines as $num => $line) {
    if (stripos($line, 'Amount Paid') !== false || stripos($line, 'Rest to') !== false) {
        echo ($num + 1) . ": " . trim($line) . "\n";
    }
}
