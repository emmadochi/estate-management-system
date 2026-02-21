<?php
/**
 * Database Configuration
 * Estate Management System
 * 
 * Update these values according to your XAMPP MySQL setup
 */

return [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'estate_management',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    
    // PDO Options
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
