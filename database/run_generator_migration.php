<?php
/**
 * Generator & Diesel Suite Migration Runner
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

try {
    $db = db();
    $sql = file_get_contents(__DIR__ . '/migrations/2026_09_02_create_generator_and_diesel_suite.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read migration SQL file.');
    }

    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $db->execute($stmt);
        }
    }

    echo "✓ Generator & Diesel Suite Migration executed successfully.\n";

    // Seed default generator for existing estates if none exist
    $estates = $db->fetchAll('SELECT id, name FROM estates');
    foreach ($estates as $est) {
        $existing = $db->fetchOne('SELECT id FROM generators WHERE estate_id = ?', [(int)$est['id']]);
        if (!$existing) {
            $db->execute(
                "INSERT INTO generators (estate_id, name, capacity_kva, fuel_type, avg_consumption_litres_per_hour, current_run_hours, service_interval_hours, tank_capacity_litres, current_fuel_litres, status)
                 VALUES (?, 'Main 500kVA Soundproof Generator', 500.00, 'diesel', 35.00, 142.50, 250.00, 3000.00, 1850.00, 'active')",
                [(int)$est['id']]
            );
            $db->execute(
                "INSERT INTO generators (estate_id, name, capacity_kva, fuel_type, avg_consumption_litres_per_hour, current_run_hours, service_interval_hours, tank_capacity_litres, current_fuel_litres, status)
                 VALUES (?, 'Backup 250kVA Generator', 250.00, 'diesel', 20.00, 84.00, 250.00, 1500.00, 920.00, 'standby')",
                [(int)$est['id']]
            );
            echo "✓ Seeded 2 sample generators for estate: {$est['name']}\n";
        }
    }
} catch (Throwable $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
