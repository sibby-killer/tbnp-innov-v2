<?php
/**
 * CSRF Protection - Prevents Cross-Site Request Forgery attacks
 * 
 * @package Core\Security
 * @version 1.0.0
 */

namespace Core\Security;

use Core\Security\Exceptions\SecurityException;

class CsrfProtection
{
    /**
     * Token storage methods
     */
    const STORAGE_SESSION = 'session';
    const STORAGE_DATABASE = 'database';
    
    /**
     * @var SecurityLogger Logger instance
     */
    private SecurityLogger $logger;
    
    /**
     * @var array Configuration
     */
    private array $config;
    
    /**
     * @var string Storage method
     */
    private string $storage;
    
    /**
     * @var \PDO|null Database connection
     */
    private ?\PDO $db = null;
    
    /**
     * Constructor
     * 
     * @param SecurityLogger $logger
     * @param array $config Configuration overrides
     */
    public function __construct(SecurityLogger $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'enabled' => true,
            'token_length' => 32,
            'token_timeout' => 7200, // 2 hours
            'storage' => self::STORAGE_SESSION,
            'regenerate_on_activity' => true,
            'multiple_tokens' => true, // Allow multiple valid tokens (for AJAX)
            'max_tokens_per_session' => 10
        ], $config);
        
        $this->storage = $this->config['storage'];
        
        // Initialize session storage if needed
        if ($this->storage === self::STORAGE_SESSION && !isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
    }
    
    /**
     * Set database connection for database storage
     * 
     * @param \PDO $db
     */
    public function setDatabase(\PDO $db): void
    {
        $this->db = $db;
    }
    
    /**
     * Generate a new CSRF token
     * 
     * @param string $action Optional action name to bind token to specific action
     * @return string Generated token
     */
    public function generateToken(string $action = 'default'): string
    {
        if (!$this->config['enabled']) {
            return '';
        }
        
        // Generate random token
        $token = bin2hex(random_bytes($this->config['token_length']));
        
        // Store token with metadata
        $tokenData = [
            'token' => $token,
            'action' => $action,
            'created' => time(),
            'expires' => time() + $this->config['token_timeout'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        // Store based on configured method
        $this->storeToken($tokenData);
        
        // Clean expired tokens
        $this->cleanExpiredTokens();
        
        $this->logger->logCsrf('token_generated', [
            'action' => $action,
            'storage' => $this->storage
        ]);
        
        return $token;
    }
    
    /**
     * Store token based on storage method
     * 
     * @param array $tokenData
     */
    private function storeToken(array $tokenData): void
    {
        switch ($this->storage) {
            case self::STORAGE_DATABASE:
                $this->storeTokenInDatabase($tokenData);
                break;
            case self::STORAGE_SESSION:
            default:
                $this->storeTokenInSession($tokenData);
                break;
        }
    }
    
    /**
     * Store token in session
     * 
     * @param array $tokenData
     */
    private function storeTokenInSession(array $tokenData): void
    {
        // Enforce maximum tokens per session
        if (count($_SESSION['csrf_tokens']) >= $this->config['max_tokens_per_session']) {
            // Remove oldest token
            array_shift($_SESSION['csrf_tokens']);
        }
        
        $_SESSION['csrf_tokens'][$tokenData['token']] = $tokenData;
    }
    
    /**
     * Store token in database
     * 
     * @param array $tokenData
     */
    private function storeTokenInDatabase(array $tokenData): void
    {
        if (!$this->db) {
            throw new SecurityException('Database connection required for CSRF token storage');
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO csrf_tokens (token, action, created_at, expires_at, ip_address, user_agent, session_id)
            VALUES (?, ?, NOW(), FROM_UNIXTIME(?), ?, ?, ?)
        ");
        
        $stmt->execute([
            $tokenData['token'],
            $tokenData['action'],
            $tokenData['expires'],
            $tokenData['ip'],
            $tokenData['user_agent'],
            session_id()
        ]);
    }
    
    /**
     * Validate a CSRF token
     * 
     * @param string|null $token Token to validate
     * @param string $action Action name for validation
     * @param bool $singleUse Whether to consume token after use
     * @return bool True if valid
     */
    public function validateToken(?string $token, string $action = 'default', bool $singleUse = true): bool
    {
        if (!$this->config['enabled']) {
            return true;
        }
        
        if (empty($token)) {
            $this->logger->logCsrf('validation_failed', [
                'action' => $action,
                'reason' => 'empty_token'
            ], SecurityLogger::LEVEL_WARNING);
            return false;
        }
        
        // Get token data from storage
        $tokenData = $this->getToken($token);
        
        if (!$tokenData) {
            $this->logger->logCsrf('validation_failed', [
                'action' => $action,
                'reason' => 'token_not_found',
                'token_prefix' => substr($token, 0, 8)
            ], SecurityLogger::LEVEL_WARNING);
            return false;
        }
        
        // Validate expiration
        if ($tokenData['expires'] < time()) {
            $this->logger->logCsrf('validation_failed', [
                'action' => $action,
                'reason' => 'token_expired',
                'expires' => date('Y-m-d H:i:s', $tokenData['expires'])
            ], SecurityLogger::LEVEL_WARNING);
            
            $this->removeToken($token);
            return false;
        }
        
        // Validate action
        if ($tokenData['action'] !== $action) {
            $this->logger->logCsrf('validation_failed', [
                'action' => $action,
                'token_action' => $tokenData['action'],
                'reason' => 'action_mismatch'
            ], SecurityLogger::LEVEL_WARNING);
            
            if ($singleUse) {
                $this->removeToken($token);
            }
            return false;
        }
        
        // Optional: Validate IP and User Agent for high-security forms
        if (isset($this->config['strict_ip_check']) && $this->config['strict_ip_check']) {
            if ($tokenData['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
                $this->logger->logCsrf('validation_failed', [
                    'action' => $action,
                    'reason' => 'ip_mismatch'
                ], SecurityLogger::LEVEL_WARNING);
                
                if ($singleUse) {
                    $this->removeToken($token);
                }
                return false;
            }
        }
        
        // Token is valid
        $this->logger->logCsrf('token_validated', [
            'action' => $action,
            'single_use' => $singleUse
        ]);
        
        // Remove token if single use
        if ($singleUse) {
            $this->removeToken($token);
        }
        
        return true;
    }
    
    /**
     * Get token data from storage
     * 
     * @param string $token
     * @return array|null
     */
    private function getToken(string $token): ?array
    {
        switch ($this->storage) {
            case self::STORAGE_DATABASE:
                return $this->getTokenFromDatabase($token);
            case self::STORAGE_SESSION:
            default:
                return $this->getTokenFromSession($token);
        }
    }
    
    /**
     * Get token from session
     * 
     * @param string $token
     * @return array|null
     */
    private function getTokenFromSession(string $token): ?array
    {
        return $_SESSION['csrf_tokens'][$token] ?? null;
    }
    
    /**
     * Get token from database
     * 
     * @param string $token
     * @return array|null
     */
    private function getTokenFromDatabase(string $token): ?array
    {
        if (!$this->db) {
            return null;
        }
        
        $stmt = $this->db->prepare("
            SELECT token, action, UNIX_TIMESTAMP(expires_at) as expires, ip_address as ip, user_agent
            FROM csrf_tokens
            WHERE token = ? AND session_id = ?
        ");
        $stmt->execute([$token, session_id()]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Remove token from storage
     * 
     * @param string $token
     */
    private function removeToken(string $token): void
    {
        switch ($this->storage) {
            case self::STORAGE_DATABASE:
                $this->removeTokenFromDatabase($token);
                break;
            case self::STORAGE_SESSION:
            default:
                $this->removeTokenFromSession($token);
                break;
        }
    }
    
    /**
     * Remove token from session
     * 
     * @param string $token
     */
    private function removeTokenFromSession(string $token): void
    {
        unset($_SESSION['csrf_tokens'][$token]);
    }
    
    /**
     * Remove token from database
     * 
     * @param string $token
     */
    private function removeTokenFromDatabase(string $token): void
    {
        if (!$this->db) {
            return;
        }
        
        $stmt = $this->db->prepare("DELETE FROM csrf_tokens WHERE token = ?");
        $stmt->execute([$token]);
    }
    
    /**
     * Clean expired tokens
     */
    private function cleanExpiredTokens(): void
    {
        switch ($this->storage) {
            case self::STORAGE_DATABASE:
                $this->cleanExpiredTokensFromDatabase();
                break;
            case self::STORAGE_SESSION:
            default:
                $this->cleanExpiredTokensFromSession();
                break;
        }
    }
    
    /**
     * Clean expired tokens from session
     */
    private function cleanExpiredTokensFromSession(): void
    {
        $now = time();
        foreach ($_SESSION['csrf_tokens'] as $token => $data) {
            if ($data['expires'] < $now) {
                unset($_SESSION['csrf_tokens'][$token]);
                $this->logger->logCsrf('token_expired_cleaned', [
                    'action' => $data['action']
                ]);
            }
        }
    }
    
    /**
     * Clean expired tokens from database
     */
    private function cleanExpiredTokensFromDatabase(): void
    {
        if (!$this->db) {
            return;
        }
        
        $stmt = $this->db->prepare("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
        $stmt->execute();
        
        $count = $stmt->rowCount();
        if ($count > 0) {
            $this->logger->logCsrf('expired_tokens_cleaned', [
                'count' => $count
            ]);
        }
    }
    
    /**
     * Generate HTML hidden input field with CSRF token
     * 
     * @param string $action Action name
     * @return string HTML input field
     */
    public function getTokenField(string $action = 'default'): string
    {
        if (!$this->config['enabled']) {
            return '';
        }
        
        $token = $this->generateToken($action);
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Get token for AJAX requests (returns JSON-friendly data)
     * 
     * @param string $action Action name
     * @return array Token data
     */
    public function getTokenForAjax(string $action = 'default'): array
    {
        $token = $this->generateToken($action);
        
        return [
            'token' => $token,
            'header' => 'X-CSRF-Token',
            'expires_in' => $this->config['token_timeout']
        ];
    }
    
    /**
     * Validate request based on method
     * - For POST/PUT/DELETE: checks CSRF token
     * - For GET/OPTIONS: no check (idempotent)
     * 
     * @param string|null $token Token from request
     * @param string $action Action name
     * @return bool
     */
    public function validateRequest(?string $token, string $action = 'default'): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Only validate state-changing methods
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return true;
        }
        
        return $this->validateToken($token, $action);
    }
    
    /**
     * Validate AJAX request using header
     * 
     * @param string $action Action name
     * @return bool
     */
    public function validateAjaxRequest(string $action = 'default'): bool
    {
        $headers = $this->getRequestHeaders();
        $token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? null;
        
        return $this->validateToken($token, $action, true);
    }
    
    /**
     * Get all request headers (works in all environments)
     * 
     * @return array
     */
    private function getRequestHeaders(): array
    {
        $headers = [];
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$name] = $value;
                }
            }
        }
        
        return $headers;
    }
    
    /**
     * Require valid CSRF token or throw exception
     * 
     * @param string $action Action name
     * @throws SecurityException
     */
    public function requireValidToken(string $action = 'default'): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!$this->validateRequest($token, $action)) {
            throw SecurityException::invalidCsrfToken([
                'action' => $action,
                'method' => $_SERVER['REQUEST_METHOD']
            ]);
        }
    }
    
    /**
     * Regenerate token for sensitive operations
     * 
     * @param string $action Action name
     * @return string New token
     */
    public function regenerateToken(string $action = 'default'): string
    {
        // Remove existing tokens for this action
        $this->removeTokensByAction($action);
        
        // Generate new token
        return $this->generateToken($action);
    }
    
    /**
     * Remove all tokens for a specific action
     * 
     * @param string $action
     */
    private function removeTokensByAction(string $action): void
    {
        switch ($this->storage) {
            case self::STORAGE_DATABASE:
                if ($this->db) {
                    $stmt = $this->db->prepare("DELETE FROM csrf_tokens WHERE action = ? AND session_id = ?");
                    $stmt->execute([$action, session_id()]);
                }
                break;
                
            case self::STORAGE_SESSION:
            default:
                foreach ($_SESSION['csrf_tokens'] as $token => $data) {
                    if ($data['action'] === $action) {
                        unset($_SESSION['csrf_tokens'][$token]);
                    }
                }
                break;
        }
    }
    
    /**
     * Get token statistics
     * 
     * @return array
     */
    public function getStats(): array
    {
        $stats = [
            'storage' => $this->storage,
            'enabled' => $this->config['enabled'],
            'token_timeout' => $this->config['token_timeout']
        ];
        
        switch ($this->storage) {
            case self::STORAGE_SESSION:
                $stats['total_tokens'] = count($_SESSION['csrf_tokens']);
                $stats['expired_tokens'] = $this->countExpiredTokensInSession();
                break;
                
            case self::STORAGE_DATABASE:
                if ($this->db) {
                    $stmt = $this->db->query("
                        SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired
                        FROM csrf_tokens 
                        WHERE session_id = '" . session_id() . "'
                    ");
                    $result = $stmt->fetch();
                    $stats['total_tokens'] = $result['total'];
                    $stats['expired_tokens'] = $result['expired'];
                }
                break;
        }
        
        return $stats;
    }
    
    /**
     * Count expired tokens in session
     * 
     * @return int
     */
    private function countExpiredTokensInSession(): int
    {
        $now = time();
        $count = 0;
        
        foreach ($_SESSION['csrf_tokens'] as $data) {
            if ($data['expires'] < $now) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Clear all tokens for current session
     */
    public function clearAllTokens(): void
    {
        switch ($this->storage) {
            case self::STORAGE_DATABASE:
                if ($this->db) {
                    $stmt = $this->db->prepare("DELETE FROM csrf_tokens WHERE session_id = ?");
                    $stmt->execute([session_id()]);
                }
                break;
                
            case self::STORAGE_SESSION:
            default:
                $_SESSION['csrf_tokens'] = [];
                break;
        }
        
        $this->logger->logCsrf('all_tokens_cleared');
    }
}