<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit();
}

require_once 'config.php';
require_once 'database.php';
require_once 'security.php';
require_once 'email-service.php';

// Initialize classes
$config = Config::getInstance();
$db = Database::getInstance();
$security = new SecurityManager();
$rateLimiter = new RateLimiter();
$emailService = new EmailService();

// Set CORS origin
$corsOrigin = $config->get('app.cors_origin');
if ($corsOrigin !== '*') {
    header("Access-Control-Allow-Origin: {$corsOrigin}");
} else {
    header('Access-Control-Allow-Origin: *');
}

/**
 * Send JSON response and exit
 */
function sendResponse(bool $success, string $message, array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('c')
    ]);
    exit();
}

/**
 * Log security events
 */
function logSecurityEvent(string $event, array $data = []): void {
    $logData = [
        'timestamp' => date('c'),
        'event' => $event,
        'ip' => (new SecurityManager())->getClientIP(),
        'user_agent' => (new SecurityManager())->getUserAgent(),
        'data' => $data
    ];
    error_log("SECURITY_EVENT: " . json_encode($logData));
}

try {
    // Create database tables if they don't exist
    $db->createTables();
    
    // Check rate limiting
    $clientIP = $security->getClientIP();
    if (!$rateLimiter->isAllowed('contact_form', $clientIP)) {
        logSecurityEvent('RATE_LIMIT_EXCEEDED', ['endpoint' => 'contact_form']);
        sendResponse(false, 'Too many requests. Please try again later.', [], 429);
    }

    // Get and decode JSON input
    $input = file_get_contents('php://input');
    if (empty($input)) {
        sendResponse(false, 'No data received.', [], 400);
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logSecurityEvent('INVALID_JSON', ['error' => json_last_error_msg()]);
        sendResponse(false, 'Invalid JSON data.', [], 400);
    }

    // Validate required fields
    $requiredFields = ['name', 'email', 'subject', 'message'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        sendResponse(false, 'Missing required fields: ' . implode(', ', $missingFields), [], 400);
    }

    // Sanitize and validate input
    $contactData = [
        'name' => $security->sanitizeInput($data['name']),
        'email' => $security->sanitizeInput($data['email']),
        'subject' => $security->sanitizeInput($data['subject']),
        'message' => $security->sanitizeInput($data['message']),
        'source' => isset($data['source']) ? $security->sanitizeInput($data['source']) : 'portfolio_website',
        'ip_address' => $clientIP,
        'user_agent' => $security->getUserAgent()
    ];

    // Validate field lengths
    $validationRules = [
        'name' => ['min' => 2, 'max' => 100],
        'email' => ['min' => 5, 'max' => 255],
        'subject' => ['min' => 3, 'max' => 200],
        'message' => ['min' => 10, 'max' => 2000]
    ];

    $validationErrors = [];
    foreach ($validationRules as $field => $rules) {
        $length = strlen($contactData[$field]);
        if ($length < $rules['min']) {
            $validationErrors[] = ucfirst($field) . " must be at least {$rules['min']} characters long.";
        }
        if ($length > $rules['max']) {
            $validationErrors[] = ucfirst($field) . " must not exceed {$rules['max']} characters.";
        }
    }

    if (!empty($validationErrors)) {
        sendResponse(false, 'Validation errors: ' . implode(' ', $validationErrors), [], 400);
    }

    // Validate email format
    if (!$security->validateEmail($contactData['email'])) {
        sendResponse(false, 'Please provide a valid email address.', [], 400);
    }

    // Check for potential spam patterns
    $spamKeywords = ['viagra', 'casino', 'lottery', 'winner', 'congratulations', 'claim now', 'urgent', 'act now'];
    $messageText = strtolower($contactData['message'] . ' ' . $contactData['subject']);
    
    foreach ($spamKeywords as $keyword) {
        if (strpos($messageText, $keyword) !== false) {
            logSecurityEvent('POTENTIAL_SPAM', ['keyword' => $keyword, 'message' => substr($contactData['message'], 0, 100)]);
            // Don't send error to user, just log it
            break;
        }
    }

    // Check for duplicate submissions (same email, subject within last 5 minutes)
    $recentSubmission = $db->query(
        "SELECT id FROM contacts WHERE email = :email AND subject = :subject AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1",
        ['email' => $contactData['email'], 'subject' => $contactData['subject']]
    )->fetch();

    if ($recentSubmission) {
        logSecurityEvent('DUPLICATE_SUBMISSION', ['email' => $contactData['email']]);
        sendResponse(false, 'Duplicate submission detected. Please wait before sending another message.', [], 429);
    }

    // Save to database
    $contactId = $db->insert('contacts', $contactData);
    
    if (!$contactId) {
        error_log("Failed to save contact form data");
        sendResponse(false, 'Failed to save your message. Please try again.', [], 500);
    }

    // Send notification emails
    $emailsSent = 0;
    
    // Add contact ID to the data for email logging
    $contactData['id'] = $contactId;
    
    // Send notification to admin
    try {
        if ($emailService->sendContactNotification($contactData)) {
            $emailsSent++;
        } else {
            error_log("Failed to send admin notification for contact ID: {$contactId}");
        }
    } catch (Exception $e) {
        error_log("Admin notification error: " . $e->getMessage());
    }

    // Send confirmation to user
    try {
        if ($emailService->sendContactConfirmation($contactData)) {
            $emailsSent++;
        } else {
            error_log("Failed to send user confirmation for contact ID: {$contactId}");
        }
    } catch (Exception $e) {
        error_log("User confirmation error: " . $e->getMessage());
    }

    // Log successful submission
    logSecurityEvent('CONTACT_FORM_SUBMITTED', [
        'contact_id' => $contactId,
        'email' => $contactData['email'],
        'emails_sent' => $emailsSent
    ]);

    // Update remaining requests for rate limiting info
    $remainingRequests = $rateLimiter->getRemainingRequests('contact_form', $clientIP);

    sendResponse(true, 'Thank you for your message! I will get back to you soon.', [
        'contact_id' => $contactId,
        'remaining_requests' => $remainingRequests
    ]);

} catch (Exception $e) {
    // Log the error
    error_log("Contact form error: " . $e->getMessage());
    
    // Send generic error response
    sendResponse(false, 'An unexpected error occurred. Please try again later.', [], 500);
}
