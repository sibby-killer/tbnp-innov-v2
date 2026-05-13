<?php
/**
 * Authentication Manager - Handles user authentication, sessions, and password management
 * Uses your ROLE_ADMIN, ROLE_DRIVER, ROLE_CLIENT, SESSION_TIMEOUT constants
 * 
 * @package Core\Security
 * @version 1.0.0
 */

namespace Core\Security;

use Core\Security\Exceptions\SecurityException;

class Authentication
{
    /**
     * Authentication status constants
     */
    const AUTH_SUCCESS = 'success';
    const AUTH_FAILED = 'failed';
    const AUTH_LOCKED = 'locked';
    const AUTH_2FA_REQUIRED = '2fa_required';
    const AUTH_PASSWORD_EXPIRED = 'password_expired';
    
    /**
     * @var SecurityLogger Logger instance
     */
    private SecurityLogger $logger;
    
    /**
     * @var RateLimiter Rate limiter instance
     */
    private RateLimiter $rateLimiter;
    
    /**
     * @var InputSanitizer Input sanitizer instance
     */
    private InputSanitizer $sanitizer;
    
    /**
     * @var \PDO Database connection
     */
    private \PDO $db;
    
    /**
     * @var array Authentication configuration
     */
    private array $config;
    
    /**
     * Constructor
     * 
     * @param SecurityLogger $logger
     * @param RateLimiter $rateLimiter
     * @param InputSanitizer $sanitizer
     * @param \PDO $db
     * @param array $config
     */
    public function __construct(
        SecurityLogger $logger,
        RateLimiter $rateLimiter,
        InputSanitizer $sanitizer,
        \PDO $db,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
        $this->sanitizer = $sanitizer;
        $this->db = $db;
        
        $this->config = array_merge([
            'session_timeout' => SESSION_TIMEOUT ?? 3600,
            'password_min_length' => 8,
            'password_requires_uppercase' => true,
            'password_requires_numbers' => true,
            'password_requires_symbols' => true,
            'password_expiry_days' => 90,
            'password_history' => 5, // Number of previous passwords to remember
            'remember_login_days' => 30,
            'max_concurrent_sessions' => 5,
            'session_regenerate_on_login' => true,
            'session_regenerate_on_privilege_change' => true
        ], $config);
    }
    
