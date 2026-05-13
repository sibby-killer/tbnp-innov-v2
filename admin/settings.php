<?php
// settings.php - Admin Settings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is admin
if ($_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: ../index.php');
    exit();
}

$pageTitle = "System Settings - Admin Dashboard";
$error_message = '';
$success_message = '';

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    switch ($action) {
        case 'optimize_db':
            try {
                $stmt = $db->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($tables as $table) {
                    $db->query("OPTIMIZE TABLE `$table`");
                }
                
                $response = ['success' => true, 'message' => 'Database optimized successfully'];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
            break;
            
        case 'clear_cache':
            // Clear opcache if enabled
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            $response = ['success' => true, 'message' => 'Cache cleared successfully'];
            break;
            
        case 'clear_logs':
            // Clear error log file if it exists
            $log_file = ROOT_PATH . '/logs/error.log';
            if (file_exists($log_file)) {
                if (is_writable($log_file)) {
                    file_put_contents($log_file, '');
                    $response = ['success' => true, 'message' => 'Logs cleared successfully'];
                } else {
                    $response = ['success' => false, 'message' => 'Log file is not writable'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Log file not found'];
            }
            break;
            
        case 'test_email':
            $test_email = $_POST['test_email'] ?? '';
            if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                $response = ['success' => false, 'message' => 'Invalid email address'];
            } else {
                // In a real app, you would send a test email here
                $response = ['success' => true, 'message' => 'Test email would be sent to: ' . $test_email];
            }
            break;
            
        case 'test_sms':
            $test_phone = $_POST['test_phone'] ?? '';
            if (empty($test_phone)) {
                $response = ['success' => false, 'message' => 'Phone number is required'];
            } else {
                // In a real app, you would send a test SMS here
                $response = ['success' => true, 'message' => 'Test SMS would be sent to: ' . $test_phone];
            }
            break;
            
        case 'add_setting':
            // Check if all required fields are present
            if (!isset($_POST['setting_key']) || !isset($_POST['category']) || !isset($_POST['setting_type'])) {
                $response = ['success' => false, 'message' => 'Missing required fields'];
                break;
            }
            
            $setting_key = trim($_POST['setting_key']);
            $setting_value = trim($_POST['setting_value'] ?? '');
            $setting_type = $_POST['setting_type'];
            $category = $_POST['category'];
            $description = trim($_POST['description'] ?? '');
            
            try {
                // Check if setting already exists
                $check_sql = "SELECT COUNT(*) FROM settings WHERE setting_key = ?";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->execute([$setting_key]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    $response = ['success' => false, 'message' => 'Setting key already exists'];
                    break;
                }
                
                // Insert new setting
                $insert_sql = "INSERT INTO settings (setting_key, setting_value, setting_type, category, description) 
                               VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = $db->prepare($insert_sql);
                $insert_stmt->execute([$setting_key, $setting_value, $setting_type, $category, $description]);
                
                $response = ['success' => true, 'message' => 'Setting added successfully', 'reload' => true];
                
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
            break;
    }
    
    echo json_encode($response);
    exit();
}

// Get current settings from database
$settings = [];
try {
    $settings_stmt = $db->prepare("SELECT setting_key, setting_value, setting_type, category, description FROM settings ORDER BY category, setting_key");
    $settings_stmt->execute();
    $settings_data = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to key-value pairs for easy access
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = [
            'value' => $setting['setting_value'],
            'type' => $setting['setting_type'],
            'category' => $setting['category'],
            'description' => $setting['description']
        ];
    }
} catch (Exception $e) {
    error_log("Settings fetch error: " . $e->getMessage());
    $error_message = "Error loading settings: " . $e->getMessage();
}

// Handle form submission for saving settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_GET['ajax'])) {
    // Get all settings from database first
    $all_settings = [];
    try {
        $all_stmt = $db->prepare("SELECT setting_key FROM settings");
        $all_stmt->execute();
        $all_settings = $all_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Settings keys fetch error: " . $e->getMessage());
    }
    
    $errors = [];
    $updated_count = 0;
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Update each setting that exists in POST
        foreach ($all_settings as $key) {
            // Check if this is a checkbox (boolean) - it might not be in POST if unchecked
            $type = $settings[$key]['type'] ?? 'text';
            
            if ($type == 'boolean') {
                // For boolean types, check if checkbox was checked
                $value = isset($_POST[$key]) ? '1' : '0';
                
                $update_sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->execute([$value, $key]);
                $updated_count++;
                
            } elseif (isset($_POST[$key])) {
                // For other types, only update if value was submitted
                $value = trim($_POST[$key]);
                
                // Validation based on type
                if ($type == 'number' && !is_numeric($value) && !empty($value)) {
                    $errors[] = "Setting '$key' must be a number";
                    continue;
                }
                
                // Update setting in database
                $update_sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->execute([$value, $key]);
                $updated_count++;
                
                // Update local settings array
                if (isset($settings[$key])) {
                    $settings[$key]['value'] = $value;
                }
            }
        }
        
        if (empty($errors)) {
            // Commit transaction
            $db->commit();
            $success_message = "Settings saved successfully! ($updated_count settings updated)";
            
            // Refresh settings from database
            $settings_stmt->execute();
            $settings_data = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);
            $settings = [];
            foreach ($settings_data as $setting) {
                $settings[$setting['setting_key']] = [
                    'value' => $setting['setting_value'],
                    'type' => $setting['setting_type'],
                    'category' => $setting['category'],
                    'description' => $setting['description']
                ];
            }
        } else {
            $db->rollBack();
            $error_message = implode("<br>", $errors);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Settings save error: " . $e->getMessage());
        $error_message = "Error saving settings: " . $e->getMessage();
    }
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<style>
/* Settings page specific styles */
.settings-card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.settings-tab-content {
    padding: 1.5rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    font-weight: 600;
    padding: 0.75rem 1.5rem;
}

