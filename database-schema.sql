-- Portfolio Backend Database Schema
-- Database: u133932327_portfolio
-- Created for: Genesis Softwares Portfolio Backend

-- Use the database
USE u133932327_portfolio;

-- Set charset and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ====================================
-- CONTACTS TABLE
-- Stores all contact form submissions
-- ====================================
CREATE TABLE IF NOT EXISTS contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    message TEXT NOT NULL,
    source VARCHAR(100) DEFAULT 'portfolio_website',
    ip_address VARCHAR(45),
    user_agent TEXT,
    status ENUM('new', 'read', 'replied', 'archived') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for better performance
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_status_created (status, created_at),
    INDEX idx_email_status (email, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================
-- ADMIN SESSIONS TABLE
-- Stores admin login sessions
-- ====================================
CREATE TABLE IF NOT EXISTS admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for session management
    INDEX idx_token (session_token),
    INDEX idx_expires (expires_at),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================
-- OTP TOKENS TABLE
-- Stores OTP codes for admin authentication
-- ====================================
CREATE TABLE IF NOT EXISTS otp_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    attempts INT DEFAULT 0,
    used BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes for OTP validation
    INDEX idx_email_otp (email, otp_code),
    INDEX idx_expires (expires_at),
    INDEX idx_used (used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================
-- RATE LIMITS TABLE
-- Stores rate limiting data per IP
-- ====================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(100) NOT NULL,
    requests INT DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Unique constraint to prevent duplicates
    UNIQUE KEY unique_ip_endpoint (ip_address, endpoint),
    INDEX idx_window (window_start),
    INDEX idx_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================
-- EMAIL LOGS TABLE (NEW)
-- Stores email sending logs for debugging
-- ====================================
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_type ENUM('contact_notification', 'contact_confirmation', 'otp', 'custom') NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT NULL,
    contact_id INT NULL, -- Link to contacts table if applicable
    ip_address VARCHAR(45),
    user_agent TEXT,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign key constraint
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    
    -- Indexes
    INDEX idx_email_type (email_type),
    INDEX idx_status (status),
    INDEX idx_recipient (recipient_email),
    INDEX idx_created_at (created_at),
    INDEX idx_contact_id (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================
-- SYSTEM LOGS TABLE (NEW)
-- Stores system events and security logs
-- ====================================
CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_level ENUM('info', 'warning', 'error', 'security') NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    context JSON NULL, -- Store additional context as JSON
    ip_address VARCHAR(45),
    user_agent TEXT,
    user_email VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_log_level (log_level),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at),
    INDEX idx_user_email (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================
-- INSERT SAMPLE DATA (OPTIONAL)
-- ====================================

-- Insert a test contact (you can remove this)
INSERT INTO contacts (name, email, subject, message, source, ip_address) VALUES 
('Test User', 'test@example.com', 'Test Contact', 'This is a test message to verify the system is working.', 'portfolio_website', '127.0.0.1');

-- ====================================
-- USEFUL QUERIES FOR MAINTENANCE
-- ====================================

-- Clean up expired OTP tokens
-- DELETE FROM otp_tokens WHERE expires_at < NOW();

-- Clean up expired sessions
-- DELETE FROM admin_sessions WHERE expires_at < NOW();

-- Clean up old rate limits (older than 24 hours)
-- DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- Archive old contacts (older than 1 year)
-- UPDATE contacts SET status = 'archived' WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR) AND status != 'archived';

-- Get contact statistics
-- SELECT 
--     status, 
--     COUNT(*) as count,
--     DATE(created_at) as date
-- FROM contacts 
-- GROUP BY status, DATE(created_at) 
-- ORDER BY date DESC;

-- Get email sending statistics
-- SELECT 
--     email_type,
--     status,
--     COUNT(*) as count,
--     DATE(created_at) as date
-- FROM email_logs 
-- GROUP BY email_type, status, DATE(created_at)
-- ORDER BY date DESC;

-- ====================================
-- PERFORMANCE OPTIMIZATIONS
-- ====================================

-- Additional indexes for better query performance
CREATE INDEX idx_contacts_email_status ON contacts(email, status);
CREATE INDEX idx_contacts_created_status ON contacts(created_at, status);
CREATE INDEX idx_email_logs_type_status ON email_logs(email_type, status);
CREATE INDEX idx_system_logs_level_type ON system_logs(log_level, event_type);

-- ====================================
-- VIEWS FOR EASIER DATA ACCESS
-- ====================================

-- View for recent contacts with email status
CREATE OR REPLACE VIEW recent_contacts_with_emails AS
SELECT 
    c.id,
    c.name,
    c.email,
    c.subject,
    c.status as contact_status,
    c.created_at,
    (SELECT COUNT(*) FROM email_logs el WHERE el.contact_id = c.id AND el.status = 'sent') as emails_sent,
    (SELECT COUNT(*) FROM email_logs el WHERE el.contact_id = c.id AND el.status = 'failed') as emails_failed
FROM contacts c
ORDER BY c.created_at DESC;

-- View for admin dashboard statistics
CREATE OR REPLACE VIEW dashboard_stats AS
SELECT 
    (SELECT COUNT(*) FROM contacts) as total_contacts,
    (SELECT COUNT(*) FROM contacts WHERE status = 'new') as new_contacts,
    (SELECT COUNT(*) FROM contacts WHERE status = 'read') as read_contacts,
    (SELECT COUNT(*) FROM contacts WHERE status = 'replied') as replied_contacts,
    (SELECT COUNT(*) FROM contacts WHERE status = 'archived') as archived_contacts,
    (SELECT COUNT(*) FROM contacts WHERE DATE(created_at) = CURDATE()) as today_contacts,
    (SELECT COUNT(*) FROM contacts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as week_contacts,
    (SELECT COUNT(*) FROM contacts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as month_contacts,
    (SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND DATE(created_at) = CURDATE()) as emails_sent_today,
    (SELECT COUNT(*) FROM email_logs WHERE status = 'failed' AND DATE(created_at) = CURDATE()) as emails_failed_today;

-- ====================================
-- STORED PROCEDURES (OPTIONAL)
-- ====================================

DELIMITER $$

-- Procedure to clean up expired data
CREATE PROCEDURE CleanupExpiredData()
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Clean expired OTP tokens
    DELETE FROM otp_tokens WHERE expires_at < NOW();
    
    -- Clean expired sessions
    DELETE FROM admin_sessions WHERE expires_at < NOW();
    
    -- Clean old rate limits (older than 24 hours)
    DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
    -- Clean old system logs (older than 90 days)
    DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- Clean old email logs (older than 30 days, keep only failed ones for longer)
    DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND status = 'sent';
    DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND status = 'failed';
    
    COMMIT;
END$$

-- Procedure to get contact statistics
CREATE PROCEDURE GetContactStats(IN days_back INT)
BEGIN
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
        SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replied_count,
        SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_count
    FROM contacts 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL days_back DAY)
    GROUP BY DATE(created_at) 
    ORDER BY date DESC;
END$$

DELIMITER ;

-- ====================================
-- TRIGGERS FOR AUDIT LOGGING
-- ====================================

DELIMITER $$

-- Trigger to log contact status changes
CREATE TRIGGER contact_status_change 
AFTER UPDATE ON contacts
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO system_logs (log_level, event_type, message, context, created_at)
        VALUES (
            'info',
            'CONTACT_STATUS_CHANGED',
            CONCAT('Contact ID ', NEW.id, ' status changed from ', OLD.status, ' to ', NEW.status),
            JSON_OBJECT(
                'contact_id', NEW.id,
                'old_status', OLD.status,
                'new_status', NEW.status,
                'contact_email', NEW.email
            ),
            NOW()
        );
    END IF;
END$$

-- Trigger to log new contacts
CREATE TRIGGER new_contact_created
AFTER INSERT ON contacts
FOR EACH ROW
BEGIN
    INSERT INTO system_logs (log_level, event_type, message, context, ip_address, created_at)
    VALUES (
        'info',
        'NEW_CONTACT_CREATED',
        CONCAT('New contact received from ', NEW.email),
        JSON_OBJECT(
            'contact_id', NEW.id,
            'name', NEW.name,
            'email', NEW.email,
            'subject', NEW.subject,
            'source', NEW.source
        ),
        NEW.ip_address,
        NOW()
    );
END$$

DELIMITER ;

-- ====================================
-- GRANT PERMISSIONS (ADJUST AS NEEDED)
-- ====================================

-- Grant necessary permissions to the user
-- GRANT SELECT, INSERT, UPDATE, DELETE ON u133932327_portfolio.* TO 'u133932327_aman'@'localhost';
-- FLUSH PRIVILEGES;

-- Show table creation summary
SELECT 
    'Database setup completed!' as status,
    COUNT(*) as tables_created
FROM information_schema.tables 
WHERE table_schema = 'u133932327_portfolio';

-- Show all tables
SHOW TABLES;
