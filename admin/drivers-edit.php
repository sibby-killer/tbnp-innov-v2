<?php
// drivers-edit.php
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

// Get driver ID from URL
$driver_id = $_GET['id'] ?? 0;
if (!$driver_id) {
    header('Location: drivers.php');
    exit();
}

// Initialize variables
$driver = null;
$error = '';
$success = '';

// Get driver details
try {
    $query = "SELECT d.*, u.name as driver_name, u.email, u.phone, u.status as user_status 
              FROM drivers d 
              INNER JOIN users u ON d.user_id = u.id 
              WHERE d.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$driver_id]);
    $driver = $stmt->fetch();
    
    if (!$driver) {
        header('Location: drivers.php?error=driver_not_found');
        exit();
    }
    
} catch (Exception $e) {
    $error = "Error loading driver details: " . $e->getMessage();
    error_log("Driver edit error: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $user_status = $_POST['user_status'] ?? 'active';
    $license_number = trim($_POST['license_number'] ?? '');
    $license_type = $_POST['license_type'] ?? '';
    $experience_years = $_POST['experience_years'] ?? 0;
    $driver_status = $_POST['driver_status'] ?? 'available';
    $courier_id = $_POST['courier_id'] ?? null;
    
    // Basic validation
    if (empty($name) || empty($email) || empty($license_number)) {
        $error = "Name, email, and license number are required fields.";
    } else {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Update users table
            $user_query = "UPDATE users SET 
                          name = ?, 
                          email = ?, 
                          phone = ?, 
                          status = ? 
                          WHERE id = ?";
            
            $user_stmt = $db->prepare($user_query);
            $user_stmt->execute([
                $name,
                $email,
                $phone,
                $user_status,
                $driver['user_id']
            ]);
            
            // Update drivers table
            $driver_query = "UPDATE drivers SET 
                            license_number = ?, 
                            license_type = ?, 
                            experience_years = ?, 
                            status = ?, 
                            courier_id = ?,
                            updated_at = NOW()
                            WHERE id = ?";
            
            $driver_stmt = $db->prepare($driver_query);
            $driver_stmt->execute([
                $license_number,
                $license_type,
                $experience_years,
                $driver_status,
                $courier_id,
                $driver_id
            ]);
            
            // Commit transaction
            $db->commit();
            
            $success = "Driver information updated successfully!";
            
            // Refresh driver data
            $stmt->execute([$driver_id]);
            $driver = $stmt->fetch();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating driver: " . $e->getMessage();
            error_log("Driver update error: " . $e->getMessage());
        }
    }
}

