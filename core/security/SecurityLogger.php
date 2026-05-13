<?php
/**
 * Security Logger - Dedicated logging for security events
 * Integrates with Monolog and your existing LOG_PATH constant
 * 
 * @package Core\Security
 * @version 1.0.0
 */

namespace Core\Security;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Core\Security\Exceptions\SecurityException;

class SecurityLogger
{
    /**
     * Log levels
     */
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * Event categories
     */
    const CATEGORY_AUTH = 'authentication';
    const CATEGORY_ACCESS = 'access_control';
    const CATEGORY_CSRF = 'csrf';
    const CATEGORY_INPUT = 'input_validation';
    const CATEGORY_SESSION = 'session';
    const CATEGORY_RATE_LIMIT = 'rate_limit';
    const CATEGORY_FILE = 'file_upload';
    const CATEGORY_SUSPICIOUS = 'suspicious';
    
    /**
     * @var Logger Monolog instance
     */
    private Logger $logger;
    
    /**
     * @var string Unique request ID for tracking
     */
    private string $requestId;
    
    /**
     * @var array Event statistics for current request
     */
    private array $stats = [
        'total' => 0,
        'by_category' => [],
        'by_level' => []
    ];
    
    /**
     * Constructor
     * 
     * @param string|null $requestId Optional request ID
     * @throws SecurityException If log directory is not writable
     */
    public function __construct(string $requestId = null)
    {
        // Ensure log directory exists and is writable
        $this->ensureLogDirectory();
        
        $this->requestId = $requestId ?? $this->generateRequestId();
        $this->initLogger();
    }
    
    /**
     * Ensure log directory exists and is writable
     * 
     * @throws SecurityException
     */
    private function ensureLogDirectory(): void
    {
        if (!defined('LOG_PATH')) {
            throw new SecurityException('LOG_PATH constant not defined');
        }
        
        if (!is_dir(LOG_PATH)) {
            if (!mkdir(LOG_PATH, 0755, true)) {
                throw new SecurityException('Cannot create log directory: ' . LOG_PATH);
            }
        }
        
        if (!is_writable(LOG_PATH)) {
            throw new SecurityException('Log directory is not writable: ' . LOG_PATH);
        }
    }
    
