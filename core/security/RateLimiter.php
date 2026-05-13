<?php
/**
 * Rate Limiter - Prevents brute force attacks and abuse
 * Uses your LOGIN_ATTEMPTS_LIMIT and LOCKOUT_TIME constants
 * 
 * @package Core\Security
 * @version 1.0.0
 */

namespace Core\Security;

use Core\Security\Exceptions\SecurityException;

class RateLimiter
{
    /**
     * Storage types
     */
    const STORAGE_SESSION = 'session';
    const STORAGE_DATABASE = 'database';
    const STORAGE_CACHE = 'cache';
    
    /**
     * @var SecurityLogger Logger instance
     */
    private SecurityLogger $logger;
    
    /**
     * @var array Rate limit rules
     */
    private array $rules = [];
    
    /**
     * @var string Storage type
     */
    private string $storageType;
    
    /**
     * @var \PDO|null Database connection
     */
    private ?\PDO $db = null;
    
    /**
     * Constructor
     * 
     * @param SecurityLogger $logger
     * @param string $storageType Storage mechanism
     */
    public function __construct(SecurityLogger $logger, string $storageType = self::STORAGE_SESSION)
    {
        $this->logger = $logger;
        $this->storageType = $storageType;
        
        // Initialize default rules using your constants
        $this->initDefaultRules();
    }
    
    /**
     * Initialize default rate limit rules
     */
    private function initDefaultRules(): void
    {
        // Login attempts rule (using your constants)
        $this->addRule('login_attempts', [
            'max_attempts' => LOGIN_ATTEMPTS_LIMIT ?? 5,
            'window' => 900, // 15 minutes
            'lockout_time' => LOCKOUT_TIME ?? 900,
            'identifier' => 'ip_and_username'
        ]);
        
        // API rate limit
        $this->addRule('api_requests', [
            'max_attempts' => 60,
            'window' => 60, // 1 minute
            'identifier' => 'ip'
        ]);
        
        // Password reset attempts
        $this->addRule('password_reset', [
            'max_attempts' => 3,
            'window' => 3600, // 1 hour
            'identifier' => 'ip_and_email'
        ]);
        
        // Form submissions
        $this->addRule('form_submissions', [
            'max_attempts' => 30,
            'window' => 300, // 5 minutes
            'identifier' => 'ip_and_session'
        ]);
    }
    
