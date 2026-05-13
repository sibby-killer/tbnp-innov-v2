<?php
// drivers-add.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if ($_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit();
}

// Initialize variables
$name = $email = $phone = $license_number = $license_type = $password = $confirm_password = '';
$experience_years = 0;
$courier_id = $status = '';
$errors = [];
$success = false;

// Get active couriers for dropdown
$couriers = [];
try {
    $courier_stmt = $db->prepare("SELECT id, name FROM couriers WHERE status = 'active' ORDER BY name");
    $courier_stmt->execute();
    $couriers = $courier_stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');
    $license_type = $_POST['license_type'] ?? '';
    $experience_years = (int)($_POST['experience_years'] ?? 0);
    $courier_id = !empty($_POST['courier_id']) ? (int)$_POST['courier_id'] : null;
    $status = $_POST['status'] ?? 'available';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($name)) $errors[] = 'Name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (empty($phone)) $errors[] = 'Phone number is required.';
    if (empty($license_number)) $errors[] = 'License number is required.';
    if (empty($license_type)) $errors[] = 'License type is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
    if ($experience_years < 0 || $experience_years > 50) $errors[] = 'Experience must be between 0 and 50 years.';
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $checkEmail = $db->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->execute([$email]);
            if ($checkEmail->fetch()) {
                $errors[] = 'Email already exists.';
            }
        } catch (Exception $e) {
            $errors[] = 'System error. Please try again.';
        }
    }
    
    // If no errors, insert data
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Insert into users table
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $user_stmt = $db->prepare("
                INSERT INTO users (name, email, password, phone, role_id, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");
            
            $user_result = $user_stmt->execute([
                $name,
                $email,
                $hashed_password,
                $phone,
                ROLE_DRIVER
            ]);
            
            if (!$user_result) {
                throw new Exception("Failed to insert into users table");
            }
            
            $user_id = $db->lastInsertId();
            
            // Insert into drivers table
            $driver_stmt = $db->prepare("
                INSERT INTO drivers (user_id, license_number, license_type, experience_years, courier_id, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $driver_result = $driver_stmt->execute([
                $user_id,
                $license_number,
                $license_type,
                $experience_years,
                $courier_id,
                $status
            ]);
            
            if (!$driver_result) {
                throw new Exception("Failed to insert into drivers table");
            }
            
            $db->commit();
            
            // Redirect to prevent form resubmission
            header('Location: drivers-add.php?success=1');
            exit();
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            // Show the actual error for debugging
            $errors[] = 'Database error: ' . $e->getMessage();
            error_log("Driver registration error: " . $e->getMessage());
        }
    }
}

// Check for success redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = true;
    // Clear form data
    $name = $email = $phone = $license_number = $license_type = $password = $confirm_password = '';
    $experience_years = 0;
    $courier_id = $status = '';
}

