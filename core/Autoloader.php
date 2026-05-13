<?php
/**
 * PSR-4 Autoloader with namespace mapping
 * 
 * @package Core
 */

namespace Core;

class Autoloader
{
    /**
     * @var array Namespace mappings
     */
    protected static $namespaces = [];
    
    /**
     * @var array Class mappings for performance
     */
    protected static $classMap = [];
    
    /**
     * @var bool Whether to use class map
     */
    protected static $useClassMap = true;
    
    /**
     * Register the autoloader
     */
    public static function register(): void
    {
        spl_autoload_register([__CLASS__, 'loadClass'], true, true);
    }
    
    /**
     * Add namespace mapping
     * 
     * @param string $namespace
     * @param string|array $path
     * @param bool $prepend
     */
    public static function addNamespace(string $namespace, $path, bool $prepend = false): void
    {
        $namespace = trim($namespace, '\\') . '\\';
        
        if (!isset(self::$namespaces[$namespace])) {
            self::$namespaces[$namespace] = [];
        }
        
        $paths = (array) $path;
        
        if ($prepend) {
            self::$namespaces[$namespace] = array_merge($paths, self::$namespaces[$namespace]);
        } else {
            self::$namespaces[$namespace] = array_merge(self::$namespaces[$namespace], $paths);
        }
    }
    
    /**
     * Add class map for performance
     * 
     * @param array $classMap
     */
    public static function addClassMap(array $classMap): void
    {
        self::$classMap = array_merge(self::$classMap, $classMap);
    }
    
    /**
     * Load class file
     * 
     * @param string $class
     * @return bool
     */
    public static function loadClass(string $class): bool
    {
        // Check class map first for performance
        if (self::$useClassMap && isset(self::$classMap[$class])) {
            require self::$classMap[$class];
            return true;
        }
        
        // Try namespace mapping
        $file = self::findFile($class);
        
        if ($file && file_exists($file)) {
            require $file;
            return true;
        }
        
        return false;
    }
    
    /**
     * Find file for class using namespace mapping
     * 
     * @param string $class
     * @return string|null
     */
    protected static function findFile(string $class): ?string
    {
        // Work through each registered namespace
        foreach (self::$namespaces as $namespace => $paths) {
            if (strpos($class, $namespace) === 0) {
                $className = substr($class, strlen($namespace));
                
                // Convert namespace separators to directory separators
                $className = str_replace('\\', DIRECTORY_SEPARATOR, $className);
                
                // Try each path
                foreach ($paths as $path) {
                    $file = $path . DIRECTORY_SEPARATOR . $className . '.php';
                    
                    if (file_exists($file)) {
                        return $file;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Generate class map for performance
     * 
     * @param array $directories
     * @param string $basePath
     * @return array
     */
    public static function generateClassMap(array $directories, string $basePath = ''): array
    {
        $classMap = [];
        
        foreach ($directories as $namespace => $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace($basePath, '', $file->getPathname());
                    $className = $namespace . str_replace(
                        [DIRECTORY_SEPARATOR, '.php'],
                        ['\\', ''],
                        substr($relativePath, 1)
                    );
                    
                    $classMap[$className] = $file->getPathname();
                }
            }
        }
        
        return $classMap;
    }
}