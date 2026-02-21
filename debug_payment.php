<?php
require_once 'app/bootstrap.php';

$db = db();

echo "=== Checking if vendor exists ===\n";
$vendor = $db->fetchOne('SELECT id, name, user_id FROM vendors WHERE id = 1');
var_dump($vendor);

echo "\n=== Checking invoices for vendor_id = 1 ===\n";
$invoices = $db->fetchAll('SELECT id, invoice_number, ticket_id, amount, status, paid_amount FROM maintenance_invoices WHERE vendor_id = 1');
foreach($invoices as $inv) {
    echo "Invoice ID: " . $inv['id'] . " - " . $inv['invoice_number'] . " - Status: " . $inv['status'] . " - Amount: " . $inv['amount'] . " - Paid: " . $inv['paid_amount'] . "\n";
}

echo "\n=== Checking payments for vendor's invoices ===\n";
$payments = $db->fetchAll('SELECT mp.*, mi.invoice_number FROM maintenance_payments mp JOIN maintenance_invoices mi ON mp.invoice_id = mi.id WHERE mi.vendor_id = 1');
foreach($payments as $p) {
    echo "Payment ID: " . $p['id'] . " - Invoice: " . $p['invoice_number'] . " - Amount: " . $p['amount'] . " - Status: " . $p['status'] . " - Vendor ID: " . $p['vendor_id'] . "\n";
}

echo "\n=== Checking if user with artisan role exists ===\n";
$artisanUser = $db->fetchOne('SELECT id, email, first_name, last_name FROM users WHERE role = "artisan" AND id = (SELECT user_id FROM vendors WHERE id = 1)');
var_dump($artisanUser);