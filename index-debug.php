<?php
/**
 * Debug version of index.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>Debug Mode</h1>";

// Define root path
define('ROOT_PATH', __DIR__);
echo "ROOT_PATH: " . ROOT_PATH . "<br>";

// Load Composer autoloader
echo "Loading autoloader...<br>";
require_once ROOT_PATH . '/vendor/autoload.php';
echo "✓ Autoloader loaded<br>";

// Load Bootstrap
echo "Loading Bootstrap...<br>";
require_once ROOT_PATH . '/core/Bootstrap.php';
echo "✓ Bootstrap loaded<br>";

use Core\Bootstrap;

echo "Getting Bootstrap instance...<br>";
try {
    $app = Bootstrap::getInstance();
    echo "✓ Bootstrap instance created<br>";
    
    // Check database
    $db = $app->getDb();
    echo "✓ Database connected<br>";
    
    // Check logger
    $logger = $app->getLogger();
    echo "✓ Logger created<br>";
    
    echo "<h2 style='color:green'>✅ System working!</h2>";
    
} catch (\Exception $e) {
    echo "<h2 style='color:red'>❌ Error:</h2>";
    echo "<pre>";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
    echo "</pre>";
}

// Show environment
echo "<h3>Environment:</h3>";
echo "<pre>";
foreach ($_ENV as $key => $value) {
    if (stripos($key, 'pass') !== false || stripos($key, 'secret') !== false || stripos($key, 'key') !== false) {
        echo "$key = ********\n";
    } else {
        echo "$key = " . (is_string($value) ? $value : json_encode($value)) . "\n";
    }
}
echo "</pre>";