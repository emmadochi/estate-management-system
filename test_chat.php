<?php
// Simulate tenant session for testing
session_start();

// Clear any existing session data
session_unset();

// Simulate tenant user (in real app, user would login)
$_SESSION['user'] = [
    'id' => 1,
    'role' => 'tenant',
    'name' => 'Test Tenant'
];

// Simulate tenant data
$_SESSION['tenant'] = [
    'id' => 1,
    'user_id' => 1,
    'status' => 'active'
];

// Redirect to chat page
header('Location: http://localhost:8000/pages/tenant/ai_chat.php');
exit;