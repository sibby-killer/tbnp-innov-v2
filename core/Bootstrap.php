<?php
/**
 * Application Bootstrap
 * Core initialization and system startup
 * 
 * @package Core
 * @version 1.0.0
 */

namespace Core;

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Whoops\Run as Whoops;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\JsonResponseHandler;

class Bootstrap
{
    /**
     * @var Bootstrap Singleton instance
     */
    private static $instance = null;
    
    /**
     * @var array Configuration storage
     */
    private $config = [];
    
    /**
     * @var \PDO Database connection
     */
    private $db = null;
    
    /**
     * @var Logger Logger instance
     */
    private $logger = null;
    
    /**
     * @var float Application start time
     */
    private $startTime;
    
    /**
     * Private constructor for singleton
     */
    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->init();
    }
    
    /**
     * Get Bootstrap instance
     * 
     * @return Bootstrap
     */
    public static function getInstance(): Bootstrap
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize all system components
     */
    private function init(): void
    {
        // Load environment first
        $this->loadEnvironment();
        
        // Initialize logging first (so we can log errors)
        $this->initLogger();
        
        // Set error reporting based on environment
        $this->setErrorReporting();
        
        // Register error handlers
        $this->registerErrorHandlers();
        
        // Load configuration
        $this->loadConfiguration();
        
        // Initialize database
        $this->initDatabase();
        
        // Start secure session
        $this->startSecureSession();
        
        // Set timezone
        $this->setTimezone();
        
        // Log application start
        $this->logApplicationStart();
    }
    
    /**
     * Load environment variables
     */
    private function loadEnvironment(): void
    {
        try {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__));
            $dotenv->load();
            
            // Required environment variables
            $dotenv->required([
                'APP_NAME',
                'APP_ENV',
                'DB_HOST',
                'DB_DATABASE',
                'DB_USERNAME'
            ]);
            
        } catch (\Exception $e) {
            die('Environment configuration error: ' . $e->getMessage());
        }
    }
    
    /**
     * Set error reporting based on environment
     */
    private function setErrorReporting(): void
    {
        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        
        if ($isDebug) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
            ini_set('log_errors', 1);
            
            // Ensure log directory exists
            $logDir = dirname(__DIR__) . '/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            ini_set('error_log', $logDir . '/php_errors.log');
        }
    }
    
    /**
     * Register error and exception handlers
     */
    private function registerErrorHandlers(): void
    {
        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        
        if ($isDebug && class_exists('Whoops\Run')) {
            // Use Whoops for debug mode
            $whoops = new Whoops();
            
            if ($this->isAjaxRequest()) {
                $whoops->pushHandler(new JsonResponseHandler());
            } else {
                $whoops->pushHandler(new PrettyPageHandler());
            }
            
            $whoops->register();
        } else {
            // Production error handler
            set_error_handler([$this, 'handleError']);
            set_exception_handler([$this, 'handleException']);
            register_shutdown_function([$this, 'handleShutdown']);
        }
    }
    
    /**
     * Production error handler
     */
    public function handleError($level, $message, $file = '', $line = 0)
    {
        if (error_reporting() & $level) {
            $this->logger->error('PHP Error', [
                'level' => $level,
                'message' => $message,
                'file' => $file,
                'line' => $line
            ]);
        }
        
        // Don't execute PHP internal error handler
        return true;
    }
    
    /**
     * Production exception handler
     */
    public function handleException($exception)
    {
        if ($this->logger) {
            $this->logger->critical('Uncaught Exception', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);
        }
        
        // Show friendly error page
        if (!$this->isAjaxRequest()) {
            $errorFile = dirname(__DIR__) . '/templates/errors/500.php';
            if (file_exists($errorFile)) {
                include $errorFile;
            } else {
                echo "<h1>500 Internal Server Error</h1>";
                echo "<p>An unexpected error occurred.</p>";
                if (($this->config['app']['debug'] ?? false)) {
                    echo "<pre>" . $exception->getMessage() . "</pre>";
                }
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => true,
                'message' => 'An internal error occurred'
            ]);
        }
        exit;
    }
    
    /**
     * Handle fatal errors
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if ($this->logger) {
                $this->logger->critical('Fatal Error', $error);
            }
            
            if (!$this->isAjaxRequest()) {
                $errorFile = dirname(__DIR__) . '/templates/errors/500.php';
                if (file_exists($errorFile)) {
                    include $errorFile;
                }
            }
        }
    }
    
    /**
     * Register autoloader
     */
    private function registerAutoloader(): void
    {
        // This is handled by Composer, but we keep for any custom autoloading
        spl_autoload_register(function ($className) {
            // Remove namespace prefix
            $className = str_replace('App\\', '', $className);
            $className = str_replace('Api\\', 'api/', $className);
            
            // Convert to file path
            $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $className) . '.php';
            
            if (file_exists($file)) {
                require_once $file;
            }
        });
    }
    
    /**
     * Load configuration files
     */
    private function loadConfiguration(): void
    {
        $configDir = dirname(__DIR__) . '/config';
        
        // Load all PHP config files if they exist
        if (is_dir($configDir)) {
            foreach (glob($configDir . '/*.php') as $file) {
                $key = basename($file, '.php');
                $this->config[$key] = require $file;
            }
        }
        
        // Merge with environment variables
        $this->config['app'] = array_merge($this->config['app'] ?? [], [
            'name' => $_ENV['APP_NAME'] ?? 'Courier System',
            'env' => $_ENV['APP_ENV'] ?? 'production',
            'debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
            'url' => $_ENV['APP_URL'] ?? 'http://localhost',
            'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi'
        ]);
    }
    
    /**
     * Initialize logger
     */
    private function initLogger(): void
    {
        try {
            $appName = $_ENV['APP_NAME'] ?? 'Courier System';
            $this->logger = new Logger($appName);
            
            // Ensure logs directory exists
            $logDir = dirname(__DIR__) . '/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            // Daily rotating log files
            $this->logger->pushHandler(
                new RotatingFileHandler(
                    $logDir . '/app.log',
                    30,
                    Logger::DEBUG
                )
            );
            
            // Error log for production
            if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
                $this->logger->pushHandler(
                    new StreamHandler(
                        $logDir . '/error.log',
                        Logger::ERROR
                    )
                );
            }
            
        } catch (\Exception $e) {
            // Logger failed, but we can't log it because logger isn't working
            error_log("Failed to initialize logger: " . $e->getMessage());
        }
    }
    
    /**
     * Initialize database connection
     */
    private function initDatabase(): void
    {
        try {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $database = $_ENV['DB_DATABASE'] ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
            $username = $_ENV['DB_USERNAME'] ?? '';
            $password = $_ENV['DB_PASSWORD'] ?? '';
            
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $host,
                $database,
                $charset
            );
            
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->db = new \PDO(
                $dsn,
                $username,
                $password,
                $options
            );
            
            // Log successful connection
            if ($this->logger) {
                $this->logger->info('Database connected successfully');
            }
            
        } catch (\PDOException $e) {
            if ($this->logger) {
                $this->logger->critical('Database connection failed', [
                    'error' => $e->getMessage()
                ]);
            }
            
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                die('Database connection failed: ' . $e->getMessage());
            } else {
                die('Database connection error. Please try again later.');
            }
        }
    }
    
    /**
     * Start secure session
     */
    private function startSecureSession(): void
    {
        // Only start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            // Set secure session parameters
            ini_set('session.use_strict_mode', 1);
            ini_set('session.use_cookies', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', $this->isHttps() ? 1 : 0);
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.gc_maxlifetime', 7200);
            ini_set('session.cookie_lifetime', 0);
            
            // Start session with custom name
            session_name('cms_session');
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['_initiated'])) {
                session_regenerate_id(true);
                $_SESSION['_initiated'] = true;
                $_SESSION['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $_SESSION['_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            }
            
            // Validate session integrity
            $this->validateSession();
        }
    }
    
    /**
     * Validate session integrity
     */
    private function validateSession(): void
    {
        $validateIp = $this->config['security']['session_validate_ip'] ?? true;
        $validateUa = $this->config['security']['session_validate_user_agent'] ?? true;
        
        if ($validateIp && isset($_SESSION['_ip']) && 
            $_SESSION['_ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
            $this->destroySession();
            return;
        }
        
        if ($validateUa && isset($_SESSION['_user_agent']) && 
            $_SESSION['_user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')) {
            $this->destroySession();
            return;
        }
    }
    
    /**
     * Destroy session
     */
    private function destroySession(): void
    {
        $_SESSION = [];
        
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
        
        session_destroy();
    }
    
    /**
     * Set timezone
     */
    private function setTimezone(): void
    {
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi');
    }
    
    /**
     * Log application start
     */
    private function logApplicationStart(): void
    {
        if ($this->logger) {
            $this->logger->info('Application started', [
                'env' => $_ENV['APP_ENV'] ?? 'unknown',
                'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
    }
    
    /**
     * Check if request is AJAX
     */
    private function isAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Check if using HTTPS
     */
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    /**
     * Get database connection
     * 
     * @return \PDO
     */
    public function getDb(): \PDO
    {
        return $this->db;
    }
    
    /**
     * Get logger
     * 
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
    
    /**
     * Get configuration
     * 
     * @param string|null $key
     * @return mixed
     */
    public function getConfig($key = null)
    {
        if ($key === null) {
            return $this->config;
        }
        
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $segment) {
            if (!isset($value[$segment])) {
                return null;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
    
    /**
     * Get application performance metrics
     * 
     * @return array
     */
    public function getMetrics(): array
    {
        return [
            'execution_time' => (microtime(true) - $this->startTime) * 1000 . 'ms',
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
            'peak_memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . 'MB',
            'db_queries' => defined('DB_QUERY_COUNT') ? DB_QUERY_COUNT : 0
        ];
    }
}