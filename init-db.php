<?php

require_once 'config.php';
require_once 'database.php';

/**
 * Database initialization script
 * Run this script once to set up the database tables
 */

echo "Portfolio Backend Database Initialization\n";
echo "========================================\n\n";

try {
    $config = Config::getInstance();
    $db = Database::getInstance();
    
    echo "✓ Configuration loaded\n";
    echo "✓ Database connection established\n";
    
    // Create all necessary tables
    echo "\nCreating database tables...\n";
    $db->createTables();
    echo "✓ All tables created successfully\n";
    
    // Verify tables exist
    echo "\nVerifying table structure...\n";
    
    $tables = ['contacts', 'admin_sessions', 'otp_tokens', 'rate_limits'];
    
    foreach ($tables as $table) {
        $result = $db->query("SHOW TABLES LIKE '{$table}'")->fetch();
        if ($result) {
            echo "✓ Table '{$table}' exists\n";
            
            // Show table structure
            $columns = $db->query("DESCRIBE {$table}")->fetchAll();
            echo "  Columns: " . implode(', ', array_column($columns, 'Field')) . "\n";
        } else {
            echo "✗ Table '{$table}' not found\n";
        }
    }
    
    // Display configuration summary
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "CONFIGURATION SUMMARY\n";
    echo str_repeat("=", 50) . "\n";
    
    echo "Database: " . $config->get('db.name') . "\n";
    echo "Admin Email: " . $config->get('admin.email') . "\n";
    echo "SMTP Host: " . $config->get('smtp.host') . "\n";
    echo "OTP Expiry: " . $config->get('security.otp_expiry') . " minutes\n";
    echo "Session Timeout: " . $config->get('security.session_timeout') . " minutes\n";
    echo "Rate Limit: " . $config->get('rate_limit.requests') . " requests per " . $config->get('rate_limit.window') . " minutes\n";
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "NEXT STEPS\n";
    echo str_repeat("=", 50) . "\n";
    
    echo "1. Update your .env file with correct database and SMTP credentials\n";
    echo "2. Make sure your web server can access these PHP files\n";
    echo "3. Test your contact form: POST to contact.php\n";
    echo "4. Test admin login: POST to admin-auth.php\n";
    echo "5. Set up SSL certificate for production use\n";
    echo "6. Configure proper file permissions (644 for PHP files, 600 for .env)\n";
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "API ENDPOINTS\n";
    echo str_repeat("=", 50) . "\n";
    
    echo "Contact Form:\n";
    echo "  POST /contact.php\n";
    echo "  Body: {\"name\":\"...\", \"email\":\"...\", \"subject\":\"...\", \"message\":\"...\"}\n\n";
    
    echo "Admin Authentication:\n";
    echo "  POST /admin-auth.php\n";
    echo "  Request OTP: {\"action\":\"request_otp\", \"email\":\"...\"}\n";
    echo "  Verify OTP: {\"action\":\"verify_otp\", \"otp\":\"123456\"}\n";
    echo "  Logout: {\"action\":\"logout\", \"session_token\":\"...\"}\n\n";
    
    echo "Admin Dashboard:\n";
    echo "  GET /admin-dashboard.php?action=contacts\n";
    echo "  GET /admin-dashboard.php?action=stats\n";
    echo "  Headers: Authorization: Bearer <session_token>\n";
    
    echo "\n✅ Database initialization completed successfully!\n";
    
} catch (Exception $e) {
    echo "\n❌ Error during initialization: " . $e->getMessage() . "\n";
    echo "\nPlease check your .env configuration and database credentials.\n";
    exit(1);
}
