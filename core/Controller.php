<?php
/**
 * Base Controller
 * 
 * @package Core
 */

namespace Core;

abstract class Controller
{
    /**
     * @var Bootstrap Application instance
     */
    protected $app;
    
    /**
     * @var \PDO Database connection
     */
    protected $db;
    
    /**
     * @var Logger Logger instance
     */
    protected $logger;
    
    /**
     * Constructor
     */
    public function __construct(Bootstrap $app)
    {
        $this->app = $app;
        $this->db = $app->getDb();
        $this->logger = $app->getLogger();
        
        $this->init();
    }
    
    /**
     * Initialize controller
     */
    protected function init()
    {
        // Override in child classes
    }
    
    /**
     * Render view
     */
    protected function view(string $view, array $data = [])
    {
        extract($data);
        
        $viewFile = dirname(__DIR__) . '/views/' . str_replace('.', '/', $view) . '.php';
        
        if (!file_exists($viewFile)) {
            throw new \Exception("View not found: {$view}");
        }
        
        ob_start();
        require $viewFile;
        return ob_get_clean();
    }
    
    /**
     * Return JSON response
     */
    protected function json($data, int $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Redirect to URL
     */
    protected function redirect(string $url)
    {
        header("Location: {$url}");
        exit;
    }
    
    /**
     * Redirect to route
     */
    protected function redirectToRoute(string $route, array $params = [])
    {
        $url = $this->app->getRouter()->route($route, $params);
        $this->redirect($url);
    }
    
    /**
     * Get request parameter
     */
    protected function param(string $name, $default = null)
    {
        return $_REQUEST[$name] ?? $default;
    }
    
    /**
     * Get JSON request body
     */
    protected function getJsonBody()
    {
        return json_decode(file_get_contents('php://input'), true);
    }
    
    /**
     * Validate request data
     */
    protected function validate(array $data, array $rules): array
    {
        $validator = new \App\Validation\Validator();
        return $validator->validate($data, $rules);
    }
    
    /**
     * Check if request is POST
     */
    protected function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
    
    /**
     * Check if request is GET
     */
    protected function isGet(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'GET';
    }
    
    /**
     * Get current user
     */
    protected function user()
    {
        return $_SESSION['user'] ?? null;
    }
    
    /**
     * Check if user is authenticated
     */
    protected function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }
    
    /**
     * Check if user has role
     */
    protected function hasRole(string $role): bool
    {
        return ($_SESSION['role'] ?? '') === $role;
    }
}