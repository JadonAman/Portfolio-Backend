<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, Authorization');

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
    error_log("ADMIN_SECURITY_EVENT: " . json_encode($logData));
}

try {
    // Create database tables if they don't exist
    $db->createTables();
    
    // Check rate limiting
    $clientIP = $security->getClientIP();
    if (!$rateLimiter->isAllowed('admin_auth', $clientIP)) {
        logSecurityEvent('RATE_LIMIT_EXCEEDED', ['endpoint' => 'admin_auth']);
        sendResponse(false, 'Too many authentication attempts. Please try again later.', [], 429);
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

    // Determine the action
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'request_otp':
            handleOTPRequest($data, $config, $security, $emailService);
            break;
            
        case 'verify_otp':
            handleOTPVerification($data, $config, $security);
            break;
            
        case 'logout':
            handleLogout($data, $security);
            break;
            
        default:
            sendResponse(false, 'Invalid action specified.', [], 400);
    }

} catch (Exception $e) {
    // Log the error
    error_log("Admin auth error: " . $e->getMessage());
    
    // Send generic error response
    sendResponse(false, 'An unexpected error occurred. Please try again later.', [], 500);
}

function handleOTPRequest(array $data, Config $config, SecurityManager $security, EmailService $emailService): void {
    // Validate admin email
    $adminEmail = $config->get('admin.email');
    $requestedEmail = $data['email'] ?? '';
    
    if (empty($requestedEmail)) {
        sendResponse(false, 'Email is required.', [], 400);
    }
    
    $requestedEmail = $security->sanitizeInput($requestedEmail);
    
    if (!$security->validateEmail($requestedEmail)) {
        logSecurityEvent('INVALID_ADMIN_EMAIL_FORMAT', ['email' => $requestedEmail]);
        sendResponse(false, 'Invalid email format.', [], 400);
    }
    
    if ($requestedEmail !== $adminEmail) {
        logSecurityEvent('UNAUTHORIZED_ADMIN_ACCESS_ATTEMPT', ['email' => $requestedEmail]);
        // Don't reveal that this email is not authorized
        sendResponse(true, 'If this email is registered as an admin, an OTP has been sent.');
    }
    
    // Generate and store OTP
    $otp = $security->generateOTP();
    
    if (!$security->storeOTP($adminEmail, $otp)) {
        error_log("Failed to store OTP for admin login");
        sendResponse(false, 'Failed to generate OTP. Please try again.', [], 500);
    }
    
    // Send OTP via email
    if (!$emailService->sendOTP($adminEmail, $otp)) {
        error_log("Failed to send OTP email to admin");
        sendResponse(false, 'Failed to send OTP. Please try again.', [], 500);
    }
    
    logSecurityEvent('OTP_REQUESTED', ['email' => $adminEmail]);
    
    sendResponse(true, 'OTP has been sent to your email address.', [
        'expires_in_minutes' => $config->get('security.otp_expiry')
    ]);
}

function handleOTPVerification(array $data, Config $config, SecurityManager $security): void {
    $adminEmail = $config->get('admin.email');
    $providedOTP = $data['otp'] ?? '';
    
    if (empty($providedOTP)) {
        sendResponse(false, 'OTP is required.', [], 400);
    }
    
    $providedOTP = $security->sanitizeInput($providedOTP);
    
    // Validate OTP format (6 digits)
    if (!preg_match('/^\d{6}$/', $providedOTP)) {
        logSecurityEvent('INVALID_OTP_FORMAT', ['otp_length' => strlen($providedOTP)]);
        sendResponse(false, 'Invalid OTP format.', [], 400);
    }
    
    // Check if OTP is valid
    if (!$security->isValidOTP($adminEmail, $providedOTP)) {
        // Increment attempt counter
        $security->incrementOTPAttempt($adminEmail, $providedOTP);
        logSecurityEvent('INVALID_OTP_ATTEMPT', ['otp' => $providedOTP]);
        sendResponse(false, 'Invalid or expired OTP.', [], 401);
    }
    
    // Consume the OTP (mark as used)
    if (!$security->consumeOTP($adminEmail, $providedOTP)) {
        logSecurityEvent('OTP_CONSUMPTION_FAILED', ['otp' => $providedOTP]);
        sendResponse(false, 'OTP verification failed. Please try again.', [], 500);
    }
    
    // Create admin session
    $sessionToken = $security->createSession($adminEmail);
    
    logSecurityEvent('ADMIN_LOGIN_SUCCESS', ['email' => $adminEmail]);
    
    sendResponse(true, 'Login successful.', [
        'session_token' => $sessionToken,
        'expires_in_minutes' => $config->get('security.session_timeout'),
        'admin_email' => $adminEmail
    ]);
}

function handleLogout(array $data, SecurityManager $security): void {
    $sessionToken = $data['session_token'] ?? '';
    
    if (empty($sessionToken)) {
        sendResponse(false, 'Session token is required.', [], 400);
    }
    
    $sessionToken = $security->sanitizeInput($sessionToken);
    
    // Validate session exists
    $session = $security->validateSession($sessionToken);
    if (!$session) {
        sendResponse(false, 'Invalid session token.', [], 401);
    }
    
    // Destroy session
    if ($security->destroySession($sessionToken)) {
        logSecurityEvent('ADMIN_LOGOUT', ['email' => $session['email']]);
        sendResponse(true, 'Logged out successfully.');
    } else {
        sendResponse(false, 'Failed to logout. Please try again.', [], 500);
    }
}
