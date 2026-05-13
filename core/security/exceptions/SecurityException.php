<?php
/**
 * Security Exception - Custom exception for security-related errors
 * 
 * @package Core\Security\Exceptions
 * @version 1.0.0
 */

namespace Core\Security\Exceptions;

/**
 * Custom exception class for security operations
 * Includes context data for better debugging and logging
 */
class SecurityException extends \Exception
{
    /**
     * @var array Additional context data about the exception
     */
    protected array $context = [];
    
    /**
     * @var string Security event type
     */
    protected string $eventType = 'security_error';
    
    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param array $context Additional context data
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message, 
        array $context = [], 
        int $code = 0, 
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    /**
     * Get context data
     * 
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }
    
    /**
     * Set security event type
     * 
     * @param string $eventType
     * @return self
     */
    public function setEventType(string $eventType): self
    {
        $this->eventType = $eventType;
        return $this;
    }
    
    /**
     * Get security event type
     * 
     * @return string
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }
    
    /**
     * Convert exception to array for logging
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
            'trace' => $this->getTraceAsString()
        ];
    }
    
    /**
     * Create from authentication failure
     * 
     * @param string $reason
     * @param array $context
     * @return self
     */
    public static function authenticationFailed(string $reason, array $context = []): self
    {
        return (new self("Authentication failed: {$reason}", $context, 1001))
            ->setEventType('authentication_failure');
    }
    
    /**
     * Create from authorization failure
     * 
     * @param string $permission
     * @param array $context
     * @return self
     */
    public static function unauthorized(string $permission, array $context = []): self
    {
        return (new self("Unauthorized access: missing permission '{$permission}'", $context, 1002))
            ->setEventType('authorization_failure');
    }
    
    /**
     * Create from CSRF validation failure
     * 
     * @param array $context
     * @return self
     */
    public static function invalidCsrfToken(array $context = []): self
    {
        return (new self("Invalid CSRF token", $context, 1003))
            ->setEventType('csrf_failure');
    }
    
    /**
     * Create from rate limit exceeded
     * 
     * @param string $identifier
     * @param array $context
     * @return self
     */
    public static function rateLimitExceeded(string $identifier, array $context = []): self
    {
        return (new self("Rate limit exceeded for: {$identifier}", $context, 1004))
            ->setEventType('rate_limit_exceeded');
    }
    
    /**
     * Create from invalid input
     * 
     * @param string $field
     * @param string $reason
     * @param array $context
     * @return self
     */
    public static function invalidInput(string $field, string $reason, array $context = []): self
    {
        return (new self("Invalid input for field '{$field}': {$reason}", $context, 1005))
            ->setEventType('input_validation_failure');
    }
    
    /**
     * Create from session expiration
     * 
     * @param array $context
     * @return self
     */
    public static function sessionExpired(array $context = []): self
    {
        return (new self("Session has expired", $context, 1006))
            ->setEventType('session_expired');
    }
    
    /**
     * Create from account lockout
     * 
     * @param int $userId
     * @param int $lockoutTime
     * @param array $context
     * @return self
     */
    public static function accountLocked(int $userId, int $lockoutTime, array $context = []): self
    {
        return (new self(
            "Account locked due to multiple failed attempts. Try again in {$lockoutTime} seconds", 
            array_merge($context, ['user_id' => $userId, 'lockout_time' => $lockoutTime]), 
            1007
        ))->setEventType('account_locked');
    }
    
    /**
     * Create from 2FA failure
     * 
     * @param array $context
     * @return self
     */
    public static function twoFactorFailed(array $context = []): self
    {
        return (new self("Two-factor authentication failed", $context, 1008))
            ->setEventType('two_factor_failure');
    }
    
    /**
     * Create from file upload violation
     * 
     * @param string $filename
     * @param string $reason
     * @param array $context
     * @return self
     */
    public static function fileUploadViolation(string $filename, string $reason, array $context = []): self
    {
        return (new self(
            "File upload violation for '{$filename}': {$reason}", 
            array_merge($context, ['filename' => $filename]), 
            1009
        ))->setEventType('file_upload_violation');
    }
}