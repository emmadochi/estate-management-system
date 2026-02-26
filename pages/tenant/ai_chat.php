<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'AI Support Chat – EstatePro Tenant';
$pageHeading = '24/7 AI Support Assistant';
$db = db();

// Add custom CSS for chat interface
$extraCSS = '
<style>
/* Enhanced Chat Container */
.chat-container {
    height: 700px;
    display: flex;
    flex-direction: column;
    border-radius: 20px;
    overflow: hidden;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    position: relative;
}

/* Enhanced Chat Header */
.chat-header {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    color: #333;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
    z-index: 1;
}

.chat-header h3 {
    margin: 0;
    font-weight: 700;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #4361ee;
}

.chat-header h3 i {
    font-size: 1.5rem;
    animation: pulse 2s infinite;
}

.chat-status {
    font-size: 0.9rem;
    opacity: 0.8;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.chat-status::before {
    content: "";
    width: 10px;
    height: 10px;
    background: #4ade80;
    border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7);
    animation: pulse-ring 1.5s ease-out infinite;
}

/* Enhanced Chat Messages Area */
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(5px);
    position: relative;
    z-index: 1;
}

.chat-messages::-webkit-scrollbar {
    width: 6px;
}

.chat-messages::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb {
    background: rgba(67, 97, 238, 0.3);
    border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb:hover {
    background: rgba(67, 97, 238, 0.5);
}

/* Enhanced Message Styles */
.message {
    margin-bottom: 1.5rem;
    display: flex;
    max-width: 85%;
    animation: fadeInUp 0.3s ease-out;
}

.message.user {
    margin-left: auto;
    justify-content: flex-end;
}

.message.bot {
    margin-right: auto;
    justify-content: flex-start;
}

.message-content {
    padding: 1rem 1.25rem;
    border-radius: 20px;
    position: relative;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    line-height: 1.6;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.message.user .message-content {
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    color: white;
    border-bottom-right-radius: 6px;
    font-weight: 500;
}

.message.bot .message-content {
    background: white;
    color: #334155;
    border-bottom-left-radius: 6px;
    border: 1px solid rgba(0, 0, 0, 0.05);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.message:hover .message-content {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}

.message-timestamp {
    font-size: 0.7rem;
    opacity: 0.6;
    margin-top: 0.5rem;
    text-align: center;
    font-weight: 500;
}

/* Enhanced Typing Indicator */
.typing-indicator {
    display: none;
    padding: 1rem 1.25rem;
    background: white;
    border-radius: 20px;
    border-bottom-left-radius: 6px;
    border: 1px solid rgba(0, 0, 0, 0.05);
    width: fit-content;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    animation: fadeInUp 0.3s ease-out;
}

.typing-indicator span {
    height: 10px;
    width: 10px;
    background: #4361ee;
    border-radius: 50%;
    display: inline-block;
    margin: 0 3px;
    animation: typing-bounce 1.4s infinite ease-in-out;
}

.typing-indicator span:nth-child(1) { animation-delay: 0s; }
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

/* Enhanced Quick Actions */
.quick-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    background: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(5px);
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
    z-index: 1;
}

.quick-action-btn {
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    padding: 0.75rem 1.25rem;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    color: #475569;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    flex: 1;
    min-width: 120px;
    text-align: center;
}

.quick-action-btn:hover {
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    color: white;
    border-color: #4361ee;
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(67, 97, 238, 0.3);
}

.quick-action-btn:active {
    transform: translateY(0);
}

/* Enhanced Chat Input Area */
.chat-input-area {
    padding: 1.25rem 1.5rem;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid rgba(255, 255, 255, 0.2);
    display: flex;
    gap: 0.75rem;
    position: relative;
    z-index: 1;
}

.chat-input {
    flex: 1;
    border: 2px solid #e2e8f0;
    border-radius: 28px;
    padding: 0.875rem 1.5rem;
    outline: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 1rem;
    font-weight: 500;
    background: white;
    color: #334155;
}

.chat-input:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
    transform: translateY(-1px);
}

.chat-input::placeholder {
    color: #94a3b8;
    font-weight: 400;
}

/* Enhanced Send Button */
.chat-send-btn {
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    color: white;
    border: none;
    border-radius: 50%;
    width: 52px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    position: relative;
    overflow: hidden;
}

.chat-send-btn::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.2);
    transform: scale(0);
    transition: transform 0.3s ease;
    border-radius: 50%;
}

