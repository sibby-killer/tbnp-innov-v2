<?php
/**
 * Add New Courier
 * 
 * Production-ready add courier page following the same pattern as working files
 * 
 * @package CourierTruckManagement
 * @subpackage Admin
 * @version 1.0.0
 */

// ============================================================================
// 1. SESSION MANAGEMENT (Same as working files)
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// 2. PATH DEFINITION (Same as working files)
// ============================================================================
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

// ============================================================================
// 3. INCLUDE CONFIGURATION (Same as working files)
// ============================================================================
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// ============================================================================
// 4. VERIFY DATABASE CONNECTION (Same as working files)
// ============================================================================
$db_connected = false;
$connection_error = null;

try {
    if (!isset($db) || !$db) {
        throw new Exception("Database connection not available");
    }
    
    // Test the connection with a simple query
    $test_query = $db->query("SELECT 1");
    if ($test_query) {
        $db_connected = true;
    } else {
        throw new Exception("Database connection test failed");
    }
} catch (Exception $e) {
    $connection_error = $e->getMessage();
    error_log("Couriers add database connection error: " . $connection_error);
}

// ============================================================================
// 5. AUTHENTICATION CHECKS (Same as working files)
// ============================================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if ($_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit();
}

// ============================================================================
// 6. INITIALIZE VARIABLES
// ============================================================================
$error = '';
$success = '';
$courier_data = [
    'name' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'max_trucks' => 10,
    'status' => 'active',
    'logo' => ''
];

