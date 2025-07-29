<?php

require_once 'config.php';
require_once 'database.php';

class SecurityManager {
    private $config;
    private $db;

    public function __construct() {
        $this->config = Config::getInstance();
        $this->db = Database::getInstance();
    }

    public function generateOTP(): string {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function generateSecureToken(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }

    public function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    public function sanitizeInput(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    public function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function getClientIP(): string {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function getUserAgent(): string {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    public function isValidOTP(string $email, string $otp): bool {
        try {
            $result = $this->db->select('otp_tokens', [
                'email' => $email,
                'otp_code' => $otp,
                'used' => 0
            ]);

            if (empty($result)) {
                return false;
            }

            $token = $result[0];
            
            // Check if OTP has expired
            if (strtotime($token['expires_at']) < time()) {
                $this->cleanupExpiredOTPs();
                return false;
            }

            // Check attempt limits (max 3 attempts)
            if ($token['attempts'] >= 3) {
                return false;
            }

            return true;
            
        } catch (Exception $e) {
            error_log("OTP validation error: " . $e->getMessage());
            return false;
        }
    }

    public function consumeOTP(string $email, string $otp): bool {
        try {
            $this->db->getConnection()->beginTransaction();
            
            // Mark OTP as used
            $updated = $this->db->update('otp_tokens', 
                ['used' => 1], 
                ['email' => $email, 'otp_code' => $otp, 'used' => 0]
            );

            if ($updated > 0) {
                $this->db->getConnection()->commit();
                return true;
            } else {
                $this->db->getConnection()->rollback();
                return false;
            }
            
        } catch (Exception $e) {
            $this->db->getConnection()->rollback();
            error_log("OTP consumption error: " . $e->getMessage());
            return false;
        }
    }

    public function incrementOTPAttempt(string $email, string $otp): void {
        try {
            $this->db->query(
                "UPDATE otp_tokens SET attempts = attempts + 1 WHERE email = :email AND otp_code = :otp AND used = 0",
                ['email' => $email, 'otp' => $otp]
            );
        } catch (Exception $e) {
            error_log("Failed to increment OTP attempts: " . $e->getMessage());
        }
    }

    public function storeOTP(string $email, string $otp): bool {
        try {
            $expiryMinutes = $this->config->get('security.otp_expiry');
            $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));
            
            // Clean up old OTPs for this email
            $this->db->delete('otp_tokens', ['email' => $email]);
            
            $this->db->insert('otp_tokens', [
                'email' => $email,
                'otp_code' => $otp,
                'ip_address' => $this->getClientIP(),
                'user_agent' => $this->getUserAgent(),
                'expires_at' => $expiresAt
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to store OTP: " . $e->getMessage());
            return false;
        }
    }

    public function createSession(string $email): string {
        try {
            $token = $this->generateSecureToken();
            $timeoutMinutes = $this->config->get('security.session_timeout');
            $expiresAt = date('Y-m-d H:i:s', time() + ($timeoutMinutes * 60));
            
            // Clean up old sessions for this email
            $this->db->delete('admin_sessions', ['email' => $email]);
            
            $this->db->insert('admin_sessions', [
                'session_token' => $token,
                'email' => $email,
                'ip_address' => $this->getClientIP(),
                'user_agent' => $this->getUserAgent(),
                'expires_at' => $expiresAt
            ]);
            
            return $token;
            
        } catch (Exception $e) {
            error_log("Failed to create session: " . $e->getMessage());
            throw new Exception("Session creation failed");
        }
    }

    public function validateSession(string $token): ?array {
        try {
            $result = $this->db->select('admin_sessions', ['session_token' => $token]);
            
            if (empty($result)) {
                return null;
            }
            
            $session = $result[0];
            
            // Check if session has expired
            if (strtotime($session['expires_at']) < time()) {
                $this->db->delete('admin_sessions', ['session_token' => $token]);
                return null;
            }
            
            return $session;
            
        } catch (Exception $e) {
            error_log("Session validation error: " . $e->getMessage());
            return null;
        }
    }

    public function destroySession(string $token): bool {
        try {
            return $this->db->delete('admin_sessions', ['session_token' => $token]) > 0;
        } catch (Exception $e) {
            error_log("Session destruction error: " . $e->getMessage());
            return false;
        }
    }

    public function cleanupExpiredOTPs(): void {
        try {
            $this->db->query("DELETE FROM otp_tokens WHERE expires_at < NOW()");
        } catch (Exception $e) {
            error_log("Failed to cleanup expired OTPs: " . $e->getMessage());
        }
    }

    public function cleanupExpiredSessions(): void {
        try {
            $this->db->query("DELETE FROM admin_sessions WHERE expires_at < NOW()");
        } catch (Exception $e) {
            error_log("Failed to cleanup expired sessions: " . $e->getMessage());
        }
    }
}

class RateLimiter {
    private $config;
    private $db;

    public function __construct() {
        $this->config = Config::getInstance();
        $this->db = Database::getInstance();
    }

    public function isAllowed(string $endpoint, string $ip = null): bool {
        if ($ip === null) {
            $security = new SecurityManager();
            $ip = $security->getClientIP();
        }

        try {
            $maxRequests = $this->config->get('rate_limit.requests');
            $windowMinutes = $this->config->get('rate_limit.window');
            $windowStart = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

            // Clean up old rate limit records
            $this->db->query(
                "DELETE FROM rate_limits WHERE window_start < :window_start",
                ['window_start' => $windowStart]
            );

            // Check current rate limit
            $result = $this->db->select('rate_limits', [
                'ip_address' => $ip,
                'endpoint' => $endpoint
            ]);

            if (empty($result)) {
                // First request, create new record
                $this->db->insert('rate_limits', [
                    'ip_address' => $ip,
                    'endpoint' => $endpoint,
                    'requests' => 1
                ]);
                return true;
            }

            $record = $result[0];
            $windowStartTime = strtotime($record['window_start']);
            $currentTime = time();

            // Check if we're still in the same window
            if (($currentTime - $windowStartTime) < ($windowMinutes * 60)) {
                if ($record['requests'] >= $maxRequests) {
                    return false; // Rate limit exceeded
                }
                
                // Increment request count
                $this->db->update('rate_limits', 
                    ['requests' => $record['requests'] + 1],
                    ['ip_address' => $ip, 'endpoint' => $endpoint]
                );
            } else {
                // New window, reset counter
                $this->db->update('rate_limits', 
                    ['requests' => 1, 'window_start' => date('Y-m-d H:i:s')],
                    ['ip_address' => $ip, 'endpoint' => $endpoint]
                );
            }

            return true;

        } catch (Exception $e) {
            error_log("Rate limiting error: " . $e->getMessage());
            return true; // Allow request if rate limiting fails
        }
    }

    public function getRemainingRequests(string $endpoint, string $ip = null): int {
        if ($ip === null) {
            $security = new SecurityManager();
            $ip = $security->getClientIP();
        }

        try {
            $maxRequests = $this->config->get('rate_limit.requests');
            $result = $this->db->select('rate_limits', [
                'ip_address' => $ip,
                'endpoint' => $endpoint
            ]);

            if (empty($result)) {
                return $maxRequests;
            }

            return max(0, $maxRequests - $result[0]['requests']);

        } catch (Exception $e) {
            error_log("Failed to get remaining requests: " . $e->getMessage());
            return 0;
        }
    }
}