.chat-send-btn:hover {
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 8px 20px rgba(67, 97, 238, 0.4);
}

.chat-send-btn:hover::before {
    transform: scale(1);
}

.chat-send-btn:active {
    transform: translateY(-1px) scale(0.98);
}

.chat-send-btn:disabled {
    background: #cbd5e1;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.chat-send-btn i {
    font-size: 1.25rem;
    transition: transform 0.2s ease;
}

.chat-send-btn:hover:not(:disabled) i {
    transform: translateX(2px);
}

/* Enhanced Welcome Message */
.chat-welcome {
    text-align: center;
    padding: 3rem 2rem;
    color: #64748b;
    animation: fadeIn 0.5s ease-out;
}

.welcome-animation {
    margin-bottom: 1.5rem;
}

.chat-welcome i {
    font-size: 4rem;
    color: #4361ee;
    margin-bottom: 1.5rem;
    animation: float 3s ease-in-out infinite;
}

.chat-welcome h4 {
    margin: 1.5rem 0 1rem;
    color: #1e293b;
    font-size: 1.5rem;
    font-weight: 700;
}

.chat-welcome p {
    margin: 0;
    line-height: 1.7;
    font-size: 1.1rem;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 2rem;
}

.welcome-features {
    display: flex;
    justify-content: center;
    gap: 2rem;
    flex-wrap: wrap;
    margin-top: 1.5rem;
}

.feature-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 16px;
    min-width: 120px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.feature-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(67, 97, 238, 0.15);
    background: rgba(67, 97, 238, 0.1);
}

.feature-item i {
    font-size: 1.5rem;
}

.feature-item span {
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
}

/* Enhanced New Chat Button */
#newChatBtn {
    background: rgba(255, 255, 255, 0.9);
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.5rem 1rem;
    font-weight: 600;
    color: #475569;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

#newChatBtn:hover {
    background: #4361ee;
    color: white;
    border-color: #4361ee;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-15px); }
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

@keyframes pulse-ring {
    0% {
        transform: scale(0.33);
        box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7);
    }
    80%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 10px rgba(74, 222, 128, 0);
    }
}

@keyframes typing-bounce {
    0%, 60%, 100% { 
        transform: translateY(0);
        opacity: 0.5;
    }
    30% { 
        transform: translateY(-8px);
        opacity: 1;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .chat-container {
        height: calc(100vh - 100px);
        border-radius: 15px;
    }
    
    .message {
        max-width: 90%;
    }
    
    .quick-actions {
        flex-direction: column;
    }
    
    .quick-action-btn {
        min-width: auto;
    }
    
    .chat-header h3 {
        font-size: 1.1rem;
    }
}

</style>
';

$GLOBALS['extra_css'] = $extraCSS;

require __DIR__ . '/partials/top.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">AI Support Assistant</h3>
                <div class="card-toolbar">
                    <span class="badge badge-light-primary">24/7 Available</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="chat-container">
                    <div class="chat-header">
                        <div>
                            <h3><i class="ki-duotone ki-message-programming fs-2 me-2"><span class="path1"></span><span class="path2"></span></i>EstatePro AI Assistant</h3>
                            <div class="chat-status">Always here to help you</div>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-light" id="newChatBtn">
                                <i class="ki-duotone ki-plus fs-4"><span class="path1"></span><span class="path2"></span></i>Start New Chat
                            </button>
                        </div>
                    </div>
                    
                    <div class="chat-messages" id="chatMessages">
                        <div class="chat-welcome" id="welcomeMessage">
                            <div class="welcome-animation">
                                <i class="ki-duotone ki-robot fs-2x"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                            </div>
                            <h4>Welcome to EstatePro AI Assistant!</h4>
                            <p>I'm here 24/7 to help you with maintenance requests, lease information, payments, and more.<br>What would you like assistance with today?</p>
                            <div class="welcome-features">
                                <div class="feature-item">
                                    <i class="ki-duotone ki-home fs-4 text-primary"><span class="path1"></span><span class="path2"></span></i>
                                    <span>Lease Information</span>
                                </div>
                                <div class="feature-item">
                                    <i class="ki-duotone ki-dollar fs-4 text-success"><span class="path1"></span><span class="path2"></span></i>
                                    <span>Payment Support</span>
                                </div>
                                <div class="feature-item">
                                    <i class="ki-duotone ki-tools fs-4 text-warning"><span class="path1"></span><span class="path2"></span></i>
                                    <span>Maintenance Help</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="typing-indicator" id="typingIndicator">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    
                    <div class="quick-actions">
                        <button class="quick-action-btn" data-message="What's my lease information?">Lease Info</button>
                        <button class="quick-action-btn" data-message="Do I have any outstanding payments?">Payment Status</button>
                        <button class="quick-action-btn" data-message="I need to report a maintenance issue">Maintenance</button>
                        <button class="quick-action-btn" data-message="What are the estate rules?">Estate Info</button>
                    </div>
                    
                    <div class="chat-input-area">
                        <input type="text" class="chat-input" id="messageInput" placeholder="Type your message here..." autocomplete="off">
                        <button class="chat-send-btn" id="sendButton">
                            <i class="ki-duotone ki-send fs-2"><span class="path1"></span><span class="path2"></span></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentConversationId = null;
let isSending = false;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize chat
    initializeChat();
    
    // Load existing conversation if any
    loadActiveConversation();
});

