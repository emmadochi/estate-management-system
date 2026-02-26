<?php
// Chatbot API Endpoint
// Handles all chatbot-related requests

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

try {
    $method = request_method();
    $response = ['success' => false, 'message' => 'Invalid request'];
    
    // Handle different request methods
    switch ($method) {
        case 'POST':
            $response = handlePostRequest();
            break;
            
        case 'GET':
            $response = handleGetRequest();
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Method not allowed'];
            http_response_code(405);
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('Chatbot API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

/**
 * Handle POST requests (sending messages)
 */
function handlePostRequest() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['message'])) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Message is required'];
    }
    
    $message = trim($input['message']);
    $conversationId = $input['conversation_id'] ?? null;
    
    if (empty($message)) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Message cannot be empty'];
    }
    
    // Get tenant information if available
    $tenant = null;
    $tenantId = null;
    $userId = null;
    
    // Check if user is logged in as tenant
    if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'tenant') {
        $userId = (int)$_SESSION['user']['id'];
        $tenant = get_tenant_by_user_id($userId);
        if ($tenant) {
            $tenantId = (int)$tenant['id'];
        }
    }
    
    // Initialize chatbot service
    require_once __DIR__ . '/../app/chatbot_service.php';
    $chatbot = new ChatbotService($tenantId, $userId);
    
    // Process the message
    $result = $chatbot->processMessage($message, $conversationId);
    
    if ($result['success']) {
        return [
            'success' => true,
            'conversation_id' => $result['conversation_id'],
            'response' => $result['response'],
            'intent' => $result['intent'],
            'confidence' => $result['confidence'],
            'requires_human_intervention' => $result['requires_human_intervention']
        ];
    } else {
        http_response_code(500);
        return ['success' => false, 'message' => $result['error'] ?? 'Failed to process message'];
    }
}

/**
 * Handle GET requests (getting conversation history)
 */
function handleGetRequest() {
    $action = get_param('action', '');
    
    switch ($action) {
        case 'history':
            return getConversationHistory();
            
        case 'active_conversation':
            return getActiveConversation();
            
        default:
            http_response_code(400);
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

/**
 * Get conversation history
 */
function getConversationHistory() {
    $conversationId = (int)get_param('conversation_id', 0);
    
    if ($conversationId <= 0) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Conversation ID is required'];
    }
    
    // Verify user has access to this conversation
    if (!verifyConversationAccess($conversationId)) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied'];
    }
    
    require_once __DIR__ . '/../../app/chatbot_service.php';
    $chatbot = new ChatbotService();
    $history = $chatbot->getConversationHistory($conversationId, 50);
    
    // Format the history for the response
    $formattedHistory = [];
    foreach (array_reverse($history) as $message) {
        $formattedHistory[] = [
            'sender' => $message['sender_type'],
            'message' => $message['message_text'],
            'timestamp' => $message['created_at'],
            'intent' => $message['intent']
        ];
    }
    
    return [
        'success' => true,
        'conversation_id' => $conversationId,
        'history' => $formattedHistory
    ];
}

/**
 * Get active conversation
 */
function getActiveConversation() {
    // Check if user is logged in as tenant
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'tenant') {
        http_response_code(401);
        return ['success' => false, 'message' => 'Authentication required'];
    }
    
    $userId = (int)$_SESSION['user']['id'];
    $tenant = get_tenant_by_user_id($userId);
    
    if (!$tenant) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Tenant not found'];
    }
    
    $db = db();
    $tenantId = (int)$tenant['id'];
    
    // Get the most recent active conversation
    $conversation = $db->fetchOne(
        "SELECT id, started_at, status 
         FROM chatbot_conversations 
         WHERE tenant_id = ? AND status = 'active' 
         ORDER BY started_at DESC 
         LIMIT 1",
        [$tenantId]
    );
    
    if (!$conversation) {
        return ['success' => true, 'conversation_id' => null, 'history' => []];
    }
    
    // Get conversation history
    require_once __DIR__ . '/../app/chatbot_service.php';
    $chatbot = new ChatbotService($tenantId, $userId);
    $history = $chatbot->getConversationHistory($conversation['id'], 20);
    
    // Format the history
    $formattedHistory = [];
    foreach (array_reverse($history) as $message) {
        $formattedHistory[] = [
            'sender' => $message['sender_type'],
            'message' => $message['message_text'],
            'timestamp' => $message['created_at'],
            'intent' => $message['intent']
        ];
    }
    
    return [
        'success' => true,
        'conversation_id' => $conversation['id'],
        'history' => $formattedHistory,
        'status' => $conversation['status']
    ];
}

/**
 * Verify user has access to a conversation
 */
function verifyConversationAccess($conversationId) {
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    $db = db();
    $userId = (int)$_SESSION['user']['id'];
    
    // Check if user is a tenant
    if ($_SESSION['user']['role'] === 'tenant') {
        $tenant = get_tenant_by_user_id($userId);
        if (!$tenant) {
            return false;
        }
        
        $conversation = $db->fetchOne(
            "SELECT id FROM chatbot_conversations 
             WHERE id = ? AND tenant_id = ?",
            [$conversationId, (int)$tenant['id']]
        );
        
        return $conversation !== false;
    }
    
    // For other roles (admin, etc.), allow access
    return $_SESSION['user']['role'] !== 'tenant' || 
           $db->fetchOne("SELECT id FROM chatbot_conversations WHERE id = ?", [$conversationId]) !== false;
}

/**
 * Get tenant by user ID
 */
function get_tenant_by_user_id($userId) {
    $db = db();
    return $db->fetchOne(
        "SELECT t.* FROM tenants t 
         JOIN users u ON t.user_id = u.id 
         WHERE u.id = ? AND t.status = 'active'",
        [$userId]
    );
}