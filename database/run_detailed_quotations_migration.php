<?php
/**
 * Migration runner for detailed quotation system
 * Run once via browser or CLI: php database/run_detailed_quotations_migration.php
 */

require_once __DIR__ . '/db_connection.php';

\ = __DIR__ . '/migrations/2026_02_20_add_detailed_quotations.sql';

if (!file_exists(\)) {
    die(\
Migration
file
not
found:
\\\n\);
}

\ = file_get_contents(\);
if (\ === false) {
    die(\Could
not
read
migration
file.\\n\);
}

// Remove comments and empty lines, split by semicolons
\ = preg_replace('/--.*\$/m', '', \);
\ = preg_replace('/^\\s*\$/m', '', \);
\ = array_filter(array_map('trim', explode(';', \)));

if (empty(\)) {
    die(\No
SQL
statements
found
in
migration
file.\\n\);
}

try {
    \ = Database::getInstance();
    \ = \->getConnection();

    // Disable foreign key checks temporarily (in case of dependency issues)
    \->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach (\ as \) {
        if (trim(\) === '') {
            continue;
        }
        \->exec(\);
    }

    // Re-enable foreign key checks
    \->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo \Migration
completed
successfully!\\n\;
    echo \Detailed
quotation
system
applied.\\n\;

} catch (PDOException \) {
    echo \Error
running
migration:
\ . \->getMessage() . \\\n\;
    exit(1);
}
