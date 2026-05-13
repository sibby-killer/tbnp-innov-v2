<?php
/**
 * Input Sanitizer - Validates and sanitizes all user input
 * Uses your MAX_FILE_SIZE, ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES constants
 * 
 * @package Core\Security
 * @version 1.0.0
 */

namespace Core\Security;

use Core\Security\Exceptions\SecurityException;

class InputSanitizer
{
    /**
     * @var SecurityLogger Logger instance
     */
    private SecurityLogger $logger;
    
    /**
     * @var array Sanitization rules
     */
    private array $rules = [];
    
    /**
     * @var array Validation errors
     */
    private array $errors = [];
    
    /**
     * @var array Cleaned data
     */
    private array $cleaned = [];
    
    /**
     * @var array Raw input data
     */
    private array $input = [];
    
    /**
     * Constructor
     * 
     * @param SecurityLogger $logger
     */
    public function __construct(SecurityLogger $logger)
    {
        $this->logger = $logger;
        $this->initDefaultRules();
    }
    
    /**
     * Initialize default sanitization rules
     */
    private function initDefaultRules(): void
    {
        $this->rules = [
            'string' => [
                'filter' => FILTER_SANITIZE_STRING,
                'options' => ['flags' => FILTER_FLAG_NO_ENCODE_QUOTES],
                'callback' => [$this, 'sanitizeString']
            ],
            'email' => [
                'filter' => FILTER_SANITIZE_EMAIL,
                'validate' => FILTER_VALIDATE_EMAIL
            ],
            'url' => [
                'filter' => FILTER_SANITIZE_URL,
                'validate' => FILTER_VALIDATE_URL
            ],
            'int' => [
                'filter' => FILTER_SANITIZE_NUMBER_INT,
                'validate' => FILTER_VALIDATE_INT
            ],
            'float' => [
                'filter' => FILTER_SANITIZE_NUMBER_FLOAT,
                'options' => ['flags' => FILTER_FLAG_ALLOW_FRACTION],
                'validate' => FILTER_VALIDATE_FLOAT
            ],
            'phone' => [
                'callback' => [$this, 'sanitizePhone']
            ],
            'name' => [
                'callback' => [$this, 'sanitizeName']
            ],
            'address' => [
                'callback' => [$this, 'sanitizeAddress']
            ],
            'textarea' => [
                'callback' => [$this, 'sanitizeTextarea']
            ],
            'html' => [
                'callback' => [$this, 'sanitizeHtml']
            ],
            'filename' => [
                'callback' => [$this, 'sanitizeFilename']
            ],
            'date' => [
                'callback' => [$this, 'validateDate']
            ],
            'time' => [
                'callback' => [$this, 'validateTime']
            ],
            'boolean' => [
                'callback' => [$this, 'sanitizeBoolean']
            ]
        ];
    }
    
    /**
     * Add custom sanitization rule
     * 
     * @param string $name Rule name
     * @param callable $callback Sanitization callback
     */
    public function addRule(string $name, callable $callback): void
    {
        $this->rules[$name] = ['callback' => $callback];
    }
    
    /**
     * Sanitize a single value
     * 
     * @param mixed $value Value to sanitize
     * @param string $type Expected type
     * @param array $options Additional options
     * @return mixed Sanitized value
     * @throws SecurityException
     */
    public function sanitize($value, string $type = 'string', array $options = [])
    {
        if ($value === null || $value === '') {
            return $options['default'] ?? null;
        }
        
        if (!isset($this->rules[$type])) {
            throw new SecurityException("Unknown sanitization type: {$type}");
        }
        
        $rule = $this->rules[$type];
        
        try {
            // Apply callback if exists
            if (isset($rule['callback'])) {
                $value = $rule['callback']($value, $options);
            }
            
            // Apply filter if exists
            if (isset($rule['filter'])) {
                $value = filter_var($value, $rule['filter'], $rule['options'] ?? []);
            }
            
            // Validate if requested
            if (isset($options['validate']) && $options['validate'] && isset($rule['validate'])) {
                if (!filter_var($value, $rule['validate'])) {
                    throw new SecurityException("Validation failed for type: {$type}");
                }
            }
            
            return $value;
            
        } catch (\Exception $e) {
            $this->logger->logInput('sanitization_failed', [
                'type' => $type,
                'error' => $e->getMessage()
            ], SecurityLogger::LEVEL_WARNING);
            
            throw new SecurityException("Failed to sanitize input: {$e->getMessage()}");
        }
    }
    
