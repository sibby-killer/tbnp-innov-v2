<?php
/**
 * Courier Management System Analyzer
 * This script analyzes the existing system structure, files, and dependencies
 * Run: php analyze_system.php or access via browser
 */

class SystemAnalyzer {
    private $rootPath;
    private $report = [];
    private $securityIssues = [];
    private $fileExtensions = ['php', 'html', 'js', 'css', 'sql', 'py', 'json'];
    private $ignoreDirs = ['vendor', 'node_modules', '.git', 'cache', 'logs'];
    
    public function __construct($rootPath = null) {
        $this->rootPath = $rootPath ?: dirname(__FILE__);
        $this->report['analysis_time'] = date('Y-m-d H:i:s');
        $this->report['root_path'] = $this->rootPath;
    }
    
    public function analyze() {
        echo "🔍 Starting System Analysis...\n\n";
        
        $this->analyzeDirectoryStructure();
        $this->analyzePHPFiles();
        $this->analyzeDatabase();
        $this->analyzeSecurity();
        $this->analyzeDependencies();
        $this->analyzeFrontend();
        $this->analyzeAPIs();
        $this->analyzeAIPresence();
        $this->generateReport();
        
        return $this->report;
    }
    
    private function analyzeDirectoryStructure() {
        echo "📁 Analyzing directory structure...\n";
        
        $structure = [];
        $this->scanDirectory($this->rootPath, $structure);
        
        $this->report['directory_structure'] = [
            'total_folders' => count($structure, COUNT_RECURSIVE) - count($structure),
            'total_files' => $this->countFiles($this->rootPath),
            'structure' => $structure
        ];
    }
    
    private function scanDirectory($dir, &$structure) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            $relativePath = str_replace($this->rootPath . DIRECTORY_SEPARATOR, '', $path);
            
