<?php
// Simple router for PHP development server
// Handles API requests and static file serving

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Handle API requests
if (strpos($path, '/api/') === 0) {
    // Remove /api/ prefix and add .php extension
    $apiFile = __DIR__ . $path;
    
    if (file_exists($apiFile)) {
        // Include the API file directly
        require $apiFile;
        return true;
    } else {
        // API file not found
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API endpoint not found']);
        return true;
    }
}

// Handle static files
$filePath = __DIR__ . $path;

if (file_exists($filePath) && is_file($filePath)) {
    // Serve static file
    return false; // Let PHP server handle it
}

// For everything else, serve index.html (for SPA routing)
if (file_exists(__DIR__ . '/index.html')) {
    readfile(__DIR__ . '/index.html');
    return true;
}

// Fallback
http_response_code(404);
echo "File not found";
return true;