    /**
     * Sanitize entire input array based on rules
     * 
     * @param array $input Raw input data
     * @param array $rules Validation rules
     * @return array Cleaned data
     * @throws SecurityException
     */
    public function sanitizeArray(array $input, array $rules): array
    {
        $this->input = $input;
        $this->errors = [];
        $this->cleaned = [];
        
        foreach ($rules as $field => $rule) {
            // Parse rule
            if (is_string($rule)) {
                $rule = ['type' => $rule];
            }
            
            $type = $rule['type'] ?? 'string';
            $required = $rule['required'] ?? false;
            $default = $rule['default'] ?? null;
            $min = $rule['min'] ?? null;
            $max = $rule['max'] ?? null;
            $pattern = $rule['pattern'] ?? null;
            $options = $rule['options'] ?? [];
            
            // Get value
            $value = $input[$field] ?? ($required ? null : $default);
            
            // Check required
            if ($required && ($value === null || $value === '')) {
                $this->addError($field, 'required', "{$field} is required");
                continue;
            }
            
            // Skip if empty and not required
            if ($value === null || $value === '') {
                $this->cleaned[$field] = $default;
                continue;
            }
            
            try {
                // Sanitize based on type
                $cleaned = $this->sanitize($value, $type, $options);
                
                // Validate length/size
                if ($min !== null && $this->getLength($cleaned) < $min) {
                    $this->addError($field, 'min', "{$field} must be at least {$min} characters");
                    continue;
                }
                
                if ($max !== null && $this->getLength($cleaned) > $max) {
                    $this->addError($field, 'max', "{$field} must not exceed {$max} characters");
                    continue;
                }
                
                // Validate pattern
                if ($pattern !== null && !preg_match($pattern, $cleaned)) {
                    $this->addError($field, 'pattern', "{$field} format is invalid");
                    continue;
                }
                
                $this->cleaned[$field] = $cleaned;
                
            } catch (SecurityException $e) {
                $this->addError($field, 'invalid', $e->getMessage());
            }
        }
        
        // Log validation results
        if (!empty($this->errors)) {
            $this->logger->logInput('validation_failed', [
                'error_count' => count($this->errors),
                'fields' => array_keys($this->errors)
            ], SecurityLogger::LEVEL_WARNING);
        }
        
        return $this->cleaned;
    }
    
    /**
     * Get validation errors
     * 
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Check if validation passed
     * 
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }
    
    /**
     * Add validation error
     * 
     * @param string $field
     * @param string $rule
     * @param string $message
     */
    private function addError(string $field, string $rule, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        
        $this->errors[$field][$rule] = $message;
    }
    
    /**
     * Get length of value (works for strings and arrays)
     * 
     * @param mixed $value
     * @return int
     */
    private function getLength($value): int
    {
        if (is_string($value)) {
            return mb_strlen($value);
        }
        
        if (is_array($value)) {
            return count($value);
        }
        
        return 0;
    }
    
