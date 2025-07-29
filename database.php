<?php

require_once 'config.php';

class Database {
    private static $instance = null;
    private $connection;
    private $config;

    private function __construct() {
        $this->config = Config::getInstance();
        $this->connect();
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    private function connect(): void {
        try {
            $host = $this->config->get('db.host');
            $dbname = $this->config->get('db.name');
            $username = $this->config->get('db.user');
            $password = $this->config->get('db.pass');
            $charset = $this->config->get('db.charset');

            $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}"
            ];

            $this->connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query execution failed: " . $e->getMessage());
            throw new Exception("Database query failed");
        }
    }

    public function insert(string $table, array $data): int {
        $columns = implode(',', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        
        return (int)$this->connection->lastInsertId();
    }

    public function select(string $table, array $conditions = [], string $columns = '*'): array {
        $sql = "SELECT {$columns} FROM {$table}";
        
        if (!empty($conditions)) {
            $whereClause = implode(' AND ', array_map(fn($key) => "{$key} = :{$key}", array_keys($conditions)));
            $sql .= " WHERE {$whereClause}";
        }
        
        $stmt = $this->query($sql, $conditions);
        return $stmt->fetchAll();
    }

    public function update(string $table, array $data, array $conditions): int {
        $setClause = implode(', ', array_map(fn($key) => "{$key} = :set_{$key}", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn($key) => "{$key} = :where_{$key}", array_keys($conditions)));
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$whereClause}";
        
        $params = [];
        foreach ($data as $key => $value) {
            $params["set_{$key}"] = $value;
        }
        foreach ($conditions as $key => $value) {
            $params["where_{$key}"] = $value;
        }
        
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function delete(string $table, array $conditions): int {
        $whereClause = implode(' AND ', array_map(fn($key) => "{$key} = :{$key}", array_keys($conditions)));
        $sql = "DELETE FROM {$table} WHERE {$whereClause}";
        
        $stmt = $this->query($sql, $conditions);
        return $stmt->rowCount();
    }

    public function createTables(): void {
        $tables = [
            'contacts' => "
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
                    INDEX idx_email (email),
                    INDEX idx_status (status),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'admin_sessions' => "
                CREATE TABLE IF NOT EXISTS admin_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    session_token VARCHAR(255) UNIQUE NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (session_token),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'otp_tokens' => "
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
                    INDEX idx_email_otp (email, otp_code),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'rate_limits' => "
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    endpoint VARCHAR(100) NOT NULL,
                    requests INT DEFAULT 1,
                    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_ip_endpoint (ip_address, endpoint),
                    INDEX idx_window (window_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'email_logs' => "
                CREATE TABLE IF NOT EXISTS email_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email_type ENUM('contact_notification', 'contact_confirmation', 'otp', 'custom') NOT NULL,
                    recipient_email VARCHAR(255) NOT NULL,
                    sender_email VARCHAR(255) NOT NULL,
                    subject VARCHAR(500) NOT NULL,
                    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
                    error_message TEXT NULL,
                    contact_id INT NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    sent_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_email_type (email_type),
                    INDEX idx_status (status),
                    INDEX idx_recipient (recipient_email),
                    INDEX idx_created_at (created_at),
                    INDEX idx_contact_id (contact_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'system_logs' => "
                CREATE TABLE IF NOT EXISTS system_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    log_level ENUM('info', 'warning', 'error', 'security') NOT NULL,
                    event_type VARCHAR(100) NOT NULL,
                    message TEXT NOT NULL,
                    context JSON NULL,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    user_email VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_log_level (log_level),
                    INDEX idx_event_type (event_type),
                    INDEX idx_created_at (created_at),
                    INDEX idx_user_email (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            "
        ];

        foreach ($tables as $name => $sql) {
            try {
                $this->connection->exec($sql);
                error_log("Table '{$name}' created successfully");
            } catch (PDOException $e) {
                error_log("Failed to create table '{$name}': " . $e->getMessage());
                throw new Exception("Failed to create database tables");
            }
        }
        
        // Create foreign key constraints after all tables are created
        try {
            $this->connection->exec("
                ALTER TABLE email_logs 
                ADD CONSTRAINT fk_email_logs_contact_id 
                FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
            ");
        } catch (PDOException $e) {
            // Foreign key might already exist, ignore error
            error_log("Foreign key constraint info: " . $e->getMessage());
        }
    }
}
