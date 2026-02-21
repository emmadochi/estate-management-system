<?php
require_once __DIR__ . '/db_connection.php';

$migrationFile = __DIR__ . '/migrations/2026_02_18_add_avatar_to_users.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

$sql = file_get_contents($migrationFile);

try {
    $db = Database::getInstance();
    $db->execute($sql);
    echo "Avatar column added to users table successfully.\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