    /**
     * Sanitize string - XSS protection
     * 
     * @param string $value
     * @param array $options
     * @return string
     */
    private function sanitizeString(string $value, array $options = []): string
    {
        $value = trim($value);
        
        // Remove null bytes
        $value = str_replace(chr(0), '', $value);
        
        // HTML escape by default
        if (!($options['allow_html'] ?? false)) {
            $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        // Remove control characters
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        
        return $value;
    }
    
    /**
     * Sanitize name (personal names, company names)
     * 
     * @param string $value
     * @return string
     */
    private function sanitizeName(string $value): string
    {
        $value = trim($value);
        
        // Remove multiple spaces
        $value = preg_replace('/\s+/', ' ', $value);
        
        // Allow letters, spaces, dots, hyphens, apostrophes
        $value = preg_replace('/[^a-zA-Z\s\.\-\'\p{L}]/u', '', $value);
        
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize address
     * 
     * @param string $value
     * @return string
     */
    private function sanitizeAddress(string $value): string
    {
        $value = trim($value);
        
        // Remove multiple spaces
        $value = preg_replace('/\s+/', ' ', $value);
        
        // Allow letters, numbers, spaces, commas, dots, hyphens, #, /
        $value = preg_replace('/[^a-zA-Z0-9\s\,\.\-\#\/\p{L}]/u', '', $value);
        
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize textarea content
     * 
     * @param string $value
     * @param array $options
     * @return string
     */
    private function sanitizeTextarea(string $value, array $options = []): string
    {
        $value = trim($value);
        
        // Convert line breaks
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        
        // Remove multiple blank lines
        if ($options['compact'] ?? false) {
            $value = preg_replace("/\n\s*\n/", "\n\n", $value);
        }
        
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize HTML content safely
     * 
     * @param string $value
     * @return string
     */
    private function sanitizeHtml(string $value): string
    {
        $value = trim($value);
        
        // If HTML Purifier is available, use it
        if (class_exists('HTMLPurifier')) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'p,br,strong,em,u,ul,ol,li,a[href],img[src|alt]');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
            $config->set('HTML.TargetBlank', true);
            
            $purifier = new \HTMLPurifier($config);
            return $purifier->purify($value);
        }
        
        // Fallback to strip_tags
        return strip_tags($value, '<p><br><strong><em><u><ul><ol><li><a><img>');
    }
    
    /**
     * Sanitize phone number
     * 
     * @param string $value
     * @return string
     */
    private function sanitizePhone(string $value): string
    {
        // Remove all non-numeric except +
        $value = preg_replace('/[^0-9+]/', '', $value);
        
        // Ensure + is only at start
        if (strpos($value, '+') > 0) {
            $value = str_replace('+', '', $value);
        }
        
        // Limit length
        return substr($value, 0, 15);
    }
    
    /**
     * Sanitize filename for uploads
     * 
     * @param string $value
     * @return string
     */
    private function sanitizeFilename(string $value): string
    {
        // Remove directory traversal attempts
        $value = str_replace(['../', '..\\', './', '.\\'], '', $value);
        
        // Remove special characters
        $value = preg_replace('/[^a-zA-Z0-9._-]/', '', $value);
        
        // Remove multiple dots
        $value = preg_replace('/\.{2,}/', '.', $value);
        
        // Limit length
        $value = substr($value, 0, 255);
        
        return $value;
    }
    
    /**
     * Sanitize boolean
     * 
     * @param mixed $value
     * @return bool
     */
    private function sanitizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower($value);
            return in_array($value, ['1', 'on', 'yes', 'true', 'y'], true);
        }
        
        return (bool) $value;
    }
    
    /**
     * Validate date
     * 
     * @param string $value
     * @param array $options
     * @return string
     * @throws SecurityException
     */
    private function validateDate(string $value, array $options = []): string
    {
        $format = $options['format'] ?? 'Y-m-d';
        
        $date = \DateTime::createFromFormat($format, $value);
        
        if (!$date || $date->format($format) !== $value) {
            throw new SecurityException("Invalid date format. Expected: {$format}");
        }
        
        return $value;
    }
    
