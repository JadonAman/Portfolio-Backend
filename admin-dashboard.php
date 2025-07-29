<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'database.php';
require_once 'security.php';

// Initialize classes
$config = Config::getInstance();
$db = Database::getInstance();
$security = new SecurityManager();
$rateLimiter = new RateLimiter();

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
 * Get session token from headers
 */
function getSessionToken(): ?string {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (empty($authHeader)) {
        return null;
    }
    
    // Support both "Bearer token" and "token" formats
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }
    
    return $authHeader;
}

/**
 * Authenticate admin session
 */
function authenticateAdmin(SecurityManager $security): array {
    $sessionToken = getSessionToken();
    
    if (!$sessionToken) {
        sendResponse(false, 'Authentication required. Please provide session token.', [], 401);
    }
    
    $session = $security->validateSession($sessionToken);
    
    if (!$session) {
        sendResponse(false, 'Invalid or expired session. Please login again.', [], 401);
    }
    
    return $session;
}

/**
 * Log admin actions
 */
function logAdminAction(string $action, array $data = []): void {
    $logData = [
        'timestamp' => date('c'),
        'action' => $action,
        'ip' => (new SecurityManager())->getClientIP(),
        'user_agent' => (new SecurityManager())->getUserAgent(),
        'data' => $data
    ];
    error_log("ADMIN_ACTION: " . json_encode($logData));
}

try {
    // Create database tables if they don't exist
    $db->createTables();
    
    // Check rate limiting
    $clientIP = $security->getClientIP();
    if (!$rateLimiter->isAllowed('admin_dashboard', $clientIP)) {
        sendResponse(false, 'Too many requests. Please try again later.', [], 429);
    }

    // Authenticate admin
    $session = authenticateAdmin($security);
    
    // Parse request
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // Get action from query parameter or path
    $action = $_GET['action'] ?? $pathParts[array_search('admin-dashboard.php', $pathParts) + 1] ?? '';
    
    switch ($method) {
        case 'GET':
            handleGetRequest($action, $db, $security);
            break;
            
        case 'POST':
        case 'PUT':
            handlePostPutRequest($action, $method, $db, $security);
            break;
            
        case 'DELETE':
            handleDeleteRequest($action, $db, $security);
            break;
            
        default:
            sendResponse(false, 'Method not allowed.', [], 405);
    }

} catch (Exception $e) {
    // Log the error
    error_log("Admin dashboard error: " . $e->getMessage());
    
    // Send generic error response
    sendResponse(false, 'An unexpected error occurred. Please try again later.', [], 500);
}

function handleGetRequest(string $action, Database $db, SecurityManager $security): void {
    switch ($action) {
        case 'contacts':
        case '':
            getContacts($db);
            break;
            
        case 'contact':
            getContact($db);
            break;
            
        case 'stats':
            getStats($db);
            break;
            
        case 'session':
            getSessionInfo($security);
            break;
            
        default:
            sendResponse(false, 'Invalid action.', [], 400);
    }
}

function handlePostPutRequest(string $action, string $method, Database $db, SecurityManager $security): void {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON data.', [], 400);
    }
    
    switch ($action) {
        case 'update-status':
            updateContactStatus($data, $db, $security);
            break;
            
        case 'bulk-action':
            handleBulkAction($data, $db, $security);
            break;
            
        default:
            sendResponse(false, 'Invalid action.', [], 400);
    }
}

function handleDeleteRequest(string $action, Database $db, SecurityManager $security): void {
    switch ($action) {
        case 'contact':
            deleteContact($db, $security);
            break;
            
        default:
            sendResponse(false, 'Invalid action.', [], 400);
    }
}