function initializeChat() {
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');
    const newChatBtn = document.getElementById('newChatBtn');
    
    // Send message on Enter key
    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // Send button click
    sendButton.addEventListener('click', sendMessage);
    
    // New chat button
    newChatBtn.addEventListener('click', startNewChat);
    
    // Quick action buttons
    document.querySelectorAll('.quick-action-btn').forEach(button => {
        button.addEventListener('click', function() {
            const message = this.getAttribute('data-message');
            messageInput.value = message;
            sendMessage();
        });
    });
}

function loadActiveConversation() {
    // Use relative path to work with subdirectory installations
    // Calculate base URL by going from pages/tenant/ to the main directory where api/ exists
    // From pages/tenant/ai_chat.php -> go up 2 levels to reach main directory
    const currentPath = window.location.pathname;
    const pathSegments = currentPath.split('/');
    pathSegments.pop(); // Remove the current file (ai_chat.php)
    pathSegments.pop(); // Go up one level (from tenant/ to pages/)
    pathSegments.pop(); // Go up another level (from pages/ to main directory)
    const basePath = pathSegments.join('/') || '';
    const apiUrl = `${basePath}/api/chatbot.php?action=active_conversation`;
    
    fetch(apiUrl)
        .then(response => {
            // Check if response is ok and has JSON content type
            if (!response.ok) {
                if (response.status === 401) {
                    // Handle unauthorized access gracefully
                    throw new Error('Authentication required. Please log in as a tenant to access the chatbot.');
                } else {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                // Response is not JSON, probably an error page
                return response.text().then(text => {
                    console.error('Non-JSON response received:', text);
                    throw new Error('API did not return JSON data');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.conversation_id) {
                currentConversationId = data.conversation_id;
                displayConversationHistory(data.history);
            }
        })
        .catch(error => {
            // Handle 401 errors gracefully without showing scary error messages
            if (error.message.includes('Authentication required')) {
                console.log('Chatbot requires authentication. User needs to log in as tenant.');
                // Optionally show a subtle notification about needing to log in
            } else {
                console.error('Error loading conversation:', error);
            }
        });
}

function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();
    
    if (!message || isSending) return;
    
    isSending = true;
    showTypingIndicator();
    
    // Add user message to chat
    addMessageToChat('user', message);
    messageInput.value = '';
    
    // Send to API
    // Calculate base URL by going from pages/tenant/ to the main directory where api/ exists
    // From pages/tenant/ai_chat.php -> go up 2 levels to reach main directory
    const currentPath = window.location.pathname;
    const pathSegments = currentPath.split('/');
    pathSegments.pop(); // Remove the current file (ai_chat.php)
    pathSegments.pop(); // Go up one level (from tenant/ to pages/)
    pathSegments.pop(); // Go up another level (from pages/ to main directory)
    const basePath = pathSegments.join('/') || '';
    const apiUrl = `${basePath}/api/chatbot.php`;
    
    fetch(apiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            message: message,
            conversation_id: currentConversationId
        })
    })
    .then(response => {
        // Check if response is ok and has JSON content type
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // Response is not JSON, probably an error page
            return response.text().then(text => {
                console.error('Non-JSON response received:', text);
                throw new Error('API did not return JSON data');
            });
        }
        return response.json();
    })
    .then(data => {
        hideTypingIndicator();
        isSending = false;
        
        if (data.success) {
            currentConversationId = data.conversation_id;
            addMessageToChat('bot', data.response);
            
            // Handle escalation to human agent
            if (data.requires_human_intervention) {
                showEscalationMessage();
            }
        } else {
            addMessageToChat('bot', 'Sorry, I encountered an error. Please try again.');
        }
    })
    .catch(error => {
        hideTypingIndicator();
        isSending = false;
        
        // Display appropriate error message based on error type
        let errorMessage = 'Sorry, I encountered an error. Please try again.';
        if (error.message.includes('Authentication required')) {
            errorMessage = 'Please log in as a tenant to use the chatbot.';
        } else if (error.message.includes('API did not return JSON')) {
            errorMessage = 'Service temporarily unavailable. Please try again later.';
        }
        
        addMessageToChat('bot', errorMessage);
        console.error('Error:', error);
    });
}