            if (is_dir($path)) {
                if (!in_array($file, $this->ignoreDirs)) {
                    $structure[$relativePath] = [];
                    $this->scanDirectory($path, $structure[$relativePath]);
                }
            }
        }
    }
    
    private function countFiles($dir) {
        $count = 0;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) $count++;
            elseif (is_dir($path) && !in_array($file, $this->ignoreDirs)) {
                $count += $this->countFiles($path);
            }
        }
        return $count;
    }
    
    private function analyzePHPFiles() {
        echo "🐘 Analyzing PHP files...\n";
        
        $phpFiles = $this->findFilesByExtension('php');
        $frameworks = [];
        $patterns = [
            'laravel' => ['Laravel', 'Illuminate'],
            'symfony' => ['Symfony'],
            'codeigniter' => ['CodeIgniter'],
            'yii' => ['Yii'],
            'cakephp' => ['CakePHP'],
            'wordpress' => ['wp_', 'WP_'],
            'custom' => []
        ];
        
        $sessions = [];
        $includes = [];
        $database_connections = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            
            // Check for framework patterns
            foreach ($patterns as $framework => $keywords) {
                foreach ($keywords as $keyword) {
                    if (strpos($content, $keyword) !== false) {
                        $frameworks[$framework] = isset($frameworks[$framework]) ? $frameworks[$framework] + 1 : 1;
                    }
                }
            }
            
            // Check for session usage
            if (preg_match('/session_start\(\)/', $content)) {
                $sessions[] = $file;
            }
            
            // Check for includes/requires
            if (preg_match('/(include|require)(_once)?\s*\(?\s*[\'"]/', $content)) {
                $includes[] = $file;
            }
            
            // Check for database connections
            if (preg_match('/(mysqli_connect|new PDO|mysql_connect|pg_connect)/', $content)) {
                $database_connections[] = $file;
            }
        }
        
        $this->report['php_analysis'] = [
            'total_php_files' => count($phpFiles),
            'frameworks_detected' => $frameworks,
            'session_usage' => count($sessions),
            'include_usage' => count($includes),
            'database_connections' => count($database_connections),
            'sample_files' => array_slice($phpFiles, 0, 10)
        ];
    }
    
    private function analyzeDatabase() {
        echo "💾 Analyzing database configuration...\n";
        
        $sqlFiles = $this->findFilesByExtension('sql');
        $configFiles = $this->findFilesByPattern('/config|database|connection/i');
        
        $dbConfigs = [];
        $tables = [];
        $queries = [];
        
        foreach ($sqlFiles as $file) {
            $content = file_get_contents($file);
            
            // Extract table names
            preg_match_all('/CREATE\s+TABLE\s+`?(\w+)`?/i', $content, $matches);
            if (!empty($matches[1])) {
                $tables = array_merge($tables, $matches[1]);
            }
            
            // Extract queries
            preg_match_all('/(SELECT|INSERT|UPDATE|DELETE).+?;/is', $content, $queryMatches);
            if (!empty($queryMatches[0])) {
                $queries = array_merge($queries, $queryMatches[0]);
            }
        }
        
        // Look for database config in PHP files
        $phpFiles = $this->findFilesByExtension('php');
        $dbCredentials = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match('/(mysql|mysqli|pgsql|pdo).+?(\'|\")(.+?)(\'|\")/is', $content)) {
                $dbCredentials[] = $file;
            }
        }
        
        $this->report['database_analysis'] = [
            'sql_files' => count($sqlFiles),
            'config_files' => count($configFiles),
            'tables_found' => array_unique($tables),
            'total_tables' => count(array_unique($tables)),
            'potential_db_config_files' => $dbCredentials,
            'sample_queries' => array_slice($queries, 0, 5)
        ];
    }
    
    private function analyzeSecurity() {
        echo "🔒 Analyzing security implementation...\n";
        
        $phpFiles = $this->findFilesByExtension('php');
        
        $securityChecks = [
            'password_hashing' => ['password_hash', 'md5', 'sha1', 'crypt'],
            'input_validation' => ['filter_input', 'htmlspecialchars', 'strip_tags', 'mysqli_real_escape_string'],
            'sql_injection' => ['prepare', 'bind_param', 'quote'],
            'xss_protection' => ['htmlspecialchars', 'strip_tags'],
            'csrf_tokens' => ['csrf', 'token'],
            'session_security' => ['session_regenerate_id', 'session_set_save_handler'],
            'file_uploads' => ['$_FILES', 'move_uploaded_file', 'is_uploaded_file'],
            'error_reporting' => ['error_reporting', 'display_errors']
        ];
        
        $securityFindings = [];
        $vulnerabilities = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $relativeFile = str_replace($this->rootPath, '', $file);
            
            foreach ($securityChecks as $check => $patterns) {
                foreach ($patterns as $pattern) {
                    if (strpos($content, $pattern) !== false) {
                        $securityFindings[$check][] = $relativeFile;
                        break;
                    }
                }
            }
            
            // Check for dangerous functions
            $dangerous = ['eval', 'exec', 'system', 'shell_exec', 'passthru', '`'];
            foreach ($dangerous as $danger) {
                if (strpos($content, $danger . '(') !== false) {
                    $vulnerabilities[] = [
                        'file' => $relativeFile,
                        'issue' => "Dangerous function: $danger",
                        'severity' => 'HIGH'
                    ];
                }
            }
            
            // Check for hardcoded credentials
            if (preg_match('/(password|pwd|pass|secret|key)\s*=\s*[\'"][^\'"]+[\'"]/i', $content)) {
                $vulnerabilities[] = [
                    'file' => $relativeFile,
                    'issue' => 'Possible hardcoded credentials',
                    'severity' => 'MEDIUM'
                ];
            }
        }
        
        $this->report['security_analysis'] = [
            'security_features' => $securityFindings,
            'vulnerabilities' => $vulnerabilities,
            'risk_level' => $this->calculateRiskLevel($vulnerabilities),
            'recommendations' => $this->generateSecurityRecommendations($securityFindings)
        ];
    }
    
    private function analyzeDependencies() {
        echo "📦 Analyzing dependencies...\n";
        
        $composerFiles = $this->findFilesByName('composer.json');
        $packageFiles = $this->findFilesByName('package.json');
        
        $dependencies = [
            'php' => [],
            'js' => [],
            'other' => []
        ];
        
        foreach ($composerFiles as $file) {
            $content = file_get_contents($file);
            $json = json_decode($content, true);
            if ($json && isset($json['require'])) {
                $dependencies['php'] = array_merge($dependencies['php'], $json['require']);
            }
        }
        
        foreach ($packageFiles as $file) {
            $content = file_get_contents($file);
            $json = json_decode($content, true);
            if ($json && isset($json['dependencies'])) {
                $dependencies['js'] = array_merge($dependencies['js'], $json['dependencies']);
            }
        }
        
        $this->report['dependencies'] = $dependencies;
    }
    
    private function analyzeFrontend() {
        echo "🎨 Analyzing frontend...\n";
        
        $htmlFiles = $this->findFilesByExtension('html');
        $cssFiles = $this->findFilesByExtension('css');
        $jsFiles = $this->findFilesByExtension('js');
        
        $frameworks = [];
        $libraries = [];
        
        foreach ($htmlFiles as $file) {
            $content = file_get_contents($file);
            
            // Detect frameworks
            if (strpos($content, 'bootstrap') !== false) $frameworks['bootstrap'] = true;
            if (strpos($content, 'tailwind') !== false) $frameworks['tailwind'] = true;
            if (strpos($content, 'jquery') !== false) $libraries['jquery'] = true;
            if (strpos($content, 'react') !== false) $frameworks['react'] = true;
            if (strpos($content, 'vue') !== false) $frameworks['vue'] = true;
            if (strpos($content, 'angular') !== false) $frameworks['angular'] = true;
        }
        
        $this->report['frontend_analysis'] = [
            'html_files' => count($htmlFiles),
            'css_files' => count($cssFiles),
            'js_files' => count($jsFiles),
            'frameworks_detected' => array_keys($frameworks),
            'libraries_detected' => array_keys($libraries),
            'has_responsive_design' => $this->checkResponsiveDesign($htmlFiles)
        ];
    }
    
    private function analyzeAPIs() {
        echo "🔌 Analyzing API endpoints...\n";
        
        $phpFiles = $this->findFilesByExtension('php');
        $apiEndpoints = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $relativeFile = str_replace($this->rootPath, '', $file);
            
            // Look for API patterns
            if (preg_match_all('/(?:api|rest|endpoint|ajax).*?\.php/i', $relativeFile, $matches)) {
                $apiEndpoints[] = $relativeFile;
            }
            
            // Look for JSON responses
            if (strpos($content, 'json_encode') !== false || 
                strpos($content, 'application/json') !== false ||
                strpos($content, 'header.*application/json') !== false) {
                if (!in_array($relativeFile, $apiEndpoints)) {
                    $apiEndpoints[] = $relativeFile;
                }
            }
            
            // Look for REST patterns
            if (preg_match('/method.*(GET|POST|PUT|DELETE)/i', $content)) {
                if (!in_array($relativeFile, $apiEndpoints)) {
                    $apiEndpoints[] = $relativeFile;
                }
            }
        }
        
        $this->report['api_analysis'] = [
            'potential_api_endpoints' => $apiEndpoints,
            'total_endpoints' => count($apiEndpoints),
            'has_rest_api' => count($apiEndpoints) > 0
        ];
    }
    
    private function analyzeAIPresence() {
        echo "🤖 Analyzing AI/ML components...\n";
        
        $pythonFiles = $this->findFilesByExtension('py');
        $aiFiles = [];
        $mlLibraries = [];
        
        foreach ($pythonFiles as $file) {
            $content = file_get_contents($file);
            $relativeFile = str_replace($this->rootPath, '', $file);
            
            // Check for AI/ML libraries
            $aiKeywords = [
                'tensorflow', 'keras', 'pytorch', 'scikit-learn', 'sklearn',
                'numpy', 'pandas', 'matplotlib', 'seaborn', 'opencv',
                'nltk', 'spacy', 'transformers', 'langchain', 'llama',
                'gpt', 'bert', 'roberta', 'xgboost', 'lightgbm'
            ];
            
            foreach ($aiKeywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    $mlLibraries[$keyword] = true;
                    if (!in_array($relativeFile, $aiFiles)) {
                        $aiFiles[] = $relativeFile;
                    }
                }
            }
            
            // Check for model files
            if (strpos($content, '.h5') !== false || 
                strpos($content, '.pkl') !== false ||
                strpos($content, '.joblib') !== false ||
                strpos($content, '.pt') !== false) {
                $aiFiles[] = $relativeFile;
            }
        }
        
        // Look for model files
        $modelFiles = array_merge(
            $this->findFilesByExtension('h5'),
            $this->findFilesByExtension('pkl'),
            $this->findFilesByExtension('joblib'),
            $this->findFilesByExtension('pt'),
            $this->findFilesByExtension('onnx')
        );
        
        $this->report['ai_analysis'] = [
            'python_files' => count($pythonFiles),
            'ai_related_files' => array_unique($aiFiles),
            'ml_libraries_detected' => array_keys($mlLibraries),
            'model_files' => $modelFiles,
            'has_ai_components' => count($pythonFiles) > 0 || count($modelFiles) > 0,
            'ai_readiness' => $this->assessAIReadiness($pythonFiles, $modelFiles)
        ];
    }
    
    private function findFilesByExtension($extension) {
        return $this->findFiles($this->rootPath, function($file) use ($extension) {
            return pathinfo($file, PATHINFO_EXTENSION) === $extension;
        });
    }
    
    private function findFilesByName($name) {
        return $this->findFiles($this->rootPath, function($file) use ($name) {
            return basename($file) === $name;
        });
    }
    
    private function findFilesByPattern($pattern) {
        return $this->findFiles($this->rootPath, function($file) use ($pattern) {
            return preg_match($pattern, $file);
        });
    }
    
    private function findFiles($dir, $callback) {
        $results = [];
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path) && !in_array($file, $this->ignoreDirs)) {
                $results = array_merge($results, $this->findFiles($path, $callback));
            } elseif (is_file($path) && $callback($path)) {
                $results[] = $path;
            }
        }
        
        return $results;
    }
    
    private function checkResponsiveDesign($htmlFiles) {
        foreach ($htmlFiles as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'viewport') !== false && 
                strpos($content, '@media') !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function calculateRiskLevel($vulnerabilities) {
        $highCount = count(array_filter($vulnerabilities, function($v) {
            return $v['severity'] === 'HIGH';
        }));
        
        if ($highCount > 5) return 'CRITICAL';
        if ($highCount > 2) return 'HIGH';
        if ($highCount > 0) return 'MEDIUM';
        return 'LOW';
    }
    
    private function generateSecurityRecommendations($findings) {
        $recommendations = [];
        
        if (!isset($findings['password_hashing']) || 
            !in_array('password_hash', $findings['password_hashing'])) {
            $recommendations[] = 'Use password_hash() for secure password storage';
        }
        
        if (!isset($findings['sql_injection']) || 
            !in_array('prepare', $findings['sql_injection'])) {
            $recommendations[] = 'Implement prepared statements to prevent SQL injection';
        }
        
        if (!isset($findings['csrf_tokens'])) {
            $recommendations[] = 'Implement CSRF protection for forms';
        }
        
        if (!isset($findings['session_security'])) {
            $recommendations[] = 'Enhance session security with regeneration and proper configuration';
        }
        
        return $recommendations;
    }
    
    private function assessAIReadiness($pythonFiles, $modelFiles) {
        $score = 0;
        
        if (count($pythonFiles) > 0) $score += 20;
        if (count($modelFiles) > 0) $score += 30;
        if ($this->hasAIDependencies()) $score += 25;
        if ($this->hasDataProcessing()) $score += 25;
        
        return [
            'score' => $score,
            'level' => $score >= 70 ? 'ADVANCED' : ($score >= 40 ? 'INTERMEDIATE' : 'BASIC'),
            'needs_setup' => $score < 40
        ];
    }
    
    private function hasAIDependencies() {
        $composerFiles = $this->findFilesByName('composer.json');
        foreach ($composerFiles as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'php-ml') !== false || 
                strpos($content, 'rubix') !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function hasDataProcessing() {
        $phpFiles = $this->findFilesByExtension('php');
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'array_map') !== false ||
                strpos($content, 'array_filter') !== false ||
                strpos($content, 'usort') !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function generateReport() {
        echo "\n📊 Generating Analysis Report...\n";
        
        // Generate HTML report
        $html = $this->generateHTMLReport();
        file_put_contents($this->rootPath . '/system_analysis_report.html', $html);
        
        // Generate JSON report
        $json = json_encode($this->report, JSON_PRETTY_PRINT);
        file_put_contents($this->rootPath . '/system_analysis_report.json', $json);
        
        echo "\n✅ Analysis Complete!\n";
        echo "📄 Report saved to: system_analysis_report.html\n";
        echo "📄 JSON data saved to: system_analysis_report.json\n";
    }
    
    private function generateHTMLReport() {
        $report = $this->report;
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>System Analysis Report - Courier Management System</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: #f5f5f5;
                    padding: 20px;
                    line-height: 1.6;
                }
                
                .container {
                    max-width: 1200px;
                    margin: 0 auto;
                }
                
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                }
                
                .header h1 {
                    font-size: 2.5em;
                    margin-bottom: 10px;
                }
                
                .header .timestamp {
                    opacity: 0.9;
                    font-size: 0.9em;
                }
                
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                
                .stat-card {
                    background: white;
                    padding: 20px;
                    border-radius: 10px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                
                .stat-card h3 {
                    color: #666;
                    font-size: 0.9em;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    margin-bottom: 10px;
                }
                
                .stat-card .value {
                    font-size: 2.5em;
                    font-weight: bold;
                    color: #333;
                }
                
                .section {
                    background: white;
                    padding: 25px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                
                .section h2 {
                    color: #333;
                    margin-bottom: 20px;
                    padding-bottom: 10px;
                    border-bottom: 2px solid #f0f0f0;
                }
                
                .section h3 {
                    color: #666;
                    margin: 15px 0 10px;
                }
                
                .badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 4px;
                    font-size: 0.8em;
                    font-weight: 500;
                }
                
                .badge.success { background: #d4edda; color: #155724; }
                .badge.warning { background: #fff3cd; color: #856404; }
                .badge.danger { background: #f8d7da; color: #721c24; }
                .badge.info { background: #d1ecf1; color: #0c5460; }
                
                .risk-meter {
                    height: 20px;
                    background: #e9ecef;
                    border-radius: 10px;
                    margin: 10px 0;
                    overflow: hidden;
                }
                
                .risk-fill {
                    height: 100%;
                    background: linear-gradient(90deg, #28a745, #ffc107, #dc3545);
                    width: <?php echo $report['security_analysis']['risk_level'] === 'CRITICAL' ? '100%' : 
                        ($report['security_analysis']['risk_level'] === 'HIGH' ? '75%' : 
                        ($report['security_analysis']['risk_level'] === 'MEDIUM' ? '50%' : '25%')); ?>;
                }
                
                .vulnerability-item {
                    padding: 10px;
                    margin: 5px 0;
                    border-radius: 5px;
                    background: #f8f9fa;
                    border-left: 4px solid;
                }
                
                .vulnerability-item.high { border-color: #dc3545; }
                .vulnerability-item.medium { border-color: #ffc107; }
                .vulnerability-item.low { border-color: #28a745; }
                
                .file-list {
                    max-height: 200px;
                    overflow-y: auto;
                    background: #f8f9fa;
                    padding: 10px;
                    border-radius: 5px;
                    font-family: monospace;
                    font-size: 0.9em;
                }
                
                .file-list div {
                    padding: 2px 0;
                    border-bottom: 1px solid #e9ecef;
                }
                
                .metric-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 10px;
                    margin-top: 10px;
                }
                
                .metric {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 8px;
                    text-align: center;
                }
                
                .metric .label {
                    color: #666;
                    font-size: 0.8em;
                }
                
                .metric .value {
                    font-size: 1.5em;
                    font-weight: bold;
                    color: #333;
                }
                
                .recommendation {
                    background: #e7f3ff;
                    padding: 15px;
                    border-radius: 8px;
                    margin: 10px 0;
                    border-left: 4px solid #007bff;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📊 Courier Management System Analysis Report</h1>
                    <p class="timestamp">Analysis Time: <?php echo $report['analysis_time']; ?></p>
                    <p class="timestamp">Root Path: <?php echo $report['root_path']; ?></p>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Total Files</h3>
                        <div class="value"><?php echo $report['directory_structure']['total_files']; ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>PHP Files</h3>
                        <div class="value"><?php echo $report['php_analysis']['total_php_files']; ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Risk Level</h3>
                        <div class="value">
                            <span class="badge <?php 
                                echo $report['security_analysis']['risk_level'] === 'CRITICAL' ? 'danger' : 
                                    ($report['security_analysis']['risk_level'] === 'HIGH' ? 'warning' : 
                                    ($report['security_analysis']['risk_level'] === 'MEDIUM' ? 'warning' : 'success')); 
                            ?>">
                                <?php echo $report['security_analysis']['risk_level']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>AI Readiness</h3>
                        <div class="value">
                            <span class="badge <?php echo $report['ai_analysis']['ai_readiness']['level'] === 'ADVANCED' ? 'success' : 
                                ($report['ai_analysis']['ai_readiness']['level'] === 'INTERMEDIATE' ? 'info' : 'warning'); ?>">
                                <?php echo $report['ai_analysis']['ai_readiness']['level']; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h2>📁 Directory Structure</h2>
                    <div class="metric-grid">
                        <div class="metric">
                            <div class="label">Total Folders</div>
                            <div class="value"><?php echo $report['directory_structure']['total_folders']; ?></div>
                        </div>
                        <div class="metric">
                            <div class="label">Total Files</div>
                            <div class="value"><?php echo $report['directory_structure']['total_files']; ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h2>🐘 PHP Analysis</h2>
                    <div class="metric-grid">
                        <div class="metric">
                            <div class="label">PHP Files</div>
                            <div class="value"><?php echo $report['php_analysis']['total_php_files']; ?></div>
                        </div>
                        <div class="metric">
                            <div class="label">Sessions Used</div>
                            <div class="value"><?php echo $report['php_analysis']['session_usage']; ?></div>
                        </div>
                        <div class="metric">
                            <div class="label">DB Connections</div>
                            <div class="value"><?php echo $report['php_analysis']['database_connections']; ?></div>
                        </div>
                    </div>
                    
                    <?php if (!empty($report['php_analysis']['frameworks_detected'])): ?>
                    <h3>Frameworks Detected</h3>
                    <ul>
                        <?php foreach ($report['php_analysis']['frameworks_detected'] as $framework => $count): ?>
                        <li><?php echo ucfirst($framework); ?> (<?php echo $count; ?> files)</li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                
                <div class="section">
                    <h2>🔒 Security Analysis</h2>
                    
                    <div class="risk-meter">
                        <div class="risk-fill"></div>
                    </div>
                    <p>Risk Level: <strong><?php echo $report['security_analysis']['risk_level']; ?></strong></p>
                    
                    <?php if (!empty($report['security_analysis']['vulnerabilities'])): ?>
                    <h3>⚠️ Vulnerabilities Found</h3>
                    <?php foreach ($report['security_analysis']['vulnerabilities'] as $vuln): ?>
                    <div class="vulnerability-item <?php echo strtolower($vuln['severity']); ?>">
                        <strong><?php echo $vuln['severity']; ?>:</strong> 
                        <?php echo $vuln['issue']; ?> in <?php echo $vuln['file']; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (!empty($report['security_analysis']['recommendations'])): ?>
                    <h3>💡 Recommendations</h3>
                    <?php foreach ($report['security_analysis']['recommendations'] as $rec): ?>
                    <div class="recommendation">
                        <?php echo $rec; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="section">
                    <h2>💾 Database Analysis</h2>
                    
                    <?php if (!empty($report['database_analysis']['tables_found'])): ?>
                    <h3>Tables Found (<?php echo $report['database_analysis']['total_tables']; ?>)</h3>
                    <div class="file-list">
                        <?php foreach ($report['database_analysis']['tables_found'] as $table): ?>
                        <div>📊 <?php echo $table; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="section">
                    <h2>🔌 API Analysis</h2>
                    <p>Total API Endpoints: <?php echo $report['api_analysis']['total_endpoints']; ?></p>
                    
                    <?php if (!empty($report['api_analysis']['potential_api_endpoints'])): ?>
                    <h3>Potential API Files</h3>
                    <div class="file-list">
                        <?php foreach (array_slice($report['api_analysis']['potential_api_endpoints'], 0, 10) as $endpoint): ?>
                        <div>🔗 <?php echo $endpoint; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="section">
                    <h2>🤖 AI/ML Analysis</h2>
                    
                    <div class="metric-grid">
                        <div class="metric">
                            <div class="label">Python Files</div>
                            <div class="value"><?php echo $report['ai_analysis']['python_files']; ?></div>
                        </div>
                        <div class="metric">
                            <div class="label">Model Files</div>
                            <div class="value"><?php echo count($report['ai_analysis']['model_files']); ?></div>
                        </div>
                        <div class="metric">
                            <div class="label">AI Score</div>
                            <div class="value"><?php echo $report['ai_analysis']['ai_readiness']['score']; ?>%</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($report['ai_analysis']['ml_libraries_detected'])): ?>
                    <h3>ML Libraries Detected</h3>
                    <div class="file-list">
                        <?php foreach ($report['ai_analysis']['ml_libraries_detected'] as $lib): ?>
                        <div>🤖 <?php echo $lib; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($report['ai_analysis']['ai_readiness']['needs_setup']): ?>
                    <div class="recommendation">
                        <strong>⚠️ AI Setup Required:</strong> Your system needs AI/ML components setup. Consider adding Python-based AI services.
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="section">
                    <h2>📦 Dependencies</h2>
                    
                    <?php if (!empty($report['dependencies']['php'])): ?>
                    <h3>PHP Dependencies</h3>
                    <div class="file-list">
                        <?php foreach ($report['dependencies']['php'] as $pkg => $ver): ?>
                        <div>📦 <?php echo $pkg; ?>: <?php echo $ver; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($report['dependencies']['js'])): ?>
                    <h3>JavaScript Dependencies</h3>
                    <div class="file-list">
                        <?php foreach ($report['dependencies']['js'] as $pkg => $ver): ?>
                        <div>📦 <?php echo $pkg; ?>: <?php echo $ver; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <script>
                // Add any interactive features here
                console.log('Analysis Report Loaded');
            </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

// Run the analyzer
$analyzer = new SystemAnalyzer();
$report = $analyzer->analyze();

// Display summary in console
echo "\n📋 SYSTEM ANALYSIS SUMMARY:\n";
echo "==========================\n";
echo "Total Files: " . $report['directory_structure']['total_files'] . "\n";
echo "PHP Files: " . $report['php_analysis']['total_php_files'] . "\n";
echo "Security Risk: " . $report['security_analysis']['risk_level'] . "\n";
echo "AI Readiness: " . $report['ai_analysis']['ai_readiness']['level'] . " (" . $report['ai_analysis']['ai_readiness']['score'] . "%)\n";
echo "Database Tables: " . $report['database_analysis']['total_tables'] . "\n";
echo "API Endpoints: " . $report['api_analysis']['total_endpoints'] . "\n";
echo "==========================\n";