    /**
     * Add rate limit rule
     * 
     * @param string $name Rule name
     * @param array $config Rule configuration
     */
    public function addRule(string $name, array $config): void
    {
        $this->rules[$name] = array_merge([
            'max_attempts' => 10,
            'window' => 60,
            'lockout_time' => null,
            'identifier' => 'ip',
            'message' => 'Too many attempts. Please try again later.'
        ], $config);
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
     * Check if action is allowed under rate limits
     * 
     * @param string $ruleName Rule to check
     * @param string|null $identifier Custom identifier (optional)
     * @return bool True if allowed
     * @throws SecurityException
     */
    public function check(string $ruleName, ?string $identifier = null): bool
    {
        if (!isset($this->rules[$ruleName])) {
            throw new SecurityException("Rate limit rule '{$ruleName}' not found");
        }
        
        $rule = $this->rules[$ruleName];
        $identifier = $identifier ?? $this->getIdentifier($rule['identifier']);
        
        $attempts = $this->getAttempts($ruleName, $identifier, $rule['window']);
        $allowed = $attempts < $rule['max_attempts'];
        
        if (!$allowed) {
            $this->handleLimitExceeded($ruleName, $rule, $identifier, $attempts);
        }
        
        return $allowed;
    }
    
    /**
     * Track an attempt
     * 
     * @param string $ruleName Rule name
     * @param string|null $identifier Custom identifier
     */
    public function trackAttempt(string $ruleName, ?string $identifier = null): void
    {
        if (!isset($this->rules[$ruleName])) {
            return;
        }
        
        $rule = $this->rules[$ruleName];
        $identifier = $identifier ?? $this->getIdentifier($rule['identifier']);
        
        $this->saveAttempt($ruleName, $identifier);
        
        // Check if this attempt exceeds the limit
        $attempts = $this->getAttempts($ruleName, $identifier, $rule['window']);
        if ($attempts >= $rule['max_attempts']) {
            $this->logger->logRateLimit('limit_exceeded', [
                'rule' => $ruleName,
                'identifier' => $identifier,
                'attempts' => $attempts,
                'max_attempts' => $rule['max_attempts'],
                'window' => $rule['window']
            ], SecurityLogger::LEVEL_WARNING);
        }
    }
    
    /**
     * Get number of attempts in current window
     * 
     * @param string $ruleName
     * @param string $identifier
     * @param int $window Time window in seconds
     * @return int
     */
    private function getAttempts(string $ruleName, string $identifier, int $window): int
    {
        switch ($this->storageType) {
            case self::STORAGE_DATABASE:
                return $this->getAttemptsFromDatabase($ruleName, $identifier, $window);
            case self::STORAGE_CACHE:
                return $this->getAttemptsFromCache($ruleName, $identifier, $window);
            case self::STORAGE_SESSION:
            default:
                return $this->getAttemptsFromSession($ruleName, $identifier, $window);
        }
    }
    
    /**
     * Save an attempt
     * 
     * @param string $ruleName
     * @param string $identifier
     */
    private function saveAttempt(string $ruleName, string $identifier): void
    {
        switch ($this->storageType) {
            case self::STORAGE_DATABASE:
                $this->saveAttemptToDatabase($ruleName, $identifier);
                break;
            case self::STORAGE_CACHE:
                $this->saveAttemptToCache($ruleName, $identifier);
                break;
            case self::STORAGE_SESSION:
            default:
                $this->saveAttemptToSession($ruleName, $identifier);
                break;
        }
    }
    
    /**
     * Get attempts from session storage
     */
    private function getAttemptsFromSession(string $ruleName, string $identifier, int $window): int
    {
        $key = "rate_limit_{$ruleName}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            return 0;
        }
        
        $data = $_SESSION[$key];
        $cutoff = time() - $window;
        
        // Filter out attempts outside the window
        $validAttempts = array_filter($data, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        
        // Update session with filtered attempts
        $_SESSION[$key] = array_values($validAttempts);
        
        return count($validAttempts);
    }
    
    /**
     * Save attempt to session
     */
    private function saveAttemptToSession(string $ruleName, string $identifier): void
    {
        $key = "rate_limit_{$ruleName}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        $_SESSION[$key][] = time();
    }
    
    /**
     * Get attempts from database
     */
    private function getAttemptsFromDatabase(string $ruleName, string $identifier, int $window): int
    {
        if (!$this->db) {
            return 0;
        }
        
        $cutoff = date('Y-m-d H:i:s', time() - $window);
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as attempt_count
            FROM rate_limit_attempts
            WHERE rule_name = ? AND identifier = ? AND attempt_time > ?
        ");
        $stmt->execute([$ruleName, $identifier, $cutoff]);
        
        return (int) $stmt->fetch()['attempt_count'];
    }
    
    /**
     * Save attempt to database
     */
    private function saveAttemptToDatabase(string $ruleName, string $identifier): void
    {
        if (!$this->db) {
            return;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO rate_limit_attempts (rule_name, identifier, attempt_time, ip_address)
            VALUES (?, ?, NOW(), ?)
        ");
        $stmt->execute([$ruleName, $identifier, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    }
    
    /**
     * Get attempts from cache (placeholder for Redis/Memcached)
     */
    private function getAttemptsFromCache(string $ruleName, string $identifier, int $window): int
    {
        // TODO: Implement Redis/Memcached
        return $this->getAttemptsFromSession($ruleName, $identifier, $window);
    }
    
    /**
     * Save attempt to cache
     */
    private function saveAttemptToCache(string $ruleName, string $identifier): void
    {
        // TODO: Implement Redis/Memcached
        $this->saveAttemptToSession($ruleName, $identifier);
    }
    
    /**
     * Handle limit exceeded
     * 
     * @param string $ruleName
     * @param array $rule
     * @param string $identifier
     * @param int $attempts
     * @throws SecurityException
     */
    private function handleLimitExceeded(string $ruleName, array $rule, string $identifier, int $attempts): void
    {
        // Log the event
        $this->logger->logRateLimit('limit_exceeded', [
            'rule' => $ruleName,
            'identifier' => $identifier,
            'attempts' => $attempts,
            'max_attempts' => $rule['max_attempts']
        ], SecurityLogger::LEVEL_WARNING);
        
        // If rule has lockout time, store lockout
        if (isset($rule['lockout_time'])) {
            $this->setLockout($ruleName, $identifier, $rule['lockout_time']);
            
            throw SecurityException::rateLimitExceeded(
                $identifier,
                [
                    'rule' => $ruleName,
                    'lockout_time' => $rule['lockout_time'],
                    'message' => $rule['message']
                ]
            );
        }
        
        throw SecurityException::rateLimitExceeded(
            $identifier,
            [
                'rule' => $ruleName,
                'message' => $rule['message']
            ]
        );
    }
    
    /**
     * Set lockout for identifier
     * 
     * @param string $ruleName
     * @param string $identifier
     * @param int $duration
     */
    private function setLockout(string $ruleName, string $identifier, int $duration): void
    {
        $key = "lockout_{$ruleName}_{$identifier}";
        $_SESSION[$key] = time() + $duration;
    }
    
    /**
     * Check if identifier is locked out
     * 
     * @param string $ruleName
     * @param string $identifier
     * @return bool
     */
    public function isLockedOut(string $ruleName, string $identifier): bool
    {
        $key = "lockout_{$ruleName}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            return false;
        }
        
        if (time() > $_SESSION[$key]) {
            unset($_SESSION[$key]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get remaining lockout time
     * 
     * @param string $ruleName
     * @param string $identifier
     * @return int Seconds remaining (0 if not locked)
     */
    public function getLockoutTime(string $ruleName, string $identifier): int
    {
        $key = "lockout_{$ruleName}_{$identifier}";
        
        if (!isset($_SESSION[$key])) {
            return 0;
        }
        
        $remaining = $_SESSION[$key] - time();
        
        if ($remaining <= 0) {
            unset($_SESSION[$key]);
            return 0;
        }
        
        return $remaining;
    }
    
    /**
     * Generate identifier based on type
     * 
     * @param string $type Identifier type
     * @return string
     */
    private function getIdentifier(string $type): string
    {
        $parts = [];
        
        switch ($type) {
            case 'ip':
                $parts[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                break;
                
            case 'ip_and_username':
                $parts[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $parts[] = $_POST['username'] ?? $_SESSION['username'] ?? 'anonymous';
                break;
                
            case 'ip_and_session':
                $parts[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $parts[] = session_id() ?? 'no_session';
                break;
                
            case 'ip_and_email':
                $parts[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $parts[] = $_POST['email'] ?? 'no_email';
                break;
                
            case 'user_id':
                $parts[] = $_SESSION['user_id'] ?? 'anonymous';
                break;
                
            default:
                $parts[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        return implode('_', $parts);
    }
    
    /**
     * Clear attempts for an identifier
     * 
     * @param string $ruleName
     * @param string|null $identifier
     */
    public function clearAttempts(string $ruleName, ?string $identifier = null): void
    {
        $rule = $this->rules[$ruleName];
        $identifier = $identifier ?? $this->getIdentifier($rule['identifier']);
        
        $key = "rate_limit_{$ruleName}_{$identifier}";
        unset($_SESSION[$key]);
        
        $lockKey = "lockout_{$ruleName}_{$identifier}";
        unset($_SESSION[$lockKey]);
        
        $this->logger->logRateLimit('attempts_cleared', [
            'rule' => $ruleName,
            'identifier' => $identifier
        ]);
    }
}