.nav-tabs .nav-link.active {
    color: #3b82f6;
    border-bottom: 3px solid #3b82f6;
    background-color: transparent;
}

.form-label {
    font-weight: 600;
    color: #4b5563;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0.75rem;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-text {
    font-size: 0.875rem;
    color: #6b7280;
    margin-top: 0.25rem;
}

.btn-submit {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 0.75rem 2rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.form-check-input:checked {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

.settings-group {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.settings-group-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #3b82f6;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.password-toggle {
    cursor: pointer;
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.setting-description {
    font-size: 0.85rem;
    color: #6c757d;
    font-style: italic;
    margin-top: 0.25rem;
}

/* Prevent content from spilling over sidebar */
.admin-container {
    margin-left: 250px;
    padding: 20px;
    max-width: calc(100% - 250px);
    overflow-x: hidden;
    box-sizing: border-box;
}

@media (max-width: 992px) {
    .admin-container {
        margin-left: 0;
        max-width: 100%;
        padding: 15px;
    }
}
</style>

<!-- Admin Container -->
<div class="admin-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #059669;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-cog me-2"></i>System Settings
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Configure system preferences and settings
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="#" class="btn btn-outline-secondary" onclick="window.location.reload()">
                    <i class="fas fa-sync me-2"></i> Refresh
                </a>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="settingsForm">
        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
            <?php
            // Get unique categories for tabs
            $categories = [];
            foreach ($settings as $key => $data) {
                if (!empty($data['category']) && !in_array($data['category'], $categories)) {
                    $categories[] = $data['category'];
                }
            }
            sort($categories);
            
            // Add 'general' as first tab if it exists
            if (in_array('general', $categories)) {
                $index = array_search('general', $categories);
                if ($index !== false) {
                    unset($categories[$index]);
                    array_unshift($categories, 'general');
                }
            }
            
            // Create tabs
            $first = true;
            foreach ($categories as $category): 
                $category_name = ucfirst($category);
                $icon = match($category) {
                    'general' => 'fa-globe',
                    'orders' => 'fa-shipping-fast',
                    'trucks' => 'fa-truck',
                    'drivers' => 'fa-user-tie',
                    'email' => 'fa-envelope',
                    'sms' => 'fa-sms',
                    'payment' => 'fa-credit-card',
                    'security' => 'fa-shield-alt',
                    default => 'fa-cog'
                };
            ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $first ? 'active' : ''; ?>" 
                            id="<?php echo $category; ?>-tab" 
                            data-bs-toggle="tab" 
                            data-bs-target="#<?php echo $category; ?>" 
                            type="button" role="tab">
                        <i class="fas <?php echo $icon; ?> me-2"></i> <?php echo $category_name; ?>
                    </button>
                </li>
            <?php 
                $first = false;
            endforeach; 
            ?>
        </ul>

        <!-- Tabs Content -->
        <div class="tab-content" id="settingsTabsContent">
            <?php
            // Create tab content for each category
            $first = true;
            foreach ($categories as $category): 
                $category_settings = array_filter($settings, function($data) use ($category) {
                    return $data['category'] == $category;
                });
            ?>
                <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" 
                     id="<?php echo $category; ?>" role="tabpanel">
                    <div class="card settings-card">
                        <div class="card-body settings-tab-content">
                            <h5 class="settings-group-title mb-4">
                                <i class="fas <?php echo match($category) {
                                    'general' => 'fa-globe',
                                    'orders' => 'fa-shipping-fast',
                                    'trucks' => 'fa-truck',
                                    'drivers' => 'fa-user-tie',
                                    'email' => 'fa-envelope',
                                    'sms' => 'fa-sms',
                                    default => 'fa-cog'
                                }; ?> me-2"></i>
                                <?php echo ucfirst($category); ?> Settings
                            </h5>
                            
                            <div class="row">
                                <?php foreach ($category_settings as $key => $data): 
                                    $value = $data['value'];
                                    $type = $data['type'];
                                    $description = $data['description'];
                                ?>
                                    <div class="col-md-6 mb-3">
                                        <label for="<?php echo htmlspecialchars($key); ?>" class="form-label">
                                            <?php echo ucwords(str_replace('_', ' ', $key)); ?>
                                        </label>
                                        
                                        <?php if ($type == 'boolean'): ?>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       id="<?php echo htmlspecialchars($key); ?>" 
                                                       name="<?php echo htmlspecialchars($key); ?>"
                                                       value="1"
                                                       <?php echo $value == '1' ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="<?php echo htmlspecialchars($key); ?>">
                                                    <?php echo $value == '1' ? 'Enabled' : 'Disabled'; ?>
                                                </label>
                                            </div>
                                        <?php elseif ($type == 'password'): ?>
                                            <div class="position-relative">
                                                <input type="password" class="form-control" 
                                                       id="<?php echo htmlspecialchars($key); ?>" 
                                                       name="<?php echo htmlspecialchars($key); ?>"
                                                       value="<?php echo htmlspecialchars($value); ?>"
                                                       placeholder="Enter value...">
                                                <span class="password-toggle" onclick="togglePassword('<?php echo htmlspecialchars($key); ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </span>
                                            </div>
                                        <?php elseif ($type == 'json'): ?>
                                            <textarea class="form-control" 
                                                      id="<?php echo htmlspecialchars($key); ?>" 
                                                      name="<?php echo htmlspecialchars($key); ?>"
                                                      rows="4"
                                                      placeholder="Enter JSON data..."><?php echo htmlspecialchars($value); ?></textarea>
                                        <?php elseif ($key == 'currency'): ?>
                                            <select class="form-select" id="<?php echo htmlspecialchars($key); ?>" 
                                                    name="<?php echo htmlspecialchars($key); ?>">
                                                <option value="USD" <?php echo $value == 'USD' ? 'selected' : ''; ?>>US Dollar ($)</option>
                                                <option value="EUR" <?php echo $value == 'EUR' ? 'selected' : ''; ?>>Euro (€)</option>
                                                <option value="GBP" <?php echo $value == 'GBP' ? 'selected' : ''; ?>>British Pound (£)</option>
                                                <option value="KES" <?php echo $value == 'KES' ? 'selected' : ''; ?>>Kenyan Shilling (KSh)</option>
                                                <option value="INR" <?php echo $value == 'INR' ? 'selected' : ''; ?>>Indian Rupee (₹)</option>
                                            </select>
                                        <?php elseif ($key == 'timezone'): ?>
                                            <select class="form-select" id="<?php echo htmlspecialchars($key); ?>" 
                                                    name="<?php echo htmlspecialchars($key); ?>">
                                                <option value="UTC" <?php echo $value == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                                <option value="Africa/Nairobi" <?php echo $value == 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi</option>
                                                <option value="America/New_York" <?php echo $value == 'America/New_York' ? 'selected' : ''; ?>>America/New York</option>
                                                <option value="Europe/London" <?php echo $value == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                                                <option value="Asia/Dubai" <?php echo $value == 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai</option>
                                            </select>
                                        <?php elseif ($key == 'date_format'): ?>
                                            <select class="form-select" id="<?php echo htmlspecialchars($key); ?>" 
                                                    name="<?php echo htmlspecialchars($key); ?>">
                                                <option value="Y-m-d" <?php echo $value == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                                <option value="d/m/Y" <?php echo $value == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                                <option value="m/d/Y" <?php echo $value == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                                <option value="d M Y" <?php echo $value == 'd M Y' ? 'selected' : ''; ?>>DD Mon YYYY</option>
                                            </select>
                                        <?php else: ?>
                                            <input type="<?php echo $type == 'number' ? 'number' : 'text'; ?>" 
                                                   class="form-control" 
                                                   id="<?php echo htmlspecialchars($key); ?>" 
                                                   name="<?php echo htmlspecialchars($key); ?>"
                                                   value="<?php echo htmlspecialchars($value); ?>"
                                                   <?php echo $type == 'number' ? 'step="any"' : ''; ?>
                                                   placeholder="Enter value...">
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($description)): ?>
                                            <div class="setting-description">
                                                <?php echo htmlspecialchars($description); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php 
                $first = false;
            endforeach; 
            ?>
        </div>
        
        <!-- Form Actions -->
        <div class="card settings-card mt-4">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i> Cancel
                        </a>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-submit text-white">
                            <i class="fas fa-save me-2"></i> Save All Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Settings page loaded');
    
    // Tab persistence
    const activeTab = localStorage.getItem('activeSettingsTab');
    if (activeTab) {
        const tab = document.querySelector(`[data-bs-target="${activeTab}"]`);
        if (tab) {
            const bsTab = new bootstrap.Tab(tab);
            bsTab.show();
        }
    }
    
    // Save active tab on change
    document.querySelectorAll('#settingsTabs button').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function (event) {
            localStorage.setItem('activeSettingsTab', event.target.getAttribute('data-bs-target'));
        });
    });
    
    // Toggle boolean switches text
    document.querySelectorAll('.form-check-input').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const label = this.closest('.form-check').querySelector('.form-check-label');
            label.textContent = this.checked ? 'Enabled' : 'Disabled';
        });
    });
    
    // Form submission
    const form = document.getElementById('settingsForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            console.log('Form submitted');
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
                submitBtn.disabled = true;
                
                // Reset button after 2 seconds if form submission fails
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 2000);
            }
        });
    }
});

function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const toggle = field.nextElementSibling;
    
    if (field.type === 'password') {
        field.type = 'text';
        toggle.querySelector('i').classList.remove('fa-eye');
        toggle.querySelector('i').classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        toggle.querySelector('i').classList.remove('fa-eye-slash');
        toggle.querySelector('i').classList.add('fa-eye');
    }
}

// Save settings with Ctrl+S
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const form = document.getElementById('settingsForm');
        if (form) {
            form.submit();
        }
    }
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>