// Set page title and include header AFTER all processing
$pageTitle = "Add New Driver";
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<div class="main-container" style="margin-left: 250px; padding: 20px; min-height: 100vh;">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #306998;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h4 mb-1" style="color: #0D2B4E; font-weight: 700;">
                    <i class="fas fa-user-plus me-2"></i>Add New Driver
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Register a new driver to your courier system
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="drivers.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Drivers
                </a>
            </div>
        </div>
    </div>

    <!-- Success Message -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <strong>Success!</strong> Driver has been added successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        <div class="mt-2">
            <a href="drivers.php" class="btn btn-sm btn-success">View Drivers</a>
            <a href="drivers-add.php" class="btn btn-sm btn-outline-success">Add Another</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Error!</strong> Please fix the following issues:
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Driver Registration Form -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" action="">
                <!-- Personal Information -->
                <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-id-card me-2"></i>Personal Information</h5>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" 
                                   class="form-control" 
                                   name="name" 
                                   value="<?php echo htmlspecialchars($name); ?>" 
                                   required
                                   placeholder="John Doe">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" 
                                   class="form-control" 
                                   name="email" 
                                   value="<?php echo htmlspecialchars($email); ?>" 
                                   required
                                   placeholder="driver@example.com">
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Phone Number *</label>
                            <input type="tel" 
                                   class="form-control" 
                                   name="phone" 
                                   value="<?php echo htmlspecialchars($phone); ?>" 
                                   required
                                   placeholder="+1 (555) 123-4567">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="available" <?php echo $status == 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="on_delivery" <?php echo $status == 'on_delivery' ? 'selected' : ''; ?>>On Delivery</option>
                                <option value="on_break" <?php echo $status == 'on_break' ? 'selected' : ''; ?>>On Break</option>
                                <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Driver License Information -->
                <h5 class="mb-3 mt-4 border-bottom pb-2"><i class="fas fa-id-card-alt me-2"></i>Driver License Information</h5>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">License Number *</label>
                            <input type="text" 
                                   class="form-control" 
                                   name="license_number" 
                                   value="<?php echo htmlspecialchars($license_number); ?>" 
                                   required
                                   placeholder="DL12345678">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Experience (Years) *</label>
                            <input type="number" 
                                   class="form-control" 
                                   name="experience_years" 
                                   value="<?php echo htmlspecialchars($experience_years); ?>" 
                                   min="0"
                                   max="50"
                                   required>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">License Type *</label>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="license_type" value="A" 
                                   id="licenseA" <?php echo $license_type == 'A' ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="licenseA">Class A (Heavy Vehicles)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="license_type" value="B" 
                                   id="licenseB" <?php echo $license_type == 'B' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="licenseB">Class B (Medium Vehicles)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="license_type" value="C" 
                                   id="licenseC" <?php echo $license_type == 'C' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="licenseC">Class C (Light Vehicles)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="license_type" value="D" 
                                   id="licenseD" <?php echo $license_type == 'D' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="licenseD">Class D (Motorcycles)</label>
                        </div>
                    </div>
                </div>

                <!-- Courier Assignment -->
                <h5 class="mb-3 mt-4 border-bottom pb-2"><i class="fas fa-building me-2"></i>Courier Assignment</h5>
                
                <div class="mb-4">
                    <label class="form-label">Assign to Courier (Optional)</label>
                    <select class="form-select" name="courier_id">
                        <option value="">-- Select Courier --</option>
                        <?php foreach ($couriers as $courier): ?>
                            <option value="<?php echo $courier['id']; ?>" 
                                <?php echo $courier_id == $courier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($courier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Account Credentials -->
                <h5 class="mb-3 mt-4 border-bottom pb-2"><i class="fas fa-key me-2"></i>Account Credentials</h5>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" 
                                   class="form-control" 
                                   name="password" 
                                   required
                                   placeholder="Minimum 6 characters">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Confirm Password *</label>
                            <input type="password" 
                                   class="form-control" 
                                   name="confirm_password" 
                                   required
                                   placeholder="Re-enter password">
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="d-flex justify-content-between align-items-center pt-4 border-top">
                    <a href="drivers.php" class="btn btn-outline-primary">
                        <i class="fas fa-times me-2"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i> Add Driver
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Database Debug Info -->
    <div class="card mt-4 bg-light border-0">
        <div class="card-body">
            <h6 class="text-muted mb-3">Database Structure Check:</h6>
            <?php
            try {
                // Check users table structure
                $users_columns = $db->query("DESCRIBE users")->fetchAll();
                echo "<div class='mb-3'><strong>Users Table:</strong><br>";
                echo "<table class='table table-sm table-bordered'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($users_columns as $col) {
                    echo "<tr>";
                    echo "<td>" . $col['Field'] . "</td>";
                    echo "<td>" . $col['Type'] . "</td>";
                    echo "<td>" . $col['Null'] . "</td>";
                    echo "<td>" . $col['Key'] . "</td>";
                    echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table></div>";
                
                // Check drivers table structure
                $drivers_columns = $db->query("DESCRIBE drivers")->fetchAll();
                echo "<div class='mb-3'><strong>Drivers Table:</strong><br>";
                echo "<table class='table table-sm table-bordered'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($drivers_columns as $col) {
                    echo "<tr>";
                    echo "<td>" . $col['Field'] . "</td>";
                    echo "<td>" . $col['Type'] . "</td>";
                    echo "<td>" . $col['Null'] . "</td>";
                    echo "<td>" . $col['Key'] . "</td>";
                    echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table></div>";
                
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Error checking database structure: " . $e->getMessage() . "</div>";
            }
            ?>
        </div>
    </div>
</div>

<script>
    // Simple form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const password = document.querySelector('input[name="password"]').value;
        const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
        const licenseType = document.querySelector('input[name="license_type"]:checked');
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match!');
            return false;
        }
        
        if (!licenseType) {
            e.preventDefault();
            alert('Please select a license type!');
            return false;
        }
        
        return true;
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>