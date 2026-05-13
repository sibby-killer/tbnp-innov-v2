<?php
/**
 * Advanced Router with middleware support
 * 
 * @package Core
 */

namespace Core;

class Router
{
    /**
     * @var array Registered routes
     */
    protected $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => [],
        'PATCH' => [],
        'OPTIONS' => []
    ];
    
    /**
     * @var array Route groups
     */
    protected $groups = [];
    
    /**
     * @var array Middleware stack
     */
    protected $middleware = [];
    
    /**
     * @var array Global middleware
     */
    protected $globalMiddleware = [];
    
    /**
     * @var array Named routes for reverse routing
     */
    protected $namedRoutes = [];
    
    /**
     * @var array Current route parameters
     */
    protected $params = [];
    
    /**
     * @var Bootstrap Application instance
     */
    protected $app;
    
    /**
     * Constructor
     */
    public function __construct(Bootstrap $app)
    {
        $this->app = $app;
    }
    
    /**
     * Register GET route
     */
    public function get(string $path, $handler, string $name = null): self
    {
        return $this->addRoute('GET', $path, $handler, $name);
    }
    
    /**
     * Register POST route
     */
    public function post(string $path, $handler, string $name = null): self
    {
        return $this->addRoute('POST', $path, $handler, $name);
    }
    
    /**
     * Register PUT route
     */
    public function put(string $path, $handler, string $name = null): self
    {
        return $this->addRoute('PUT', $path, $handler, $name);
    }
    
    /**
     * Register DELETE route
     */
    public function delete(string $path, $handler, string $name = null): self
    {
        return $this->addRoute('DELETE', $path, $handler, $name);
    }
    
    /**
     * Register PATCH route
     */
    public function patch(string $path, $handler, string $name = null): self
    {
        return $this->addRoute('PATCH', $path, $handler, $name);
    }
    
    /**
     * Register OPTIONS route
     */
    public function options(string $path, $handler, string $name = null): self
    {
        return $this->addRoute('OPTIONS', $path, $handler, $name);
    }
    
    /**
     * Register route for multiple methods
     */
    public function match(array $methods, string $path, $handler, string $name = null): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler, $name);
        }
        
        return $this;
    }
    
    /**
     * Register route for all methods
     */
    public function any(string $path, $handler, string $name = null): self
    {
        foreach (array_keys($this->routes) as $method) {
            $this->addRoute($method, $path, $handler, $name);
        }
        
        return $this;
    }
    
    /**
     * Add route to registry
     */
    protected function addRoute(string $method, string $path, $handler, ?string $name): self
    {
        // Apply group prefixes
        if (!empty($this->groups)) {
            $group = end($this->groups);
            
            if (isset($group['prefix'])) {
                $path = rtrim($group['prefix'], '/') . '/' . ltrim($path, '/');
            }
            
            if (isset($group['middleware'])) {
                // Merge middleware from group
                $middleware = array_merge($group['middleware'], $handler['middleware'] ?? []);
                if (is_array($handler)) {
                    $handler['middleware'] = $middleware;
                }
            }
        }
        
        // Convert path to regex pattern
        $pattern = $this->compilePattern($path);
        
        $route = [
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
            'method' => $method,
            'name' => $name,
            'middleware' => []
        ];
        
        // Store named route for reverse routing
        if ($name) {
            $this->namedRoutes[$name] = $route;
        }
        
        $this->routes[$method][] = $route;
        
        return $this;
    }
    
    /**
     * Create route group
     */
    public function group(array $attributes, callable $callback): self
    {
        $this->groups[] = $attributes;
        $callback($this);
        array_pop($this->groups);
        
        return $this;
    }
    
    /**
     * Add middleware to route
     */
    public function middleware($middleware): self
    {
        if (!empty($this->routes)) {
            $lastRoute = &$this->routes[array_key_last($this->routes)];
            $lastRoute[array_key_last($lastRoute)]['middleware'][] = $middleware;
        }
        
        return $this;
    }
    
    /**
     * Add global middleware
     */
    public function addGlobalMiddleware($middleware): self
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }
    
    /**
     * Compile route pattern to regex
     */
    protected function compilePattern(string $path): string
    {
        // Replace {param} with regex capture
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($matches) {
                return '(?P<' . $matches[1] . '>[^/]+)';
            },
            $path
        );
        
        // Replace {param:pattern} with custom regex
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*):([^}]+)\}/',
            function ($matches) {
                return '(?P<' . $matches[1] . '>' . $matches[2] . ')';
            },
            $pattern
        );
        
        return '#^' . $pattern . '$#';
    }
    
    /**
     * Dispatch request to appropriate handler
     */
    public function dispatch(string $method, string $uri)
    {
        // Remove query string
        $uri = strtok($uri, '?');
        
        // Get routes for method
        $routes = $this->routes[$method] ?? [];
        
        // Find matching route
        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                // Run middleware stack
                return $this->runMiddleware($route, $params);
            }
        }
        
        // No route found
        return $this->handleNotFound();
    }
    
    /**
     * Run middleware stack
     */
    protected function runMiddleware(array $route, array $params)
    {
        // Combine global and route middleware
        $middleware = array_merge(
            $this->globalMiddleware,
            $route['middleware'] ?? []
        );
        
        // Create middleware stack
        $next = function ($params) use ($route) {
            return $this->callHandler($route['handler'], $params);
        };
        
        // Run middleware in reverse order
        foreach (array_reverse($middleware) as $middlewareClass) {
            $next = function ($params) use ($middlewareClass, $next) {
                $middleware = new $middlewareClass($this->app);
                return $middleware->handle($params, $next);
            };
        }
        
        return $next($params);
    }
    
    /**
     * Call route handler
     */
    protected function callHandler($handler, array $params)
    {
        if (is_callable($handler)) {
            // Closure handler
            return call_user_func_array($handler, $params);
        } elseif (is_string($handler)) {
            // Controller@method format
            list($controller, $method) = explode('@', $handler);
            
            $controller = 'App\\Controllers\\' . $controller;
            
            if (!class_exists($controller)) {
                throw new \Exception("Controller {$controller} not found");
            }
            
            $instance = new $controller($this->app);
            
            if (!method_exists($instance, $method)) {
                throw new \Exception("Method {$method} not found in {$controller}");
            }
            
            return call_user_func_array([$instance, $method], $params);
        }
        
        throw new \Exception("Invalid route handler");
    }
    
    /**
     * Handle 404 Not Found
     */
    protected function handleNotFound()
    {
        http_response_code(404);
        
        if ($this->isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => true,
                'message' => 'Route not found'
            ]);
        } else {
            include dirname(__DIR__) . '/templates/errors/404.php';
        }
    }
    
    /**
     * Generate URL from named route
     */
    public function route(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \Exception("Route '{$name}' not found");
        }
        
        $route = $this->namedRoutes[$name];
        $url = $route['path'];
        
        // Replace parameters
        foreach ($params as $key => $value) {
            $url = str_replace('{' . $key . '}', $value, $url);
        }
        
        return $url;
    }
    
    /**
     * Check if request is AJAX
     */
    protected function isAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}