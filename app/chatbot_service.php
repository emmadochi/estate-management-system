<?php
// AI Chatbot Service for 24/7 Tenant Support
// Handles chatbot logic, intent recognition, and response generation

class ChatbotService {
    private $db;
    private $tenantId;
    private $userId;
    
    public function __construct($tenantId = null, $userId = null) {
        $this->db = db();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }
    
    /**
     * Process a user message and generate appropriate response
     * 
     * @param string $message
     * @param int $conversationId
     * @return array
     */
    public function processMessage($message, $conversationId = null) {
        try {
            // Create or get conversation
            if ($conversationId === null) {
                $conversationId = $this->createConversation();
            } else {
                $this->updateConversationActivity($conversationId);
            }
            
            // Store user message
            $this->storeMessage($conversationId, 'tenant', $this->userId, $message);
            
            // Recognize intent
            $intentResult = $this->recognizeIntent($message);
            $intent = $intentResult['intent'];
            $confidence = $intentResult['confidence'];
            
            // Generate response
            $response = $this->generateResponse($intent, $message, $intentResult);
            
            // Store bot response
            $this->storeMessage($conversationId, 'chatbot', null, $response, $intent, $confidence);
            
            // Handle special cases
            if ($intentResult['requires_human_intervention']) {
                $this->escalateToHuman($conversationId);
            }
            
            return [
                'success' => true,
                'conversation_id' => $conversationId,
                'response' => $response,
                'intent' => $intent,
                'confidence' => $confidence,
                'requires_human_intervention' => $intentResult['requires_human_intervention']
            ];
            
        } catch (Exception $e) {
            error_log('Chatbot error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Sorry, I encountered an error. Please try again.'
            ];
        }
    }
    
    /**
     * Create a new conversation
     * 
     * @return int
     */
    private function createConversation() {
        return $this->db->insert(
            "INSERT INTO chatbot_conversations (tenant_id, status) VALUES (?, 'active')",
            [$this->tenantId]
        );
    }
    
    /**
     * Update conversation activity timestamp
     * 
     * @param int $conversationId
     */
    private function updateConversationActivity($conversationId) {
        $this->db->execute(
            "UPDATE chatbot_conversations SET started_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$conversationId]
        );
    }
    
    /**
     * Store a message in the database
     * 
     * @param int $conversationId
     * @param string $senderType
     * @param int|null $senderId
     * @param string $messageText
     * @param string|null $intent
     * @param float|null $confidence
     */
    private function storeMessage($conversationId, $senderType, $senderId, $messageText, $intent = null, $confidence = null) {
        $this->db->insert(
            "INSERT INTO chatbot_messages (conversation_id, sender_type, sender_id, message_text, intent, confidence_score) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [$conversationId, $senderType, $senderId, $messageText, $intent, $confidence]
        );
    }
    
    /**
     * Recognize intent from user message
     * 
     * @param string $message
     * @return array
     */
    private function recognizeIntent($message) {
        $message = strtolower(trim($message));
        
        // Get all intents from database
        $intents = $this->db->fetchAll(
            "SELECT id, intent_name, keywords, requires_authentication, requires_human_intervention 
             FROM chatbot_intents 
             WHERE 1=1"
        );
        
        $bestMatch = null;
        $highestScore = 0;
        
        foreach ($intents as $intent) {
            $keywords = json_decode($intent['keywords'], true);
            $score = $this->calculateMatchScore($message, $keywords);
            
            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch = $intent;
            }
        }
        
        // Return results with confidence threshold
        $confidenceThreshold = 0.3;
        if ($highestScore >= $confidenceThreshold && $bestMatch) {
            return [
                'intent' => $bestMatch['intent_name'],
                'confidence' => $highestScore,
                'requires_authentication' => (bool)$bestMatch['requires_authentication'],
                'requires_human_intervention' => (bool)$bestMatch['requires_human_intervention']
            ];
        }
        
