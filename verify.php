<?php
/**
 * Production Verification Script
 * Quick check to ensure all core components are working
 */

header('Content-Type: application/json');

$checks = [];
$allPassed = true;

// Check 1: PHP Version
$checks['php_version'] = [
    'status' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'message' => 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.0.0', '>=') ? ' ✅' : ' ❌ (Requires 8.0+)')
];

// Check 2: Required Files
$requiredFiles = [
    'config.php', 'database.php', 'security.php', 'email-service.php',
    'contact.php', 'admin-auth.php', 'admin-dashboard.php', 'admin.html',
    '.env', 'database-schema.sql'
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        $missingFiles[] = $file;
        $allPassed = false;
    }
}

$checks['required_files'] = [
    'status' => empty($missingFiles),
    'message' => empty($missingFiles) ? 'All required files present ✅' : 'Missing files: ' . implode(', ', $missingFiles) . ' ❌'
];

// Check 3: Composer Dependencies
$checks['composer'] = [
    'status' => file_exists('vendor/autoload.php'),
    'message' => file_exists('vendor/autoload.php') ? 'Composer dependencies installed ✅' : 'Run composer install ❌'
];

// Check 4: Environment Configuration
try {
    require_once 'config.php';
    $checks['config'] = [
        'status' => defined('DB_HOST') && defined('SMTP_HOST'),
        'message' => (defined('DB_HOST') && defined('SMTP_HOST')) ? 'Configuration loaded ✅' : 'Configuration incomplete ❌'
    ];
} catch (Exception $e) {
    $checks['config'] = [
        'status' => false,
        'message' => 'Configuration error: ' . $e->getMessage() . ' ❌'
    ];
    $allPassed = false;
}

// Check 5: Database Connection (if config is loaded)
if ($checks['config']['status']) {
    try {
        require_once 'database.php';
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $checks['database'] = [
            'status' => true,
            'message' => 'Database connection successful ✅'
        ];
    } catch (Exception $e) {
        $checks['database'] = [
            'status' => false,
            'message' => 'Database connection failed: ' . $e->getMessage() . ' ❌'
        ];
        $allPassed = false;
    }
}

// Check 6: File Permissions
$checks['permissions'] = [
    'status' => is_writable('.') && is_readable('.env'),
    'message' => (is_writable('.') && is_readable('.env')) ? 'File permissions OK ✅' : 'Check file permissions ❌'
];

$result = [
    'success' => $allPassed,
    'message' => $allPassed ? 'All systems operational! 🚀' : 'Some issues detected. Please review. ⚠️',
    'checks' => $checks,
    'timestamp' => date('Y-m-d H:i:s T'),
    'status' => $allPassed ? 'READY' : 'NEEDS_ATTENTION'
];

if ($allPassed) {
    $result['next_steps'] = [
        '1. Upload files to production server',
        '2. Run: php init-db.php (to create database tables)',
        '3. Test contact form from your frontend',
        '4. Access admin panel at admin.html',
        '5. Monitor system logs'
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>
