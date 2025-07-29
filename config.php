<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

class Config {
    private static $instance = null;
    private $config = [];

    private function __construct() {
        // Load environment variables
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        
        $this->config = [
            'db' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'name' => $_ENV['DB_NAME'] ?? 'portfolio_db',
                'user' => $_ENV['DB_USER'] ?? '',
                'pass' => $_ENV['DB_PASS'] ?? '',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4'
            ],
            'smtp' => [
                'host' => $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com',
                'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
                'username' => $_ENV['SMTP_USERNAME'] ?? '',
                'password' => $_ENV['SMTP_PASSWORD'] ?? '',
                'secure' => $_ENV['SMTP_SECURE'] ?? 'tls',
                'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
                'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'Portfolio Contact'
            ],
            'admin' => [
                'email' => $_ENV['ADMIN_EMAIL'] ?? 'iasamanjadon@gmail.com',
                'name' => $_ENV['ADMIN_NAME'] ?? 'Admin'
            ],
            'security' => [
                'jwt_secret' => $_ENV['JWT_SECRET'] ?? 'change_this_secret',
                'otp_expiry' => (int)($_ENV['OTP_EXPIRY_MINUTES'] ?? 10),
                'session_timeout' => (int)($_ENV['SESSION_TIMEOUT_MINUTES'] ?? 60)
            ],
            'rate_limit' => [
                'requests' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 5),
                'window' => (int)($_ENV['RATE_LIMIT_WINDOW_MINUTES'] ?? 15)
            ],
            'app' => [
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
                'cors_origin' => $_ENV['CORS_ORIGIN'] ?? '*'
            ]
        ];
        
        // Set timezone
        date_default_timezone_set($this->config['app']['timezone']);
    }

    public static function getInstance(): Config {
        if (self::$instance === null) {
            self::$instance = new Config();
        }
        return self::$instance;
    }

    public function get(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }

    public function getAll(): array {
        return $this->config;
    }
}
