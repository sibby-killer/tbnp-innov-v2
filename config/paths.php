<?php
// Define absolute paths
define('BASE_DIR', dirname(dirname(__FILE__)));
define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST'] . '/courier-system');

// Path constants
define('ADMIN_PATH', BASE_DIR . '/admin');
define('AUTH_PATH', BASE_DIR . '/auth');
define('CLIENT_PATH', BASE_DIR . '/client');
define('DRIVER_PATH', BASE_DIR . '/driver');
define('INCLUDES_PATH', BASE_DIR . '/includes');
define('UPLOADS_PATH', BASE_DIR . '/uploads');
define('ASSETS_PATH', BASE_DIR . '/assets');

// URL constants
define('ADMIN_URL', BASE_URL . '/admin');
define('AUTH_URL', BASE_URL . '/auth');
define('CLIENT_URL', BASE_URL . '/client');
define('DRIVER_URL', BASE_URL . '/driver');
define('ASSETS_URL', BASE_URL . '/assets');
?>