function addMessageToChat(sender, message) {
    const chatMessages = document.getElementById('chatMessages');
    const welcomeMessage = document.getElementById('welcomeMessage');
    
    // Hide welcome message if it's visible
    if (welcomeMessage) {
        welcomeMessage.style.display = 'none';
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}`;
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'message-content';
    contentDiv.textContent = message;
    
    const timestampDiv = document.createElement('div');
    timestampDiv.className = 'message-timestamp';
    timestampDiv.textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    
    messageDiv.appendChild(contentDiv);
    messageDiv.appendChild(timestampDiv);
    chatMessages.appendChild(messageDiv);
    
    // Scroll to bottom
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function displayConversationHistory(history) {
    const chatMessages = document.getElementById('chatMessages');
    const welcomeMessage = document.getElementById('welcomeMessage');
    
    if (welcomeMessage) {
        welcomeMessage.style.display = 'none';
    }
    
    history.forEach(msg => {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${msg.sender === 'tenant' ? 'user' : 'bot'}`;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        contentDiv.textContent = msg.message;
        
        const timestampDiv = document.createElement('div');
        timestampDiv.className = 'message-timestamp';
        timestampDiv.textContent = new Date(msg.timestamp).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        
        messageDiv.appendChild(contentDiv);
        messageDiv.appendChild(timestampDiv);
        chatMessages.appendChild(messageDiv);
    });
    
    // Scroll to bottom
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function showTypingIndicator() {
    const typingIndicator = document.getElementById('typingIndicator');
    typingIndicator.style.display = 'block';
    document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
}

function hideTypingIndicator() {
    const typingIndicator = document.getElementById('typingIndicator');
    typingIndicator.style.display = 'none';
}

function startNewChat() {
    currentConversationId = null;
    document.getElementById('chatMessages').innerHTML = `
        <div class="chat-welcome" id="welcomeMessage">
            <div class="welcome-animation">
                <i class="ki-duotone ki-robot fs-2x"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
            </div>
            <h4>New Chat Started!</h4>
            <p>How can I help you today?</p>
            <div class="welcome-features">
                <div class="feature-item">
                    <i class="ki-duotone ki-home fs-4 text-primary"><span class="path1"></span><span class="path2"></span></i>
                    <span>Lease Information</span>
                </div>
                <div class="feature-item">
                    <i class="ki-duotone ki-dollar fs-4 text-success"><span class="path1"></span><span class="path2"></span></i>
                    <span>Payment Support</span>
                </div>
                <div class="feature-item">
                    <i class="ki-duotone ki-tools fs-4 text-warning"><span class="path1"></span><span class="path2"></span></i>
                    <span>Maintenance Help</span>
                </div>
            </div>
        </div>
    `;
}

function showEscalationMessage() {
    setTimeout(() => {
        addMessageToChat('bot', 'I\'ve notified our support team. A human representative will be with you shortly to assist with this complex issue.');
    }, 1000);
}
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>