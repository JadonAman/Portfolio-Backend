<?php

require_once 'config.php';
require_once 'database.php';
require_once 'security.php';

/**
 * Maintenance and cleanup script
 * Run this script periodically (via cron job) to clean up expired data
 */

echo "Portfolio Backend Maintenance Script\n";
echo "====================================\n\n";

try {
    $config = Config::getInstance();
    $db = Database::getInstance();
    $security = new SecurityManager();
    
    echo "Starting cleanup operations...\n\n";
    
    // 1. Clean up expired OTP tokens
    echo "1. Cleaning up expired OTP tokens...\n";
    $expiredOTPs = $db->query("SELECT COUNT(*) as count FROM otp_tokens WHERE expires_at < NOW()")->fetch();
    echo "   Found {$expiredOTPs['count']} expired OTP tokens\n";
    
    $security->cleanupExpiredOTPs();
    echo "   ✓ Expired OTP tokens cleaned up\n\n";
    
    // 2. Clean up expired admin sessions
    echo "2. Cleaning up expired admin sessions...\n";
    $expiredSessions = $db->query("SELECT COUNT(*) as count FROM admin_sessions WHERE expires_at < NOW()")->fetch();
    echo "   Found {$expiredSessions['count']} expired sessions\n";
    
    $security->cleanupExpiredSessions();
    echo "   ✓ Expired sessions cleaned up\n\n";
    
    // 3. Clean up old rate limiting records (older than 24 hours)
    echo "3. Cleaning up old rate limiting records...\n";
    $oldRateLimit = $db->query("SELECT COUNT(*) as count FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch();
    echo "   Found {$oldRateLimit['count']} old rate limit records\n";
    
    $db->query("DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    echo "   ✓ Old rate limit records cleaned up\n\n";
    
    // 4. Archive old contacts (optional - archive contacts older than 1 year)
    echo "4. Checking for contacts to archive...\n";
    $oldContacts = $db->query("
        SELECT COUNT(*) as count 
        FROM contacts 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR) 
        AND status NOT IN ('archived')
    ")->fetch();
    echo "   Found {$oldContacts['count']} contacts older than 1 year\n";
    
    if ($oldContacts['count'] > 0) {
        $archived = $db->query("
            UPDATE contacts 
            SET status = 'archived' 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR) 
            AND status NOT IN ('archived')
        ");
        echo "   ✓ {$archived->rowCount()} old contacts archived\n";
    }
    echo "\n";
    
    // 5. Database optimization
    echo "5. Clean up old email logs (older than 30 days for successful, 90 days for failed)...\n";
    $oldEmailLogs = $db->query("
        SELECT COUNT(*) as count 
        FROM email_logs 
        WHERE (created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'sent') 
        OR (created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND status = 'failed')
    ")->fetch();
    echo "   Found {$oldEmailLogs['count']} old email logs\n";
    
    $deletedEmailLogs = $db->query("
        DELETE FROM email_logs 
        WHERE (created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'sent') 
        OR (created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND status = 'failed')
    ");
    echo "   ✓ {$deletedEmailLogs->rowCount()} old email logs cleaned up\n\n";
    
    // 6. Clean up old system logs (older than 90 days)
    echo "6. Cleaning up old system logs...\n";
    $oldSystemLogs = $db->query("SELECT COUNT(*) as count FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")->fetch();
    echo "   Found {$oldSystemLogs['count']} old system logs\n";
    
    $deletedSystemLogs = $db->query("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    echo "   ✓ {$deletedSystemLogs->rowCount()} old system logs cleaned up\n\n";
    
    // 7. Database optimization
    echo "7. Optimizing database tables...\n";
    $tables = ['contacts', 'admin_sessions', 'otp_tokens', 'rate_limits', 'email_logs', 'system_logs'];
    
    foreach ($tables as $table) {
        try {
            $db->query("OPTIMIZE TABLE {$table}");
            echo "   ✓ Table '{$table}' optimized\n";
        } catch (Exception $e) {
            echo "   ✗ Failed to optimize table '{$table}': " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
    
    // 8. Generate statistics
    echo "8. Generating maintenance statistics...\n";
    
    // Count records in each table
    $stats = [];
    foreach ($tables as $table) {
        $count = $db->query("SELECT COUNT(*) as count FROM {$table}")->fetch();
        $stats[$table] = $count['count'];
        echo "   {$table}: {$count['count']} records\n";
    }
    
    // Contact statistics
    $contactStats = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM contacts 
        GROUP BY status
    ")->fetchAll();
    
    echo "\n   Contact status breakdown:\n";
    foreach ($contactStats as $stat) {
        echo "   - {$stat['status']}: {$stat['count']}\n";
    }
    
    // Recent activity
    $recentContacts = $db->query("
        SELECT COUNT(*) as count 
        FROM contacts 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch();
    echo "   - Last 7 days: {$recentContacts['count']} new contacts\n";
    
    // Email statistics
    $emailStats = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM email_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY status
    ")->fetchAll();
    
    echo "\n   Email delivery (last 7 days):\n";
    foreach ($emailStats as $stat) {
        echo "   - {$stat['status']}: {$stat['count']}\n";
    }
    
    echo "\n" . str_repeat("=", 40) . "\n";
    echo "MAINTENANCE SUMMARY\n";
    echo str_repeat("=", 40) . "\n";
    echo "Expired OTPs cleaned: {$expiredOTPs['count']}\n";
    echo "Expired sessions cleaned: {$expiredSessions['count']}\n";
    echo "Old rate limits cleaned: {$oldRateLimit['count']}\n";
    echo "Contacts archived: " . ($oldContacts['count'] > 0 ? $archived->rowCount() : 0) . "\n";
    echo "Current database records:\n";
    foreach ($stats as $table => $count) {
        echo "  - {$table}: {$count}\n";
    }
    
    echo "\n✅ Maintenance completed successfully!\n";
    echo "Next recommended run: " . date('Y-m-d H:i:s', time() + 86400) . " (24 hours)\n";
    
    // Log maintenance completion
    error_log("MAINTENANCE_COMPLETED: " . json_encode([
        'timestamp' => date('c'),
        'expired_otps_cleaned' => $expiredOTPs['count'],
        'expired_sessions_cleaned' => $expiredSessions['count'],
        'rate_limits_cleaned' => $oldRateLimit['count'],
        'contacts_archived' => $oldContacts['count'] > 0 ? $archived->rowCount() : 0,
        'current_stats' => $stats
    ]));
    
} catch (Exception $e) {
    echo "\n❌ Error during maintenance: " . $e->getMessage() . "\n";
    error_log("MAINTENANCE_ERROR: " . $e->getMessage());
    exit(1);
}