    /**
     * Authenticate user with username/email and password
     * 
     * @param string $username Username or email
     * @param string $password Plain text password
     * @param bool $remember Remember login
     * @return array Authentication result
     */
    public function authenticate(string $username, string $password, bool $remember = false): array
    {
        try {
            // Sanitize username
            $username = $this->sanitizer->sanitize($username, 'string', ['trim' => true]);
            
            // Check rate limiting for login attempts
            if (!$this->rateLimiter->check('login_attempts', $username)) {
                $lockoutTime = $this->rateLimiter->getLockoutTime('login_attempts', $username);
                
                $this->logger->logAuth('login_blocked_rate_limit', [
                    'username' => $username,
                    'lockout_time' => $lockoutTime
                ], SecurityLogger::LEVEL_WARNING);
                
                return [
                    'status' => self::AUTH_LOCKED,
                    'message' => "Too many failed attempts. Please try again in {$lockoutTime} seconds.",
                    'lockout_time' => $lockoutTime
                ];
            }
            
            // Get user by username or email
            $user = $this->getUserByUsername($username);
            
            if (!$user) {
                // Track failed attempt
                $this->rateLimiter->trackAttempt('login_attempts', $username);
                
                $this->logger->logAuth('login_failed', [
                    'username' => $username,
                    'reason' => 'user_not_found'
                ], SecurityLogger::LEVEL_WARNING);
                
                return [
                    'status' => self::AUTH_FAILED,
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Check if account is locked
            if ($this->isAccountLocked($user['id'])) {
                $this->logger->logAuth('login_blocked_locked', [
                    'user_id' => $user['id'],
                    'username' => $username
                ], SecurityLogger::LEVEL_WARNING);
                
                return [
                    'status' => self::AUTH_LOCKED,
                    'message' => 'This account has been locked. Please contact administrator.'
                ];
            }
            
            // Verify password
            if (!$this->verifyPassword($password, $user['password_hash'])) {
                // Track failed attempt
                $this->rateLimiter->trackAttempt('login_attempts', $username);
                $this->recordFailedLogin($user['id'], $username);
                
                // Check if account should be locked
                if ($this->shouldLockAccount($user['id'])) {
                    $this->lockAccount($user['id']);
                }
                
                $this->logger->logAuth('login_failed', [
                    'user_id' => $user['id'],
                    'username' => $username,
                    'reason' => 'invalid_password'
                ], SecurityLogger::LEVEL_WARNING);
                
                return [
                    'status' => self::AUTH_FAILED,
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Check if password has expired
            if ($this->isPasswordExpired($user)) {
                $this->logger->logAuth('password_expired', [
                    'user_id' => $user['id'],
                    'username' => $username
                ]);
                
                return [
                    'status' => self::AUTH_PASSWORD_EXPIRED,
                    'message' => 'Your password has expired. Please change it.',
                    'user_id' => $user['id'],
                    'reset_token' => $this->generatePasswordResetToken($user['id'])
                ];
            }
            
            // Check if 2FA is enabled
            if (!empty($user['two_factor_secret'])) {
                return [
                    'status' => self::AUTH_2FA_REQUIRED,
                    'message' => 'Two-factor authentication required',
                    'user_id' => $user['id'],
                    'temp_token' => $this->generateTempToken($user['id'])
                ];
            }
            
            // Check concurrent sessions limit
            if (!$this->checkConcurrentSessions($user['id'])) {
                $this->terminateOldestSession($user['id']);
            }
            
            // Complete login
            $sessionData = $this->createSession($user, $remember);
            
            // Clear failed attempts
            $this->clearFailedAttempts($user['id'], $username);
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Log success
            $this->logger->logAuth('login_success', [
                'user_id' => $user['id'],
                'username' => $username,
                'role_id' => $user['role_id'],
                'remember' => $remember
            ]);
            
            return [
                'status' => self::AUTH_SUCCESS,
                'message' => 'Login successful',
                'user' => $this->sanitizeUserData($user),
                'session' => $sessionData
            ];
            
        } catch (SecurityException $e) {
            $this->logger->logException($e);
            
            return [
                'status' => self::AUTH_FAILED,
                'message' => 'Authentication error. Please try again.'
            ];
        }
    }
    
    /**
     * Verify two-factor authentication code
     * 
     * @param int $userId
     * @param string $code
     * @param string $tempToken
     * @return array
     */
    public function verifyTwoFactor(int $userId, string $code, string $tempToken): array
    {
        // Verify temp token
        if (!$this->verifyTempToken($userId, $tempToken)) {
            $this->logger->logAuth('2fa_failed', [
                'user_id' => $userId,
                'reason' => 'invalid_temp_token'
            ], SecurityLogger::LEVEL_WARNING);
            
            return [
                'status' => self::AUTH_FAILED,
                'message' => 'Invalid authentication session'
            ];
        }
        
        // Get user's 2FA secret
        $user = $this->getUserById($userId);
        
        if (!$user || empty($user['two_factor_secret'])) {
            return [
                'status' => self::AUTH_FAILED,
                'message' => '2FA not configured'
            ];
        }
        
        // Verify code
        if (!$this->verifyTwoFactorCode($user['two_factor_secret'], $code)) {
            $this->logger->logAuth('2fa_failed', [
                'user_id' => $userId,
                'reason' => 'invalid_code'
            ], SecurityLogger::LEVEL_WARNING);
            
            return [
                'status' => self::AUTH_FAILED,
                'message' => 'Invalid verification code'
            ];
        }
        
        // Complete login
        $sessionData = $this->createSession($user, false);
        
        $this->logger->logAuth('2fa_success', [
            'user_id' => $userId,
            'username' => $user['username']
        ]);
        
        return [
            'status' => self::AUTH_SUCCESS,
            'message' => 'Login successful',
            'user' => $this->sanitizeUserData($user),
            'session' => $sessionData
        ];
    }
    
    /**
     * Create user session
     * 
     * @param array $user User data
     * @param bool $remember Remember login
     * @return array Session data
     */
    private function createSession(array $user, bool $remember): array
    {
        // Regenerate session ID to prevent fixation
        if ($this->config['session_regenerate_on_login']) {
            session_regenerate_id(true);
        }
        
        // Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $this->getRoleName($user['role_id']);
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $_SESSION['session_id'] = session_id();
        
        // Set session timeout
        $_SESSION['expires_at'] = time() + $this->config['session_timeout'];
        
        // Handle remember me
        if ($remember) {
            $this->setRememberMe($user['id']);
        }
        
        // Record session in database
        $this->recordSession($user['id'], session_id(), $remember);
        
        return [
            'session_id' => session_id(),
            'expires_at' => $_SESSION['expires_at'],
            'remember' => $remember
        ];
    }
    
    /**
     * Set remember me cookie
     * 
     * @param int $userId
     */
    private function setRememberMe(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + ($this->config['remember_login_days'] * 86400);
        
        // Store token in database
        $stmt = $this->db->prepare("
            INSERT INTO user_remember_tokens (user_id, token, expires_at, created_at)
            VALUES (?, ?, FROM_UNIXTIME(?), NOW())
        ");
        $stmt->execute([$userId, $token, $expires]);
        
        // Set cookie
        setcookie(
            'remember_token',
            $token,
            $expires,
            '/',
            '',
            true, // Secure
            true  // HttpOnly
        );
    }
    
    /**
     * Check remember me cookie
     * 
     * @return bool
     */
    public function checkRememberMe(): bool
    {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }
        
        $token = $_COOKIE['remember_token'];
        
        // Find token in database
        $stmt = $this->db->prepare("
            SELECT user_id, expires_at 
            FROM user_remember_tokens 
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $data = $stmt->fetch();
        
        if (!$data) {
            // Invalid token, clear cookie
            setcookie('remember_token', '', time() - 3600, '/');
            return false;
        }
        
        // Get user
        $user = $this->getUserById($data['user_id']);
        
        if (!$user || $this->isAccountLocked($user['id'])) {
            return false;
        }
        
        // Create session
        $this->createSession($user, true);
        
        $this->logger->logAuth('remember_me_login', [
            'user_id' => $user['id'],
            'username' => $user['username']
        ]);
        
        return true;
    }
    
    /**
     * Log out user
     * 
     * @param bool $everywhere Logout from all devices
     */
    public function logout(bool $everywhere = false): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        $sessionId = session_id();
        
        // Log the logout
        if ($userId) {
            $this->logger->logAuth('logout', [
                'user_id' => $userId,
                'everywhere' => $everywhere
            ]);
        }
        
        if ($everywhere && $userId) {
            // Delete all sessions for this user
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Delete remember tokens
            $stmt = $this->db->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
        } else {
            // Delete current session only
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE session_id = ?");
            $stmt->execute([$sessionId]);
            
            // Delete remember cookie
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        // Clear session
        $_SESSION = [];
        
        // Destroy session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Check if user is authenticated
     * 
     * @return bool
     */
    public function check(): bool
    {
        // Check if session exists
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Check session expiration
        if (isset($_SESSION['expires_at']) && $_SESSION['expires_at'] < time()) {
            $this->logger->logSession('session_expired', [
                'user_id' => $_SESSION['user_id']
            ]);
            $this->logout();
            return false;
        }
        
        // Check IP address if strict mode
        if (($this->config['strict_ip_check'] ?? false) && 
            $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
            $this->logger->logSuspicious('ip_mismatch', [
                'user_id' => $_SESSION['user_id'],
                'session_ip' => $_SESSION['ip_address'],
                'current_ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
            $this->logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Require authentication or redirect
     * 
     * @param string $redirect URL to redirect to
     */
    public function requireAuth(string $redirect = '/auth/login.php'): void
    {
        if (!$this->check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . $redirect);
            exit;
        }
    }
    
    /**
     * Get current authenticated user
     * 
     * @return array|null
     */
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        
        return $this->getUserById($_SESSION['user_id']);
    }
    
    /**
     * Get user ID
     * 
     * @return int|null
     */
    public function id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get user role
     * 
     * @return int|null
     */
    public function role(): ?int
    {
        return $_SESSION['role_id'] ?? null;
    }
    
    /**
     * Check if user has specific role
     * 
     * @param int|array $roles Role ID or array of role IDs
     * @return bool
     */
    public function hasRole($roles): bool
    {
        if (!$this->check()) {
            return false;
        }
        
        if (is_array($roles)) {
            return in_array($_SESSION['role_id'], $roles);
        }
        
        return $_SESSION['role_id'] == $roles;
    }
    
    /**
     * Change user password
     * 
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @return bool
     * @throws SecurityException
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        // Get user
        $user = $this->getUserById($userId);
        
        if (!$user) {
            throw new SecurityException("User not found");
        }
        
        // Verify current password
        if (!$this->verifyPassword($currentPassword, $user['password_hash'])) {
            $this->logger->logAuth('password_change_failed', [
                'user_id' => $userId,
                'reason' => 'invalid_current_password'
            ], SecurityLogger::LEVEL_WARNING);
            
            throw new SecurityException("Current password is incorrect");
        }
        
        // Validate new password strength
        $validation = $this->validatePasswordStrength($newPassword);
        if (!$validation['valid']) {
            throw new SecurityException(
                "Password does not meet requirements: " . implode(', ', $validation['errors'])
            );
        }
        
        // Check password history
        if (!$this->checkPasswordHistory($userId, $newPassword)) {
            throw new SecurityException("You have used this password recently. Please choose a different one.");
        }
        
        // Hash new password
        $newHash = $this->hashPassword($newPassword);
        
        // Update password
        $stmt = $this->db->prepare("
            UPDATE users 
            SET password_hash = ?, 
                password_changed_at = NOW(),
                password_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)
            WHERE id = ?
        ");
        $result = $stmt->execute([$newHash, $this->config['password_expiry_days'], $userId]);
        
        if ($result) {
            // Save to password history
            $this->savePasswordHistory($userId, $newHash);
            
            $this->logger->logAuth('password_changed', [
                'user_id' => $userId
            ]);
        }
        
        return $result;
    }
    
    /**
     * Validate password strength
     * 
     * @param string $password
     * @return array
     */
    public function validatePasswordStrength(string $password): array
    {
        $errors = [];
        
        if (strlen($password) < $this->config['password_min_length']) {
            $errors[] = "Password must be at least {$this->config['password_min_length']} characters";
        }
        
        if ($this->config['password_requires_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }
        
        if ($this->config['password_requires_numbers'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        
        if ($this->config['password_requires_symbols'] && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }
        
        // Check for common passwords
        if ($this->isCommonPassword($password)) {
            $errors[] = "This password is too common. Please choose a stronger one.";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'strength' => $this->calculatePasswordStrength($password)
        ];
    }
    
    /**
     * Hash password
     * 
     * @param string $password
     * @return string
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 2048,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Verify password
     * 
     * @param string $password
     * @param string $hash
     * @return bool
     */
    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Check password history
     * 
     * @param int $userId
     * @param string $newPassword
     * @return bool
     */
    private function checkPasswordHistory(int $userId, string $newPassword): bool
    {
        $stmt = $this->db->prepare("
            SELECT password_hash 
            FROM password_history 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $this->config['password_history']]);
        
        while ($row = $stmt->fetch()) {
            if (password_verify($newPassword, $row['password_hash'])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Save password to history
     * 
     * @param int $userId
     * @param string $hash
     */
    private function savePasswordHistory(int $userId, string $hash): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO password_history (user_id, password_hash, created_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$userId, $hash]);
        
        // Keep only last N passwords
        $stmt = $this->db->prepare("
            DELETE FROM password_history 
            WHERE user_id = ? 
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM password_history 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?
                ) AS tmp
            )
        ");
        $stmt->execute([$userId, $userId, $this->config['password_history']]);
    }
    
    /**
     * Generate password reset token
     * 
     * @param int $userId
     * @return string
     */
    public function generatePasswordResetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + 3600; // 1 hour
        
        $stmt = $this->db->prepare("
            INSERT INTO password_resets (user_id, token, expires_at, created_at)
            VALUES (?, ?, FROM_UNIXTIME(?), NOW())
        ");
        $stmt->execute([$userId, $token, $expires]);
        
        return $token;
    }
    
    /**
     * Verify password reset token
     * 
     * @param string $token
     * @return int|null User ID if valid
     */
    public function verifyPasswordResetToken(string $token): ?int
    {
        $stmt = $this->db->prepare("
            SELECT user_id 
            FROM password_resets 
            WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        
        return $result ? $result['user_id'] : null;
    }
    
    /**
     * Reset password with token
     * 
     * @param string $token
     * @param string $newPassword
     * @return bool
     * @throws SecurityException
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $userId = $this->verifyPasswordResetToken($token);
        
        if (!$userId) {
            throw new SecurityException("Invalid or expired reset token");
        }
        
        // Validate password strength
        $validation = $this->validatePasswordStrength($newPassword);
        if (!$validation['valid']) {
            throw new SecurityException(
                "Password does not meet requirements: " . implode(', ', $validation['errors'])
            );
        }
        
        // Hash new password
        $newHash = $this->hashPassword($newPassword);
        
        // Update password
        $stmt = $this->db->prepare("
            UPDATE users 
            SET password_hash = ?, 
                password_changed_at = NOW(),
                password_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)
            WHERE id = ?
        ");
        $stmt->execute([$newHash, $this->config['password_expiry_days'], $userId]);
        
        // Mark token as used
        $stmt = $this->db->prepare("
            UPDATE password_resets 
            SET used_at = NOW() 
            WHERE token = ?
        ");
        $stmt->execute([$token]);
        
        // Log all other sessions out
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $this->logger->logAuth('password_reset', [
            'user_id' => $userId
        ]);
        
        return true;
    }
    
    /**
     * Get user by username or email
     * 
     * @param string $username
     * @return array|null
     */
    private function getUserByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, email, password_hash, role_id, 
                   two_factor_secret, account_locked, locked_until,
                   password_changed_at, password_expires_at,
                   failed_attempts, last_login_at
            FROM users 
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get user by ID
     * 
     * @param int $userId
     * @return array|null
     */
    public function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, email, role_id, two_factor_secret,
                   account_locked, locked_until, last_login_at,
                   created_at
            FROM users 
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Check if account is locked
     * 
     * @param int $userId
     * @return bool
     */
    private function isAccountLocked(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT account_locked, locked_until 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false;
        }
        
        if ($user['account_locked']) {
            if ($user['locked_until'] && strtotime($user['locked_until']) < time()) {
                // Auto-unlock
                $this->unlockAccount($userId);
                return false;
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if account should be locked based on failed attempts
     * 
     * @param int $userId
     * @return bool
     */
    private function shouldLockAccount(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM failed_logins 
            WHERE user_id = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return ($result['attempt_count'] ?? 0) >= (LOGIN_ATTEMPTS_LIMIT ?? 5);
    }
    
    /**
     * Lock user account
     * 
     * @param int $userId
     */
    private function lockAccount(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET account_locked = 1, 
                locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
            WHERE id = ?
        ");
        $stmt->execute([LOCKOUT_TIME ?? 900, $userId]);
        
        $this->logger->logAuth('account_locked', [
            'user_id' => $userId
        ], SecurityLogger::LEVEL_WARNING);
    }
    
    /**
     * Unlock user account
     * 
     * @param int $userId
     */
    private function unlockAccount(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET account_locked = 0, 
                locked_until = NULL,
                failed_attempts = 0
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    }
    
    /**
     * Record failed login attempt
     * 
     * @param int|null $userId
     * @param string $username
     */
    private function recordFailedLogin(?int $userId, string $username): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO failed_logins (user_id, username, ip_address, user_agent, attempt_time)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $username,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Increment failed attempts counter
        if ($userId) {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET failed_attempts = failed_attempts + 1 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        }
    }
    
    /**
     * Clear failed attempts after successful login
     * 
     * @param int $userId
     * @param string $username
     */
    private function clearFailedAttempts(int $userId, string $username): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM failed_logins 
            WHERE user_id = ? OR username = ?
        ");
        $stmt->execute([$userId, $username]);
        
        $stmt = $this->db->prepare("
            UPDATE users 
            SET failed_attempts = 0 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    }
    
    /**
     * Check if password has expired
     * 
     * @param array $user
     * @return bool
     */
    private function isPasswordExpired(array $user): bool
    {
        if (empty($user['password_expires_at'])) {
            return false;
        }
        
        return strtotime($user['password_expires_at']) < time();
    }
    
    /**
     * Check concurrent sessions limit
     * 
     * @param int $userId
     * @return bool
     */
    private function checkConcurrentSessions(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as session_count 
            FROM user_sessions 
            WHERE user_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return ($result['session_count'] ?? 0) < $this->config['max_concurrent_sessions'];
    }
    
    /**
     * Terminate oldest session
     * 
     * @param int $userId
     */
    private function terminateOldestSession(int $userId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM user_sessions 
            WHERE user_id = ? 
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
    }
    
    /**
     * Record session in database
     * 
     * @param int $userId
     * @param string $sessionId
     * @param bool $remember
     */
    private function recordSession(int $userId, string $sessionId, bool $remember): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, remember, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW(), FROM_UNIXTIME(?))
        ");
        $stmt->execute([
            $userId,
            $sessionId,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $remember ? 1 : 0,
            $_SESSION['expires_at']
        ]);
    }
    
    /**
     * Update last login timestamp
     * 
     * @param int $userId
     */
    private function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET last_login_at = NOW(),
                last_login_ip = ?
            WHERE id = ?
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $userId]);
    }
    
    /**
     * Generate temporary token for 2FA
     * 
     * @param int $userId
     * @return string
     */
    private function generateTempToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        
        $_SESSION['2fa_temp'][$token] = [
            'user_id' => $userId,
            'expires' => time() + 300 // 5 minutes
        ];
        
        return $token;
    }
    
    /**
     * Verify temporary token
     * 
     * @param int $userId
     * @param string $token
     * @return bool
     */
    private function verifyTempToken(int $userId, string $token): bool
    {
        if (!isset($_SESSION['2fa_temp'][$token])) {
            return false;
        }
        
        $data = $_SESSION['2fa_temp'][$token];
        
        if ($data['user_id'] !== $userId || time() > $data['expires']) {
            unset($_SESSION['2fa_temp'][$token]);
            return false;
        }
        
        unset($_SESSION['2fa_temp'][$token]);
        return true;
    }
    
    /**
     * Verify 2FA code
     * 
     * @param string $secret
     * @param string $code
     * @return bool
     */
    private function verifyTwoFactorCode(string $secret, string $code): bool
    {
        // This is a placeholder - implement with Google Authenticator or similar
        // You'll need to include a 2FA library like sonata-project/google-authenticator
        return true;
    }
    
    /**
     * Get role name from ID
     * 
     * @param int $roleId
     * @return string
     */
    private function getRoleName(int $roleId): string
    {
        $roles = [
            ROLE_ADMIN => 'Admin',
            ROLE_DRIVER => 'Driver',
            ROLE_CLIENT => 'Client'
        ];
        
        return $roles[$roleId] ?? 'Unknown';
    }
    
    /**
     * Sanitize user data for output (remove sensitive fields)
     * 
     * @param array $user
     * @return array
     */
    private function sanitizeUserData(array $user): array
    {
        unset(
            $user['password_hash'],
            $user['two_factor_secret'],
            $user['account_locked'],
            $user['locked_until']
        );
        
        return $user;
    }
    
    /**
     * Check if password is common
     * 
     * @param string $password
     * @return bool
     */
    private function isCommonPassword(string $password): bool
    {
        $commonPasswords = [
            'password', '123456', '12345678', '1234', 'qwerty',
            'abc123', 'password1', 'admin', 'letmein', 'welcome'
        ];
        
        return in_array(strtolower($password), $commonPasswords);
    }
    
    /**
     * Calculate password strength (0-100)
     * 
     * @param string $password
     * @return int
     */
    private function calculatePasswordStrength(string $password): int
    {
        $score = 0;
        
        // Length score (up to 40)
        $score += min(40, strlen($password) * 4);
        
        // Character variety score (up to 40)
        if (preg_match('/[a-z]/', $password)) $score += 10;
        if (preg_match('/[A-Z]/', $password)) $score += 10;
        if (preg_match('/[0-9]/', $password)) $score += 10;
        if (preg_match('/[^a-zA-Z0-9]/', $password)) $score += 10;
        
        // Uniqueness score (up to 20)
        $unique = count(array_unique(str_split($password)));
        $score += min(20, $unique * 2);
        
        return min(100, $score);
    }
}