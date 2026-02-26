<?php
// Migration runner for chatbot tables
require_once __DIR__ . '/../app/bootstrap.php';

try {
    $db = db();
    
    // Read the migration SQL file
    $migrationSql = file_get_contents(__DIR__ . '/migrations/2026_02_25_create_chatbot_tables.sql');
    
    if ($migrationSql === false) {
        throw new Exception('Could not read migration file');
    }
    
    // Execute the migration
    $statements = explode(';', $migrationSql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $db->execute($statement);
        }
    }
    
    echo "Chatbot tables migration completed successfully!\n";
    echo "Created tables: chatbot_conversations, chatbot_messages, chatbot_intents, chatbot_training_data\n";
    
} catch (Exception $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}