<?php
/**
 * Minimal Bootstrap for Testing
 * 
 * @package Core
 */

namespace Core;

use Dotenv\Dotenv;

class Bootstrap
{
    private static $instance = null;
    private $db = null;
    private $logger = null;
    private $config = [];
    
    private function __construct()
    {
        echo "▶ Minimal Bootstrap constructor started...<br>";
        $this->loadEnvironment();
        echo "▶ Environment loaded successfully<br>";
    }
    
    public static function getInstance(): Bootstrap
    {
        if (self::$instance === null) {
            echo "▶ Creating new Minimal Bootstrap instance...<br>";
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function loadEnvironment(): void
    {
        try {
            $envPath = dirname(__DIR__);
            echo "▶ Loading .env from: {$envPath}<br>";
            
            if (!file_exists($envPath . '/.env')) {
                throw new \Exception(".env file not found at {$envPath}/.env");
            }
            
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->load();
            echo "✓ .env file loaded successfully<br>";
            
            // Check required variables
            $required = ['APP_NAME', 'APP_ENV', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME'];
            echo "▶ Checking required variables: " . implode(', ', $required) . "<br>";
            
            foreach ($required as $var) {
                if (!isset($_ENV[$var]) && !isset($_SERVER[$var])) {
                    throw new \Exception("Required environment variable '{$var}' is not set");
                }
                $value = $_ENV[$var] ?? $_SERVER[$var] ?? null;
                echo "  ✓ {$var} = " . (strpos($var, 'PASS') ? '********' : $value) . "<br>";
            }
            
            echo "✓ All required variables present<br>";
            
        } catch (\Exception $e) {
            echo "<span style='color:red'>✗ Environment error: " . $e->getMessage() . "</span><br>";
            throw $e;
        }
    }
    
    public function getDb()
    {
        return $this->db;
    }
    
    public function getLogger()
    {
        return $this->logger;
    }
    
    public function getConfig()
    {
        return $this->config;
    }
}