    /**
     * Initialize Monolog logger with multiple handlers
     */
    private function initLogger(): void
    {
        $this->logger = new Logger('security');
        
        // Main security log - all events (JSON format for easy parsing)
        $securityHandler = new RotatingFileHandler(
            LOG_PATH . '/security.log',
            90, // Keep 90 days for security logs
            Logger::DEBUG
        );
        $securityHandler->setFormatter(new JsonFormatter());
        $this->logger->pushHandler($securityHandler);
        
        // Authentication log - separate file for auth events (human-readable)
        $authHandler = new RotatingFileHandler(
            LOG_PATH . '/auth.log',
            90,
            Logger::INFO
        );
        $authHandler->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s'
        ));
        $this->logger->pushHandler($authHandler);
        
        // Suspicious activity log - high severity only
        $suspiciousHandler = new RotatingFileHandler(
            LOG_PATH . '/suspicious.log',
            365, // Keep 1 year
            Logger::WARNING
        );
        $suspiciousHandler->setFormatter(new JsonFormatter());
        $this->logger->pushHandler($suspiciousHandler);
        
        // Real-time alerts handler for critical events (email, SMS, etc.)
        $alertsHandler = new StreamHandler(
            LOG_PATH . '/alerts.log',
            Logger::CRITICAL
        );
        $this->logger->pushHandler($alertsHandler);
    }
    
    /**
     * Generate unique request ID for tracking
     * 
     * @return string
     */
    private function generateRequestId(): string
    {
        return sprintf(
            '%s_%s_%s',
            date('YmdHis'),
            substr(uniqid(), -6),
            bin2hex(random_bytes(4))
        );
    }
    
    /**
     * Get current request ID
     * 
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }
    
    /**
     * Log authentication event
     * 
     * @param string $action e.g., 'login_success', 'login_failed', 'logout'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logAuth(string $action, array $data = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CATEGORY_AUTH, $action, $data, $level);
    }
    
    /**
     * Log access control event
     * 
     * @param string $action e.g., 'permission_denied', 'access_granted'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logAccess(string $action, array $data = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CATEGORY_ACCESS, $action, $data, $level);
    }
    
    /**
     * Log CSRF event
     * 
     * @param string $action e.g., 'token_generated', 'token_validated', 'token_invalid'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logCsrf(string $action, array $data = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CATEGORY_CSRF, $action, $data, $level);
    }
    
    /**
     * Log input validation event
     * 
     * @param string $action e.g., 'validation_passed', 'validation_failed'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logInput(string $action, array $data = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CATEGORY_INPUT, $action, $data, $level);
    }
    
    /**
     * Log session event
     * 
     * @param string $action e.g., 'session_started', 'session_expired', 'session_regenerated'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logSession(string $action, array $data = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CATEGORY_SESSION, $action, $data, $level);
    }
    
    /**
     * Log rate limit event
     * 
     * @param string $action e.g., 'limit_exceeded', 'limit_reset'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logRateLimit(string $action, array $data = [], string $level = self::LEVEL_WARNING): void
    {
        $this->log(self::CATEGORY_RATE_LIMIT, $action, $data, $level);
    }
    
    /**
     * Log file upload event
     * 
     * @param string $action e.g., 'upload_attempt', 'upload_success', 'upload_failed'
     * @param array $data Context data
     * @param string $level Log level
     */
    public function logFile(string $action, array $data = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CATEGORY_FILE, $action, $data, $level);
    }
    
    /**
     * Log suspicious activity
     * 
     * @param string $action e.g., 'unusual_ip', 'multiple_failures', 'sql_injection_attempt'
     * @param array $data Context data
     * @param string $level Log level (default WARNING or higher)
     */
    public function logSuspicious(string $action, array $data = [], string $level = self::LEVEL_WARNING): void
    {
        $this->log(self::CATEGORY_SUSPICIOUS, $action, $data, $level);
        
        // Trigger alert for critical suspicious events
        if ($level === self::LEVEL_CRITICAL) {
            $this->triggerAlert('suspicious_activity', array_merge($data, ['action' => $action]));
        }
    }
    
    /**
     * Log security exception
     * 
     * @param SecurityException $exception
     * @param array $extra Extra context
     */
    public function logException(SecurityException $exception, array $extra = []): void
    {
        $data = array_merge(
            $exception->getContext(),
            $extra,
            [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]
        );
        
        $level = $this->mapExceptionToLevel($exception);
        
        $this->log(
            $exception->getEventType(),
            $exception->getMessage(),
            $data,
            $level
        );
    }
    
    /**
     * Map exception to log level
     * 
     * @param SecurityException $exception
     * @return string
     */
    private function mapExceptionToLevel(SecurityException $exception): string
    {
        $code = $exception->getCode();
        
        if ($code >= 1007) { // Account locked, rate limit exceeded
            return self::LEVEL_WARNING;
        }
        
        if ($code >= 1003) { // CSRF, input validation
            return self::LEVEL_INFO;
        }
        
        return self::LEVEL_ERROR;
    }
    
    /**
     * Core logging method
     * 
     * @param string $category Event category
     * @param string $action Event action
     * @param array $data Context data
     * @param string $level Log level
     */
    private function log(string $category, string $action, array $data, string $level): void
    {
        // Add standard context to every log entry
        $context = array_merge(
            $this->getStandardContext(),
            $data,
            [
                'category' => $category,
                'action' => $action
            ]
        );
        
        // Update statistics
        $this->updateStats($category, $level);
        
        // Log using Monolog
        switch ($level) {
            case self::LEVEL_CRITICAL:
                $this->logger->critical($action, $context);
                break;
            case self::LEVEL_ERROR:
                $this->logger->error($action, $context);
                break;
            case self::LEVEL_WARNING:
                $this->logger->warning($action, $context);
                break;
            case self::LEVEL_INFO:
            default:
                $this->logger->info($action, $context);
                break;
        }
    }
    
    /**
     * Get standard context data for all logs
     * 
     * @return array
     */
    private function getStandardContext(): array
    {
        return [
            'request_id' => $this->requestId,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? 'anonymous',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'session_id' => session_id() ?: null
        ];
    }
    
    /**
     * Update statistics
     * 
     * @param string $category
     * @param string $level
     */
    private function updateStats(string $category, string $level): void
    {
        $this->stats['total']++;
        
        if (!isset($this->stats['by_category'][$category])) {
            $this->stats['by_category'][$category] = 0;
        }
        $this->stats['by_category'][$category]++;
        
        if (!isset($this->stats['by_level'][$level])) {
            $this->stats['by_level'][$level] = 0;
        }
        $this->stats['by_level'][$level]++;
    }
    
    /**
     * Trigger alert for critical events
     * 
     * @param string $type Alert type
     * @param array $data Alert data
     */
    private function triggerAlert(string $type, array $data): void
    {
        // This will be integrated with notification system later
        // For now, just log to alerts file
        $this->logger->critical('ALERT_TRIGGERED', [
            'alert_type' => $type,
            'data' => $data
        ]);
        
        // TODO: Integrate with notification system
        // - Send email to admin
        // - Send SMS for critical alerts
        // - Push to monitoring dashboard
    }
    
    /**
     * Get statistics for current request
     * 
     * @return array
     */
    public function getStats(): array
    {
        return $this->stats;
    }
    
    /**
     * Clean old log files based on retention policy
     * 
     * @param int $days Number of days to keep
     * @return int Number of files deleted
     */
    public function cleanOldLogs(int $days = 90): int
    {
        $deleted = 0;
        $pattern = LOG_PATH . '/*.log-*';
        
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                $fileTime = filemtime($file);
                if (time() - $fileTime > $days * 86400) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }
        
        $this->logger->info('Cleaned old log files', [
            'deleted_count' => $deleted,
            'retention_days' => $days
        ]);
        
        return $deleted;
    }
    
    /**
     * Search logs for specific events
     * 
     * @param array $criteria Search criteria
     * @param int $limit Maximum results
     * @return array
     */
    public function searchLogs(array $criteria, int $limit = 100): array
    {
        $results = [];
        $logFile = LOG_PATH . '/security.log';
        
        if (!file_exists($logFile)) {
            return $results;
        }
        
        $handle = fopen($logFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if ($this->matchesCriteria($entry, $criteria)) {
                    $results[] = $entry;
                    if (count($results) >= $limit) {
                        break;
                    }
                }
            }
            fclose($handle);
        }
        
        return $results;
    }
    
    /**
     * Check if log entry matches search criteria
     * 
     * @param array|null $entry
     * @param array $criteria
     * @return bool
     */
    private function matchesCriteria(?array $entry, array $criteria): bool
    {
        if (!$entry) {
            return false;
        }
        
        foreach ($criteria as $key => $value) {
            if (!isset($entry[$key]) || $entry[$key] != $value) {
                return false;
            }
        }
        
        return true;
    }
}