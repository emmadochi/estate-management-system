<?php
/**
 * Run notifications table migration
 * Execute once: php database/run_notifications_migration.php
 */

require_once __DIR__ . '/db_connection.php';

$file = __DIR__ . '/migrations/2026_02_18_create_notifications_table.sql';
if (!file_exists($file)) {
    die("Migration file not found.\n");
}

$sql = file_get_contents($file);
$sql = preg_replace('/--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    foreach ($statements as $s) {
        if ($s !== '') {
            $conn->exec($s);
        }
    }
    echo "Notifications table created successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
