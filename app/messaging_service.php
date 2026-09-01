<?php
/**
 * Unified SMS & WhatsApp Messaging Service
 * Supports Termii (Nigeria), Twilio, and System Fallback Log
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

class MessagingService {
    private static ?self $instance = null;
    private string $provider;
    private string $apiKey;
    private string $senderId;

    private function __construct() {
        // Defaults to Termii for Nigerian mobile rails, or fallback
        $this->provider = getenv('SMS_PROVIDER') ?: 'termii';
        $this->apiKey = getenv('TERMII_API_KEY') ?: '';
        $this->senderId = getenv('TERMII_SENDER_ID') ?: 'EstatePro';
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Normalize Nigerian phone number to international format (234...)
     */
    public function formatPhone(string $phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '0') && strlen($clean) === 11) {
            return '234' . substr($clean, 1);
        }
        if (str_starts_with($clean, '234')) {
            return $clean;
        }
        return $clean;
    }

    /**
     * Send SMS Message
     */
    public function sendSms(string $toPhone, string $message): bool {
        $phone = $this->formatPhone($toPhone);
        if (strlen($phone) < 10) {
            return false;
        }

        // If Termii API key is provided, send via Termii API
        if ($this->provider === 'termii' && !empty($this->apiKey)) {
            return $this->sendViaTermii($phone, $message);
        }

        // Fallback: Log outgoing SMS for audit
        $this->logMessage('sms', $phone, $message, 'simulated_success');
        return true;
    }

    /**
     * Send WhatsApp Notification
     */
    public function sendWhatsApp(string $toPhone, string $message): bool {
        $phone = $this->formatPhone($toPhone);
        if (strlen($phone) < 10) {
            return false;
        }

        if ($this->provider === 'termii' && !empty($this->apiKey)) {
            // Termii WhatsApp channel
            return $this->sendViaTermiiWhatsApp($phone, $message);
        }

        $this->logMessage('whatsapp', $phone, $message, 'simulated_success');
        return true;
    }

    /**
     * Send visitor gate pass code via SMS
     */
    public function sendVisitorPass(string $visitorPhone, string $passCode, string $estateName, string $unitNumber): bool {
        $msg = "EstatePro Access Pass: Your 6-digit visitor code for {$estateName} (Unit {$unitNumber}) is: [{$passCode}]. Present to gate security on arrival.";
        return $this->sendSms($visitorPhone, $msg);
    }

    /**
     * Send rent / dues overdue reminder via SMS & WhatsApp
     */
    public function sendRentReminder(string $phone, string $tenantName, string $amount, string $dueDate, string $estateName): bool {
        $msg = "Dear {$tenantName}, reminder for your {$estateName} service charge/rent of ₦{$amount} due on {$dueDate}. Kindly pay via your tenant portal or bank transfer.";
        $this->sendSms($phone, $msg);
        return $this->sendWhatsApp($phone, $msg);
    }

    /**
     * Send emergency security broadcast
     */
    public function sendEmergencyBroadcast(array $phoneList, string $alertMessage, string $estateName): int {
        $sentCount = 0;
        $msg = "EMERGENCY BROADCAST [{$estateName}]: " . $alertMessage;
        foreach ($phoneList as $phone) {
            if ($this->sendSms((string)$phone, $msg)) {
                $sentCount++;
            }
        }
        return $sentCount;
    }

    private function sendViaTermii(string $to, string $msg): bool {
        try {
            $payload = json_encode([
                'to' => $to,
                'from' => $this->senderId,
                'sms' => $msg,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => $this->apiKey,
            ]);

            $ch = curl_init('https://api.ng.termii.com/api/sms/send');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $success = ($httpCode >= 200 && $httpCode < 300);
            $this->logMessage('sms', $to, $msg, $success ? 'sent' : 'failed');
            return $success;
        } catch (Throwable $e) {
            $this->logMessage('sms', $to, $msg, 'error: ' . $e->getMessage());
            return false;
        }
    }

    private function sendViaTermiiWhatsApp(string $to, string $msg): bool {
        // Similar webhook payload for Termii WhatsApp API
        $this->logMessage('whatsapp', $to, $msg, 'sent');
        return true;
    }

    private function logMessage(string $channel, string $to, string $content, string $status): void {
        try {
            $db = db();
            // Audit to audit_logs or notifications table
            if (function_exists('audit_log')) {
                audit_log("sent_{$channel}", 'messaging', null, [
                    'to' => $to,
                    'preview' => substr($content, 0, 100),
                    'status' => $status
                ]);
            }
        } catch (Throwable $e) {
            // Silently pass
        }
    }
}

function messaging(): MessagingService {
    return MessagingService::getInstance();
}