// ============================================================================
// 7. PROCESS FORM SUBMISSION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connected) {
    
    // Sanitize and validate inputs
    $name = trim($_POST['name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $max_trucks = intval($_POST['max_trucks'] ?? 10);
    $status = $_POST['status'] ?? 'active';
    
    // Basic validation
    if (empty($name)) {
        $error = "Company name is required.";
    } elseif (empty($contact_person)) {
        $error = "Contact person is required.";
    } elseif (empty($phone)) {
        $error = "Phone number is required.";
    } elseif (empty($email)) {
        $error = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($max_trucks < 1) {
        $error = "Max trucks must be at least 1.";
    } else {
        try {
            // Check if courier name or email already exists
            $check_query = "SELECT id FROM couriers WHERE name = ? OR email = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$name, $email]);
            
            if ($check_stmt->fetch()) {
                $error = "A courier with this name or email already exists.";
            } else {
                
                // Handle logo upload
                $logo_path = null;
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = ROOT_PATH . '/uploads/couriers/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Validate file type
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $file_type = $_FILES['logo']['type'];
                    
                    if (!in_array($file_type, $allowed_types)) {
                        throw new Exception("Only JPG, PNG, GIF, and WEBP images are allowed.");
                    }
                    
                    // Validate file size (max 2MB)
                    if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                        throw new Exception("Logo image must be less than 2MB.");
                    }
                    
                    $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $filename = 'courier_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                        $logo_path = '/uploads/couriers/' . $filename;
                    }
                }
                
                // Prepare courier data for insertion
                $courier_data = [
                    'name' => $name,
                    'contact_person' => $contact_person,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'max_trucks' => $max_trucks,
                    'status' => $status,
                    'logo' => $logo_path
                ];
                
                // Insert into database
                $insert_query = "INSERT INTO couriers 
                                (name, contact_person, phone, email, address, max_trucks, status, logo, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->execute([
                    $courier_data['name'],
                    $courier_data['contact_person'],
                    $courier_data['phone'],
                    $courier_data['email'],
                    $courier_data['address'],
                    $courier_data['max_trucks'],
                    $courier_data['status'],
                    $courier_data['logo']
                ]);
                
                $courier_id = $db->lastInsertId();
                
                // Log activity
                $log_stmt = $db->prepare("
                    INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                    VALUES (?, 'add_courier', ?, ?, ?)
                ");
                $log_stmt->execute([
                    $_SESSION['user_id'],
                    "Added new courier: $name (ID: $courier_id)",
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $success = "Courier added successfully!";
                
                // Clear form data after successful submission
                $courier_data = [
                    'name' => '',
                    'contact_person' => '',
                    'phone' => '',
                    'email' => '',
                    'address' => '',
                    'max_trucks' => 10,
                    'status' => 'active',
                    'logo' => ''
                ];
            }
            
        } catch (Exception $e) {
            $error = "Error adding courier: " . $e->getMessage();
            error_log("Courier add error: " . $e->getMessage());
        }
    }
}

// ============================================================================
// 8. GET USER NAME (Same as working files)
// ============================================================================
$user_name = $_SESSION['user_name'] ?? 'Admin';
if (empty($user_name) && $db_connected) {
    try {
        $name_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
        $name_stmt->execute([$_SESSION['user_id']]);
        $user_data = $name_stmt->fetch();
        $user_name = $user_data['name'] ?? 'Admin';
        $_SESSION['user_name'] = $user_name;
    } catch (Exception $e) {
        error_log("Failed to fetch user name: " . $e->getMessage());
    }
}

$pageTitle = "Add New Courier";

// ============================================================================
// 9. INCLUDE HEADER AND SIDEBAR
// ============================================================================
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<!-- ============================================================================
     Add Courier Content
     ============================================================================ -->
<div class="main-container" style="margin-left: 250px; padding: 20px; min-height: 100vh;">
    
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #306998;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h4 mb-1" style="color: #0D2B4E; font-weight: 700;">
                    <i class="fas fa-building me-2"></i>Add New Courier
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Register a new courier company in the system
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="couriers.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Couriers
                </a>
            </div>
        </div>
    </div>

    <!-- Database Connection Error -->
    <?php if (!$db_connected): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Database connection error: <?php echo htmlspecialchars($connection_error ?? 'Unknown error'); ?>
            <br>Please check your database configuration.
        </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            <div class="mt-2">
                <a href="couriers.php" class="btn btn-sm btn-outline-success me-2">
                    <i class="fas fa-list me-1"></i> View All Couriers
                </a>
                <a href="couriers-add.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus me-1"></i> Add Another Courier
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- Add Courier Form -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="courierAddForm">
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-building me-2"></i>Courier Company Information
                        </h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label">Company Name *</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($courier_data['name']); ?>" 
                                       required 
                                       placeholder="e.g., Express Delivery Inc., Fast Cargo Services"
                                       <?php echo !$db_connected ? 'disabled' : ''; ?>>
                                <div class="form-text">Enter the official company name (must be unique)</div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-user-tie me-2"></i>Contact Information
                        </h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="contact_person" class="form-label">Contact Person *</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                       value="<?php echo htmlspecialchars($courier_data['contact_person']); ?>" 
                                       required 
                                       placeholder="e.g., John Manager, Sarah Logistics"
                                       <?php echo !$db_connected ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone Number *</label>
                                <input type="text" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($courier_data['phone']); ?>" 
                                       required 
                                       placeholder="e.g., +1234567890"
                                       <?php echo !$db_connected ? 'disabled' : ''; ?>>
                                <div class="form-text">Include country code</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($courier_data['email']); ?>" 
                                       required 
                                       placeholder="e.g., info@company.com"
                                       <?php echo !$db_connected ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-6">
                                <label for="max_trucks" class="form-label">Maximum Trucks Allowed *</label>
                                <input type="number" class="form-control" id="max_trucks" name="max_trucks" 
                                       value="<?php echo htmlspecialchars($courier_data['max_trucks']); ?>" 
                                       required min="1" max="100"
                                       placeholder="e.g., 10"
                                       <?php echo !$db_connected ? 'disabled' : ''; ?>>
                                <div class="form-text">Maximum number of trucks this courier can have</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="address" class="form-label">Address</label>
                            <textarea class="form-control" id="address" name="address" 
                                      rows="2" 
                                      placeholder="Enter full company address..."
                                      <?php echo !$db_connected ? 'disabled' : ''; ?>><?php echo htmlspecialchars($courier_data['address']); ?></textarea>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-cog me-2"></i>Additional Settings
                        </h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" <?php echo !$db_connected ? 'disabled' : ''; ?>>
                                    <option value="active" <?php echo $courier_data['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $courier_data['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Active couriers can be assigned trucks and drivers</div>
                            </div>
                            <div class="col-md-6">
                                <label for="logo" class="form-label">Company Logo</label>
                                <input type="file" class="form-control" id="logo" name="logo" accept="image/jpeg,image/png,image/gif,image/webp"
                                       <?php echo !$db_connected ? 'disabled' : ''; ?>>
                                <div class="form-text">Optional: Upload company logo (JPG, PNG, GIF, WEBP - max 2MB)</div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between">
                            <a href="couriers.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" <?php echo !$db_connected ? 'disabled' : ''; ?>>
                                <i class="fas fa-save me-2"></i> Add Courier
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <!-- Information Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                        <i class="fas fa-info-circle me-2"></i>Quick Information
                    </h5>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tips for adding couriers:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                            <li>Company name must be unique</li>
                            <li>Email address must be unique</li>
                            <li>Phone number format: +[country code][number]</li>
                            <li>Max trucks determines fleet size limit</li>
                            <li>You can upload a logo image later</li>
                        </ul>
                    </div>
                    
                    <div class="list-group list-group-flush">
                        <div class="list-group-item">
                            <h6 class="mb-1">Required Fields *</h6>
                            <small class="text-muted">Company Name, Contact Person, Phone, Email, and Max Trucks are mandatory</small>
                        </div>
                        <div class="list-group-item">
                            <h6 class="mb-1">Status Options</h6>
                            <small class="text-muted">
                                <span class="badge bg-success">Active</span> - Available for assignments<br>
                                <span class="badge bg-secondary">Inactive</span> - Temporarily disabled
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                        <i class="fas fa-chart-bar me-2"></i>Current Statistics
                    </h5>
                    
                    <?php
                    if ($db_connected) {
                        try {
                            // Get courier stats
                            $stats_query = "SELECT 
                                COUNT(*) as total_couriers,
                                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_couriers,
                                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_couriers,
                                SUM(max_trucks) as total_max_trucks
                                FROM couriers";
                            $stats_stmt = $db->query($stats_query);
                            $stats = $stats_stmt->fetch();
                            
                            // Get total trucks count
                            $trucks_query = "SELECT COUNT(*) as total_trucks FROM trucks";
                            $trucks_stmt = $db->query($trucks_query);
                            $trucks = $trucks_stmt->fetch();
                            
                        } catch (Exception $e) {
                            $stats = ['total_couriers' => 0, 'active_couriers' => 0, 'inactive_couriers' => 0, 'total_max_trucks' => 0];
                            $trucks = ['total_trucks' => 0];
                        }
                    } else {
                        $stats = ['total_couriers' => 0, 'active_couriers' => 0, 'inactive_couriers' => 0, 'total_max_trucks' => 0];
                        $trucks = ['total_trucks' => 0];
                    }
                    ?>
                    
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Total Couriers:</span>
                            <span class="badge bg-primary rounded-pill"><?php echo $stats['total_couriers']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Active Couriers:</span>
                            <span class="badge bg-success rounded-pill"><?php echo $stats['active_couriers']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Inactive Couriers:</span>
                            <span class="badge bg-secondary rounded-pill"><?php echo $stats['inactive_couriers']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Total Trucks in System:</span>
                            <span class="badge bg-info rounded-pill"><?php echo $trucks['total_trucks']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Total Max Truck Capacity:</span>
                            <span class="badge bg-warning rounded-pill"><?php echo $stats['total_max_trucks']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Auto-hide alerts after 5 seconds -->
<script>
    setTimeout(() => {
        document.querySelectorAll('.alert:not(.alert-info)').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Form validation
    document.getElementById('courierAddForm')?.addEventListener('submit', function(e) {
        const name = document.getElementById('name').value.trim();
        const contactPerson = document.getElementById('contact_person').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const email = document.getElementById('email').value.trim();
        const maxTrucks = document.getElementById('max_trucks').value;
        
        if (!name || !contactPerson || !phone || !email || !maxTrucks) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }
        
        // Email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            e.preventDefault();
            alert('Please enter a valid email address.');
            return false;
        }
        
        // Phone validation (basic)
        const phoneRegex = /^\+?[0-9]{10,15}$/;
        if (!phoneRegex.test(phone.replace(/\s/g, ''))) {
            e.preventDefault();
            alert('Please enter a valid phone number with country code (e.g., +1234567890).');
            return false;
        }
        
        // Max trucks validation
        if (parseInt(maxTrucks) < 1 || parseInt(maxTrucks) > 100) {
            e.preventDefault();
            alert('Max trucks must be between 1 and 100.');
            return false;
        }
        
        // File validation if logo is selected
        const logo = document.getElementById('logo').files[0];
        if (logo) {
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!allowedTypes.includes(logo.type)) {
                e.preventDefault();
                alert('Please select a valid image file (JPG, PNG, GIF, WEBP).');
                return false;
            }
            
            // Check file size (2MB)
            if (logo.size > 2 * 1024 * 1024) {
                e.preventDefault();
                alert('Logo image must be less than 2MB.');
                return false;
            }
        }
    });

    // Auto-format phone number (allow only numbers and +)
    document.getElementById('phone')?.addEventListener('input', function(e) {
        let value = this.value.replace(/[^\d+]/g, '');
        if (value.length > 0 && !value.startsWith('+')) {
            value = '+' + value.replace(/\+/g, '');
        }
        this.value = value;
    });

    // Preview logo before upload
    document.getElementById('logo')?.addEventListener('change', function(e) {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                // Create or update preview
                let preview = document.getElementById('logo-preview');
                if (!preview) {
                    preview = document.createElement('img');
                    preview.id = 'logo-preview';
                    preview.className = 'mt-2 rounded';
                    preview.style.maxWidth = '100px';
                    preview.style.maxHeight = '100px';
                    document.getElementById('logo').parentNode.appendChild(preview);
                }
                preview.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });
</script>

<?php
// ============================================================================
// 10. INCLUDE FOOTER
// ============================================================================
require_once ROOT_PATH . '/includes/footer.php';
?>