        // Fallback intent for unrecognized messages
        return [
            'intent' => 'fallback',
            'confidence' => 0.1,
            'requires_authentication' => false,
            'requires_human_intervention' => false
        ];
    }
    
    /**
     * Calculate match score between message and keywords
     * 
     * @param string $message
     * @param array $keywords
     * @return float
     */
    private function calculateMatchScore($message, $keywords) {
        $score = 0;
        $totalKeywords = count($keywords);
        
        if ($totalKeywords === 0) return 0;
        
        foreach ($keywords as $keyword) {
            $keyword = strtolower($keyword);
            if (strpos($message, $keyword) !== false) {
                // Exact matches get higher scores
                if ($message === $keyword) {
                    $score += 2;
                } else {
                    $score += 1;
                }
            }
        }
        
        return min(1.0, $score / $totalKeywords);
    }
    
    /**
     * Generate appropriate response based on intent
     * 
     * @param string $intent
     * @param string $message
     * @param array $intentResult
     * @return string
     */
    private function generateResponse($intent, $message, $intentResult) {
        // Get response template from database
        $intentData = $this->db->fetchOne(
            "SELECT response_template FROM chatbot_intents WHERE intent_name = ?",
            [$intent]
        );
        
        if (!$intentData) {
            return "I'm not sure how to help with that. Can you please rephrase your question or ask about something else?";
        }
        
        $responseTemplate = $intentData['response_template'];
        
        // Handle authentication requirements
        if ($intentResult['requires_authentication'] && !$this->tenantId) {
            return "I'd be happy to help with that, but I need you to be logged in as a tenant first. Please log in to your account and try again.";
        }
        
        // Handle specific intent responses
        switch ($intent) {
            case 'lease_info':
                return $this->getLeaseInfoResponse();
                
            case 'payment_info':
                return $this->getPaymentInfoResponse();
                
            case 'maintenance_request':
                return $this->getMaintenanceRequestResponse();
                
            case 'fallback':
                return $this->getFallbackResponse($message);
                
            default:
                return $responseTemplate;
        }
    }
    
    /**
     * Get lease information response
     * 
     * @return string
     */
    private function getLeaseInfoResponse() {
        if (!$this->tenantId) {
            return "Please log in to access your lease information.";
        }
        
        $lease = $this->db->fetchOne(
            "SELECT l.lease_number, l.start_date, l.end_date, l.rent_amount, l.payment_frequency,
                    u.unit_number, p.name as property_name
             FROM leases l
             JOIN units u ON l.unit_id = u.id
             JOIN properties p ON u.property_id = p.id
             WHERE l.tenant_id = ? AND l.status = 'active'
             ORDER BY l.start_date DESC
             LIMIT 1",
            [$this->tenantId]
        );
        
        if (!$lease) {
            return "I couldn't find an active lease for your account. Please contact our support team for assistance.";
        }
        
        return "Here's your lease information:\n" .
               "📋 Lease Number: " . $lease['lease_number'] . "\n" .
               "🏠 Property: " . $lease['property_name'] . " - Unit " . $lease['unit_number'] . "\n" .
               "💰 Rent Amount:₦ " . number_format($lease['rent_amount'], 2) . " (" . $lease['payment_frequency'] . ")\n" .
               "📅 Lease Period: " . date('M j, Y', strtotime($lease['start_date'])) . " to " . date('M j, Y', strtotime($lease['end_date'])) . "\n" .
               "Is there anything specific about your lease you'd like to know?";
    }
    
    /**
     * Get payment information response
     * 
     * @return string
     */
    private function getPaymentInfoResponse() {
        if (!$this->tenantId) {
            return "Please log in to access your payment information.";
        }
        
        $outstandingInvoices = $this->db->fetchAll(
            "SELECT id, invoice_number, amount, due_date, status
             FROM invoices 
             WHERE tenant_id = ? AND status IN ('pending', 'overdue')
             ORDER BY due_date ASC",
            [$this->tenantId]
        );
        
        if (empty($outstandingInvoices)) {
            return "You don't have any outstanding payments at the moment. All your invoices are up to date!";
        }
        
        $response = "Here are your outstanding payments:\n\n";
        $totalAmount = 0;
        
        foreach ($outstandingInvoices as $invoice) {
            $status = $invoice['status'] === 'overdue' ? '🔴 OVERDUE' : '🟡 PENDING';
            $response .= "📝 Invoice #" . $invoice['invoice_number'] . " - " . $status . "\n";
            $response .= "   Amount:₦ ." . number_format($invoice['amount'], 2) . "\n";
            $response .= "   Due: " . date('M j, Y', strtotime($invoice['due_date'])) . "\n\n";
            $totalAmount += $invoice['amount'];
        }
        
        $response .= "💰 Total Outstanding:₦ ." . number_format($totalAmount, 2) . "\n\n";
        $response .= "Would you like to make a payment or need more details about any specific invoice?";
        
        return $response;
    }
    
    /**
     * Get maintenance request response
     * 
     * @return string
     */
    private function getMaintenanceRequestResponse() {
        if (!$this->tenantId) {
            return "Please log in to submit a maintenance request.";
        }
        
        return "I can help you submit a maintenance request. Please provide:\n\n" .
               "1. What type of issue is it? (e.g., plumbing, electrical, door, etc.)\n" .
               "2. Where exactly is the problem located? (e.g., kitchen, bedroom, bathroom)\n" .
               "3. How urgent is this? (low, medium, high, urgent)\n" .
               "4. A brief description of the problem\n\n" .
               "You can also go to your Maintenance Tickets section to create a detailed request with photos.";
    }
    
    /**
     * Get fallback response for unrecognized messages
     * 
     * @param string $message
     * @return string
     */
    private function getFallbackResponse($message) {
        $suggestions = [
            "You can ask me about your lease details, payments, or submit maintenance requests.",
            "I can help with your rental agreement, billing information, or maintenance issues.",
            "Try asking about your lease, payments, or reporting maintenance problems."
        ];
        
        $randomSuggestion = $suggestions[array_rand($suggestions)];
        
        return "I'm not sure I understand your request. " . $randomSuggestion . " What would you like help with?";
    }
    
    /**
     * Escalate conversation to human agent
     * 
     * @param int $conversationId
     */
    private function escalateToHuman($conversationId) {
        $this->db->execute(
            "UPDATE chatbot_conversations SET status = 'escalated' WHERE id = ?",
            [$conversationId]
        );
    }
    
    /**
     * Get conversation history
     * 
     * @param int $conversationId
     * @param int $limit
     * @return array
     */
    public function getConversationHistory($conversationId, $limit = 50) {
        return $this->db->fetchAll(
            "SELECT sender_type, message_text, created_at, intent
             FROM chatbot_messages 
             WHERE conversation_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$conversationId, $limit]
        );
    }
}