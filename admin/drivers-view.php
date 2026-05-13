<?php
// drivers-view.php
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
    error_log("Driver view error: " . $e->getMessage());
}

$pageTitle = "Driver Details - " . ($driver['driver_name'] ?? 'Unknown Driver');

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
                    <i class="fas fa-user me-2"></i>Driver Details
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    View detailed information about this driver
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="drivers.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Drivers
                </a>
                <a href="drivers-edit.php?id=<?php echo $driver_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit me-2"></i> Edit Driver
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

    <?php if ($driver): ?>
        <!-- Driver Information Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h4 class="mb-3"><?php echo htmlspecialchars($driver['driver_name']); ?></h4>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($driver['email']); ?></p>
                                <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($driver['phone']); ?></p>
                                <p class="mb-1"><strong>Account Status:</strong> 
                                    <span class="badge bg-<?php echo $driver['user_status'] == 'active' ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($driver['user_status']); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>License Number:</strong> <?php echo htmlspecialchars($driver['license_number']); ?></p>
                                <p class="mb-1"><strong>License Type:</strong> Class <?php echo htmlspecialchars($driver['license_type'] ?? 'N/A'); ?></p>
                                <p class="mb-1"><strong>Experience:</strong> <?php echo htmlspecialchars($driver['experience_years'] ?? 0); ?> years</p>
                            </div>
                        </div>
                        
                        <!-- Status -->
                        <div class="mb-3">
                            <strong>Driver Status:</strong>
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
                            <span class="<?php echo $status_class; ?> ms-2">
                                <?php echo ucfirst($driver['status']); ?>
                            </span>
                        </div>
                        
                        <!-- Courier Assignment -->
                        <div class="mb-3">
                            <strong>Courier Assignment:</strong>
                            <?php if (!empty($driver['courier_id'])): ?>
                                <span class="ms-2">
                                    <i class="fas fa-building text-primary me-1"></i>
                                    Courier ID: <?php echo $driver['courier_id']; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted ms-2">Not assigned</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="mb-3">
                                <div style="width: 120px; height: 120px; background: linear-gradient(135deg, #306998, #4B8BBE); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 2.5rem; font-weight: 700; margin: 0 auto;">
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
                            </div>
                            <p class="text-muted mb-0">Driver ID: <?php echo $driver['id']; ?></p>
                            <p class="text-muted">User ID: <?php echo $driver['user_id']; ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a href="drivers-edit.php?id=<?php echo $driver_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-2"></i> Edit Profile
                    </a>
                    <a href="assign-truck.php?driver_id=<?php echo $driver_id; ?>" class="btn btn-info">
                        <i class="fas fa-truck me-2"></i> Assign Truck
                    </a>
                    <?php if ($driver['courier_id']): ?>
                        <a href="reassign-courier.php?driver_id=<?php echo $driver_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-exchange-alt me-2"></i> Reassign Courier
                        </a>
                    <?php else: ?>
                        <a href="assign-courier.php?driver_id=<?php echo $driver_id; ?>" class="btn btn-success">
                            <i class="fas fa-building me-2"></i> Assign to Courier
                        </a>
                    <?php endif; ?>
                    <a href="drivers.php?action=delete&id=<?php echo $driver_id; ?>" 
                       class="btn btn-danger"
                       onclick="return confirm('Are you sure you want to delete this driver? This action cannot be undone.');">
                        <i class="fas fa-trash me-2"></i> Delete Driver
                    </a>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="row">
            <div class="col-md-6">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-id-card me-2"></i>Driver Information
                        </h5>
                        <table class="table table-sm">
                            <tr>
                                <td><strong>Driver ID:</strong></td>
                                <td><?php echo $driver['id']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>User ID:</strong></td>
                                <td><?php echo $driver['user_id']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Created At:</strong></td>
                                <td><?php echo date('F j, Y, g:i a', strtotime($driver['created_at'] ?? 'Now')); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Updated At:</strong></td>
                                <td><?php echo date('F j, Y, g:i a', strtotime($driver['updated_at'] ?? 'Now')); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-info-circle me-2"></i>Quick Actions
                        </h5>
                        <div class="d-grid gap-2">
                            <a href="orders.php?driver=<?php echo $driver_id; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-box me-2"></i> View Driver's Orders
                            </a>
                            <a href="trucks.php?driver=<?php echo $driver_id; ?>" class="btn btn-outline-success">
                                <i class="fas fa-truck me-2"></i> View Assigned Trucks
                            </a>
                            <a href="reports.php?driver=<?php echo $driver_id; ?>" class="btn btn-outline-info">
                                <i class="fas fa-chart-bar me-2"></i> Driver Performance Report
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

    // Confirm delete action
    document.querySelectorAll('a.btn-danger').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this driver? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>