    /**
     * Validate time
     * 
     * @param string $value
     * @param array $options
     * @return string
     * @throws SecurityException
     */
    private function validateTime(string $value, array $options = []): string
    {
        $format = $options['format'] ?? 'H:i';
        
        $time = \DateTime::createFromFormat($format, $value);
        
        if (!$time || $time->format($format) !== $value) {
            throw new SecurityException("Invalid time format. Expected: {$format}");
        }
        
        return $value;
    }
    
    /**
     * Validate file upload
     * 
     * @param array $file $_FILES array element
     * @param array $options Validation options
     * @return array Validated file info
     * @throws SecurityException
     */
    public function validateFileUpload(array $file, array $options = []): array
    {
        $errors = [];
        
        // Check upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            
            throw new SecurityException(
                $uploadErrors[$file['error']] ?? 'Unknown upload error',
                ['upload_error' => $file['error']]
            );
        }
        
        // Check file size (using your constant)
        $maxSize = $options['max_size'] ?? MAX_FILE_SIZE;
        if ($file['size'] > $maxSize) {
            throw SecurityException::fileUploadViolation(
                $file['name'],
                "File size exceeds maximum allowed (" . round($maxSize / 1024 / 1024, 2) . "MB)"
            );
        }
        
        // Get file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check file type based on category
        $type = $options['type'] ?? 'image';
        
        if ($type === 'image') {
            $allowedTypes = ALLOWED_IMAGE_TYPES;
            
            // Verify image
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                throw SecurityException::fileUploadViolation($file['name'], 'Invalid image file');
            }
            
            // Check image dimensions
            if (isset($options['max_width']) && $imageInfo[0] > $options['max_width']) {
                throw SecurityException::fileUploadViolation($file['name'], "Image width exceeds maximum");
            }
            
            if (isset($options['max_height']) && $imageInfo[1] > $options['max_height']) {
                throw SecurityException::fileUploadViolation($file['name'], "Image height exceeds maximum");
            }
            
        } elseif ($type === 'document') {
            $allowedTypes = ALLOWED_DOC_TYPES;
            
            // Check for PHP in documents
            $content = file_get_contents($file['tmp_name']);
            if (preg_match('/<\?php/i', $content)) {
                throw SecurityException::fileUploadViolation($file['name'], 'File contains suspicious code');
            }
        } else {
            $allowedTypes = $options['allowed_types'] ?? [];
        }
        
        // Check extension
        if (!in_array($extension, $allowedTypes)) {
            throw SecurityException::fileUploadViolation(
                $file['name'],
                "File type not allowed. Allowed: " . implode(', ', $allowedTypes)
            );
        }
        
        // Generate safe filename
        $safeFilename = $this->sanitizeFilename(
            $options['filename'] ?? pathinfo($file['name'], PATHINFO_FILENAME)
        );
        
        return [
            'original_name' => $file['name'],
            'safe_name' => $safeFilename . '.' . $extension,
            'extension' => $extension,
            'size' => $file['size'],
            'mime_type' => $file['type'],
            'tmp_name' => $file['tmp_name'],
            'width' => $imageInfo[0] ?? null,
            'height' => $imageInfo[1] ?? null
        ];
    }
    
    /**
     * Sanitize SQL parameter to prevent injection
     * 
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    public function sanitizeForSql($value, string $type = 'string')
    {
        // Use prepared statements instead of manual escaping
        // This is just an additional safety layer
        
        if ($value === null) {
            return null;
        }
        
        switch ($type) {
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'bool':
                return (bool) $value;
            case 'string':
            default:
                // Remove null bytes
                $value = str_replace(chr(0), '', $value);
                
                // For MySQL, use quote method when needed
                // But always prefer prepared statements
                return $value;
        }
    }
    
    /**
     * Get cleaned data with original keys
     * 
     * @return array
     */
    public function getCleaned(): array
    {
        return $this->cleaned;
    }
    
    /**
     * Get original input
     * 
     * @return array
     */
    public function getInput(): array
    {
        return $this->input;
    }
    
    /**
     * Get single cleaned value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->cleaned[$key] ?? $default;
    }
}