<?php
// Prevent direct access
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

// Load Composer autoload for Dotenv
if (file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
}

// Load .env file
if (file_exists(ROOT_PATH . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH, '.env');
    $dotenv->load();
}

// Database Configuration - reads from .env
class DatabaseConfig {
    const HOST = $_ENV['DB_HOST'] ?? 'localhost';
    const USER = $_ENV['DB_USERNAME'] ?? 'root';
    const PASS = $_ENV['DB_PASSWORD'] ?? '';
    const NAME = $_ENV['DB_DATABASE'] ?? 'courier_system';
    const CHARSET = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
}

// Site Configuration from .env
class SiteConfig {
    const NAME = $_ENV['APP_NAME'] ?? 'Courier Truck Management System';
    const URL = $_ENV['APP_URL'] ?? 'http://localhost';
    const TIMEZONE = $_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi';
    const ENV = $_ENV['APP_ENV'] ?? 'development';
    const DEBUG = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

// Database Connection Class
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DatabaseConfig::HOST .
                   ";dbname=" . DatabaseConfig::NAME .
                   ";charset=" . DatabaseConfig::CHARSET;

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->connection = new PDO($dsn, DatabaseConfig::USER, DatabaseConfig::PASS, $options);

        } catch (PDOException $e) {
            if (SiteConfig::DEBUG) {
                error_log("Database Connection Error: " . $e->getMessage());
                die("Database connection failed: " . $e->getMessage());
            } else {
                error_log("Database Connection Error: " . $e->getMessage());
                die("Database connection failed. Please check your configuration.");
            }
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollBack() {
        return $this->connection->rollBack();
    }
}

// Create global database instance
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    die("Unable to connect to database. Please contact administrator.");
}
?>