function getContacts(Database $db): void {
    try {
        // Get pagination parameters
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        // Get filter parameters
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $sortBy = $_GET['sort_by'] ?? 'created_at';
        $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        
        if (!empty($status) && in_array($status, ['new', 'read', 'replied', 'archived'])) {
            $whereConditions[] = "status = :status";
            $params['status'] = $status;
        }
        
        if (!empty($search)) {
            $whereConditions[] = "(name LIKE :search OR email LIKE :search OR subject LIKE :search OR message LIKE :search)";
            $params['search'] = "%{$search}%";
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Allowed sort columns
        $allowedSortColumns = ['id', 'name', 'email', 'subject', 'status', 'created_at'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            $sortBy = 'created_at';
        }
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM contacts {$whereClause}";
        $totalResult = $db->query($countSql, $params)->fetch();
        $total = (int)$totalResult['total'];
        
        // Get contacts
        $contactsSql = "
            SELECT id, name, email, subject, message, source, status, ip_address, created_at, updated_at
            FROM contacts 
            {$whereClause}
            ORDER BY {$sortBy} {$sortOrder}
            LIMIT {$limit} OFFSET {$offset}
        ";
        
        $contacts = $db->query($contactsSql, $params)->fetchAll();
        
        // Format created_at and updated_at
        foreach ($contacts as &$contact) {
            $contact['created_at'] = date('c', strtotime($contact['created_at']));
            $contact['updated_at'] = date('c', strtotime($contact['updated_at']));
            $contact['message_preview'] = strlen($contact['message']) > 100 
                ? substr($contact['message'], 0, 100) . '...' 
                : $contact['message'];
        }
        
        logAdminAction('CONTACTS_VIEWED', [
            'page' => $page,
            'limit' => $limit,
            'status_filter' => $status,
            'search_query' => !empty($search) ? '[REDACTED]' : null
        ]);
        
        sendResponse(true, 'Contacts retrieved successfully.', [
            'contacts' => $contacts,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to get contacts: " . $e->getMessage());
        sendResponse(false, 'Failed to retrieve contacts.', [], 500);
    }
}

function getContact(Database $db): void {
    $contactId = (int)($_GET['id'] ?? 0);
    
    if ($contactId <= 0) {
        sendResponse(false, 'Valid contact ID is required.', [], 400);
    }
    
    try {
        $contact = $db->select('contacts', ['id' => $contactId]);
        
        if (empty($contact)) {
            sendResponse(false, 'Contact not found.', [], 404);
        }
        
        $contact = $contact[0];
        $contact['created_at'] = date('c', strtotime($contact['created_at']));
        $contact['updated_at'] = date('c', strtotime($contact['updated_at']));
        
        logAdminAction('CONTACT_VIEWED', ['contact_id' => $contactId]);
        
        sendResponse(true, 'Contact retrieved successfully.', ['contact' => $contact]);
        
    } catch (Exception $e) {
        error_log("Failed to get contact: " . $e->getMessage());
        sendResponse(false, 'Failed to retrieve contact.', [], 500);
    }
}

function getStats(Database $db): void {
    try {
        // Get various statistics
        $stats = [
            'total_contacts' => 0,
            'new_contacts' => 0,
            'read_contacts' => 0,
            'replied_contacts' => 0,
            'archived_contacts' => 0,
            'today_contacts' => 0,
            'this_week_contacts' => 0,
            'this_month_contacts' => 0,
            'emails_sent_today' => 0,
            'emails_failed_today' => 0,
            'total_emails_sent' => 0
        ];
        
        // Get status counts
        $statusCounts = $db->query("
            SELECT status, COUNT(*) as count 
            FROM contacts 
            GROUP BY status
        ")->fetchAll();
        
        foreach ($statusCounts as $statusCount) {
            $stats[$statusCount['status'] . '_contacts'] = (int)$statusCount['count'];
            $stats['total_contacts'] += (int)$statusCount['count'];
        }
        
        // Get time-based counts
        $timeCounts = $db->query("
            SELECT 
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as this_month
            FROM contacts
        ")->fetch();
        
        $stats['today_contacts'] = (int)$timeCounts['today'];
        $stats['this_week_contacts'] = (int)$timeCounts['this_week'];
        $stats['this_month_contacts'] = (int)$timeCounts['this_month'];
        
        // Get email statistics
        $emailStats = $db->query("
            SELECT 
                SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'sent' THEN 1 ELSE 0 END) as emails_sent_today,
                SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'failed' THEN 1 ELSE 0 END) as emails_failed_today,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_emails_sent
            FROM email_logs
        ")->fetch();
        
        if ($emailStats) {
            $stats['emails_sent_today'] = (int)$emailStats['emails_sent_today'];
            $stats['emails_failed_today'] = (int)$emailStats['emails_failed_today'];
            $stats['total_emails_sent'] = (int)$emailStats['total_emails_sent'];
        }
        
        // Get recent contacts (last 5)
        $recentContacts = $db->query("
            SELECT id, name, email, subject, status, created_at
            FROM contacts 
            ORDER BY created_at DESC 
            LIMIT 5
        ")->fetchAll();
        
        foreach ($recentContacts as &$contact) {
            $contact['created_at'] = date('c', strtotime($contact['created_at']));
        }
        
        // Get email delivery stats for recent contacts
        $emailDeliveryStats = $db->query("
            SELECT 
                email_type,
                status,
                COUNT(*) as count
            FROM email_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY email_type, status
        ")->fetchAll();
        
        logAdminAction('STATS_VIEWED');
        
        sendResponse(true, 'Statistics retrieved successfully.', [
            'stats' => $stats,
            'recent_contacts' => $recentContacts,
            'email_delivery_stats' => $emailDeliveryStats
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to get stats: " . $e->getMessage());
        sendResponse(false, 'Failed to retrieve statistics.', [], 500);
    }
}

function getSessionInfo(SecurityManager $security): void {
    $sessionToken = getSessionToken();
    $session = $security->validateSession($sessionToken);
    
    if (!$session) {
        sendResponse(false, 'Invalid session.', [], 401);
    }
    
    $sessionInfo = [
        'email' => $session['email'],
        'ip_address' => $session['ip_address'],
        'expires_at' => date('c', strtotime($session['expires_at'])),
        'created_at' => date('c', strtotime($session['created_at']))
    ];
    
    sendResponse(true, 'Session information retrieved successfully.', ['session' => $sessionInfo]);
}

function updateContactStatus(array $data, Database $db, SecurityManager $security): void {
    $contactId = (int)($data['contact_id'] ?? 0);
    $newStatus = $data['status'] ?? '';
    
    if ($contactId <= 0) {
        sendResponse(false, 'Valid contact ID is required.', [], 400);
    }
    
    if (!in_array($newStatus, ['new', 'read', 'replied', 'archived'])) {
        sendResponse(false, 'Invalid status. Allowed values: new, read, replied, archived.', [], 400);
    }
    
    try {
        $updated = $db->update('contacts', 
            ['status' => $newStatus], 
            ['id' => $contactId]
        );
        
        if ($updated === 0) {
            sendResponse(false, 'Contact not found or status unchanged.', [], 404);
        }
        
        logAdminAction('CONTACT_STATUS_UPDATED', [
            'contact_id' => $contactId,
            'new_status' => $newStatus
        ]);
        
        sendResponse(true, 'Contact status updated successfully.');
        
    } catch (Exception $e) {
        error_log("Failed to update contact status: " . $e->getMessage());
        sendResponse(false, 'Failed to update contact status.', [], 500);
    }
}

function handleBulkAction(array $data, Database $db, SecurityManager $security): void {
    $action = $data['action'] ?? '';
    $contactIds = $data['contact_ids'] ?? [];
    
    if (!in_array($action, ['mark_read', 'mark_replied', 'archive', 'delete'])) {
        sendResponse(false, 'Invalid bulk action.', [], 400);
    }
    
    if (empty($contactIds) || !is_array($contactIds)) {
        sendResponse(false, 'Contact IDs are required for bulk action.', [], 400);
    }
    
    // Validate contact IDs
    $contactIds = array_filter(array_map('intval', $contactIds));
    if (empty($contactIds)) {
        sendResponse(false, 'Valid contact IDs are required.', [], 400);
    }
    
    try {
        $affected = 0;
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        switch ($action) {
            case 'mark_read':
                $stmt = $db->query("UPDATE contacts SET status = 'read' WHERE id IN ({$placeholders})", $contactIds);
                $affected = $stmt->rowCount();
                break;
                
            case 'mark_replied':
                $stmt = $db->query("UPDATE contacts SET status = 'replied' WHERE id IN ({$placeholders})", $contactIds);
                $affected = $stmt->rowCount();
                break;
                
            case 'archive':
                $stmt = $db->query("UPDATE contacts SET status = 'archived' WHERE id IN ({$placeholders})", $contactIds);
                $affected = $stmt->rowCount();
                break;
                
            case 'delete':
                $stmt = $db->query("DELETE FROM contacts WHERE id IN ({$placeholders})", $contactIds);
                $affected = $stmt->rowCount();
                break;
        }
        
        logAdminAction('BULK_ACTION_PERFORMED', [
            'action' => $action,
            'contact_ids' => $contactIds,
            'affected_count' => $affected
        ]);
        
        sendResponse(true, "Bulk action '{$action}' completed successfully.", [
            'affected_count' => $affected
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to perform bulk action: " . $e->getMessage());
        sendResponse(false, 'Failed to perform bulk action.', [], 500);
    }
}

function deleteContact(Database $db, SecurityManager $security): void {
    $contactId = (int)($_GET['id'] ?? 0);
    
    if ($contactId <= 0) {
        sendResponse(false, 'Valid contact ID is required.', [], 400);
    }
    
    try {
        $deleted = $db->delete('contacts', ['id' => $contactId]);
        
        if ($deleted === 0) {
            sendResponse(false, 'Contact not found.', [], 404);
        }
        
        logAdminAction('CONTACT_DELETED', ['contact_id' => $contactId]);
        
        sendResponse(true, 'Contact deleted successfully.');
        
    } catch (Exception $e) {
        error_log("Failed to delete contact: " . $e->getMessage());
        sendResponse(false, 'Failed to delete contact.', [], 500);
    }
}
