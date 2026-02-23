<?php
require_once __DIR__ . '/app/bootstrap.php';

$db = db();
$users = $db->fetchAll("SELECT id, first_name, last_name, email, role FROM users WHERE role = 'security'");

echo "Security Users:\n";
foreach($users as $u) {
    echo $u['id'] . ' - ' . $u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['email'] . ")\n";
}