$pageTitle = "Edit Driver - " . ($driver['driver_name'] ?? 'Unknown Driver');

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<div class="main-container" style="margin-left: 250px; padding: 20px; min-height: 100vh;">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #306998;">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h4 mb-1" style="color: #0D2B4E; font-weight: 700;">
                    <i class="fas fa-user-edit me-2"></i>Edit Driver
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Update driver information and settings
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="drivers.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Drivers
                </a>
                <a href="drivers-view.php?id=<?php echo $driver_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye me-2"></i> View Driver
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($driver): ?>
        <div class="row">
            <div class="col-md-8">
                <!-- Edit Form -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <form method="POST" action="" id="driverEditForm">
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-user-circle me-2"></i>Basic Information
                            </h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?php echo htmlspecialchars($driver['driver_name']); ?>" 
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($driver['email']); ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($driver['phone']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="user_status" class="form-label">Account Status</label>
                                    <select class="form-select" id="user_status" name="user_status">
                                        <option value="active" <?php echo $driver['user_status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $driver['user_status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="suspended" <?php echo $driver['user_status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    </select>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-id-card me-2"></i>Driver Details
                            </h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="license_number" class="form-label">License Number *</label>
                                    <input type="text" class="form-control" id="license_number" name="license_number" 
                                           value="<?php echo htmlspecialchars($driver['license_number']); ?>" 
                                           required>
                                </div>
                                <div class="col-md-6">
                                    <label for="license_type" class="form-label">License Type</label>
                                    <select class="form-select" id="license_type" name="license_type">
                                        <option value="">Select Type</option>
                                        <option value="A" <?php echo $driver['license_type'] == 'A' ? 'selected' : ''; ?>>Class A (Motorcycle)</option>
                                        <option value="B" <?php echo $driver['license_type'] == 'B' ? 'selected' : ''; ?>>Class B (Car)</option>
                                        <option value="C" <?php echo $driver['license_type'] == 'C' ? 'selected' : ''; ?>>Class C (Truck)</option>
                                        <option value="D" <?php echo $driver['license_type'] == 'D' ? 'selected' : ''; ?>>Class D (Bus)</option>
                                        <option value="E" <?php echo $driver['license_type'] == 'E' ? 'selected' : ''; ?>>Class E (Trailer)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="experience_years" class="form-label">Years of Experience</label>
                                    <input type="number" class="form-control" id="experience_years" name="experience_years" 
                                           value="<?php echo htmlspecialchars($driver['experience_years'] ?? 0); ?>" 
                                           min="0" max="50">
                                </div>
                                <div class="col-md-6">
                                    <label for="driver_status" class="form-label">Driver Status</label>
                                    <select class="form-select" id="driver_status" name="driver_status">
                                        <option value="available" <?php echo $driver['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                        <option value="on_delivery" <?php echo $driver['status'] == 'on_delivery' ? 'selected' : ''; ?>>On Delivery</option>
                                        <option value="on_break" <?php echo $driver['status'] == 'on_break' ? 'selected' : ''; ?>>On Break</option>
                                        <option value="inactive" <?php echo $driver['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="maintenance" <?php echo $driver['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="courier_id" class="form-label">Assigned Courier</label>
                                <input type="text" class="form-control" id="courier_id" name="courier_id" 
                                       value="<?php echo htmlspecialchars($driver['courier_id'] ?? ''); ?>"
                                       placeholder="Enter Courier ID or leave empty if not assigned">
                                <div class="form-text">Enter the ID of the courier company this driver is assigned to.</div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="d-flex justify-content-between">
                                <a href="drivers-view.php?id=<?php echo $driver_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Driver Summary -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-info-circle me-2"></i>Driver Summary
                        </h5>
                        
                        <div class="text-center mb-4">
                            <div style="width: 100px; height: 100px; background: linear-gradient(135deg, #306998, #4B8BBE); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem; font-weight: 700; margin: 0 auto;">
                                <?php 
                                    $initials = '';
                                    if (!empty($driver['driver_name'])) {
                                        $names = explode(' ', $driver['driver_name']);
                                        $initials = strtoupper(substr($names[0], 0, 1));
                                        if (count($names) > 1) {
                                            $initials .= strtoupper(substr($names[1], 0, 1));
                                        }
                                    } else {
                                        $initials = '?';
                                    }
                                    echo $initials;
                                ?>
                            </div>
                            <h6 class="mt-3 mb-1"><?php echo htmlspecialchars($driver['driver_name']); ?></h6>
                            <p class="text-muted small">Driver ID: <?php echo $driver['id']; ?></p>
                        </div>
                        
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Account Status:</span>
                                <span class="badge bg-<?php echo $driver['user_status'] == 'active' ? 'success' : 'danger'; ?>">
                                    <?php echo ucfirst($driver['user_status']); ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Driver Status:</span>
                                <?php
                                $status_class = '';
                                switch ($driver['status']) {
                                    case 'available':
                                        $status_class = 'badge bg-success';
                                        break;
                                    case 'on_delivery':
                                        $status_class = 'badge bg-warning';
                                        break;
                                    case 'on_break':
                                        $status_class = 'badge bg-info';
                                        break;
                                    case 'inactive':
                                        $status_class = 'badge bg-secondary';
                                        break;
                                    default:
                                        $status_class = 'badge bg-light text-dark';
                                }
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo ucfirst($driver['status']); ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>License Number:</span>
                                <strong><?php echo htmlspecialchars($driver['license_number']); ?></strong>
                            </div>
                            <div class="list-group-item">
                                <small class="text-muted">User ID: <?php echo $driver['user_id']; ?></small><br>
                                <small class="text-muted">Created: <?php echo date('M j, Y', strtotime($driver['created_at'] ?? 'Now')); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-link me-2"></i>Quick Links
                        </h5>
                        <div class="d-grid gap-2">
                            <a href="assign-truck.php?driver_id=<?php echo $driver_id; ?>" class="btn btn-outline-info">
                                <i class="fas fa-truck me-2"></i> Assign Truck
                            </a>
                            <?php if ($driver['courier_id']): ?>
                                <a href="reassign-courier.php?driver_id=<?php echo $driver_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-exchange-alt me-2"></i> Reassign Courier
                                </a>
                            <?php else: ?>
                                <a href="assign-courier.php?driver_id=<?php echo $driver_id; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-building me-2"></i> Assign to Courier
                                </a>
                            <?php endif; ?>
                            <a href="drivers.php?action=delete&id=<?php echo $driver_id; ?>" 
                               class="btn btn-outline-danger"
                               onclick="return confirm('Are you sure you want to delete this driver? This action cannot be undone.');">
                                <i class="fas fa-trash me-2"></i> Delete Driver
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Driver not found. Please check the driver ID and try again.
        </div>
        <div class="text-center mt-4">
            <a href="drivers.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i> Back to Drivers List
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Form validation
    document.getElementById('driverEditForm').addEventListener('submit', function(e) {
        const name = document.getElementById('name').value.trim();
        const email = document.getElementById('email').value.trim();
        const license = document.getElementById('license_number').value.trim();
        
        if (!name || !email || !license) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }
        
        if (!validateEmail(email)) {
            e.preventDefault();
            alert('Please enter a valid email address.');
            return false;
        }
    });
    
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>