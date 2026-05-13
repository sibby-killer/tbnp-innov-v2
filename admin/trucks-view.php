<?php
// trucks-view.php
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

// Get truck ID from URL
$truck_id = $_GET['id'] ?? 0;
if (!$truck_id) {
    header('Location: trucks.php');
    exit();
}

// Initialize variables
$truck = null;
$error = '';

// Get truck details with related information
try {
    $query = "SELECT 
                t.*, 
                c.name as courier_name,
                c.email as courier_email,
                c.phone as courier_phone,
                u.name as driver_name,
                u.email as driver_email,
                u.phone as driver_phone,
                d.license_number,
                d.license_type,
                d.experience_years,
                d.status as driver_status
              FROM trucks t
              LEFT JOIN couriers c ON t.courier_id = c.id
              LEFT JOIN drivers d ON t.driver_id = d.id
              LEFT JOIN users u ON d.user_id = u.id
              WHERE t.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$truck_id]);
    $truck = $stmt->fetch();
    
    if (!$truck) {
        header('Location: trucks.php?error=truck_not_found');
        exit();
    }
    
} catch (Exception $e) {
    $error = "Error loading truck details: " . $e->getMessage();
    error_log("Truck view error: " . $e->getMessage());
}

$pageTitle = "Truck Details - " . ($truck['plate_number'] ?? 'Unknown Truck');

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
                    <i class="fas fa-truck me-2"></i>Truck Details
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    View detailed information about this truck
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="trucks.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Trucks
                </a>
                <a href="trucks-edit.php?id=<?php echo $truck_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit me-2"></i> Edit Truck
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

    <?php if ($truck): ?>
        <!-- Truck Information Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-4">
                            <div class="me-3">
                                <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #306998, #4B8BBE); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem;">
                                    <i class="fas fa-truck"></i>
                                </div>
                            </div>
                            <div>
                                <h4 class="mb-1"><?php echo htmlspecialchars($truck['plate_number']); ?></h4>
                                <p class="text-muted mb-0">
                                    <?php echo htmlspecialchars($truck['model'] ?? 'Unknown Model'); ?> 
                                    <?php if ($truck['brand']): ?>
                                        by <?php echo htmlspecialchars($truck['brand']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Status and Basic Info -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <strong>Status:</strong>
                                    <?php
                                    $status_class = '';
                                    switch ($truck['status']) {
                                        case 'available':
                                            $status_class = 'badge bg-success';
                                            break;
                                        case 'assigned':
                                            $status_class = 'badge bg-primary';
                                            break;
                                        case 'on_delivery':
                                            $status_class = 'badge bg-warning';
                                            break;
                                        case 'maintenance':
                                            $status_class = 'badge bg-danger';
                                            break;
                                        case 'out_of_service':
                                            $status_class = 'badge bg-secondary';
                                            break;
                                        default:
                                            $status_class = 'badge bg-light text-dark';
                                    }
                                    ?>
                                    <span class="<?php echo $status_class; ?> ms-2">
                                        <?php echo ucfirst(str_replace('_', ' ', $truck['status'])); ?>
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Capacity:</strong>
                                    <span class="badge bg-info text-dark ms-2">
                                        <?php echo $truck['capacity']; ?> tons
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Fuel Type:</strong>
                                    <span class="ms-2">
                                        <?php echo ucfirst($truck['fuel_type'] ?? 'N/A'); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <strong>Manufacturing Year:</strong>
                                    <span class="ms-2"><?php echo $truck['year'] ?? 'N/A'; ?></span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Current Location:</strong>
                                    <span class="ms-2">
                                        <?php echo $truck['current_location'] ? htmlspecialchars($truck['current_location']) : 'Not specified'; ?>
                                    </span>
                                </div>
                                
                                <div class="mb-3">
                                    <strong>Courier:</strong>
                                    <span class="ms-2">
                                        <?php if ($truck['courier_name']): ?>
                                            <i class="fas fa-building text-primary me-1"></i>
                                            <?php echo htmlspecialchars($truck['courier_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Driver Information -->
                        <div class="mb-4">
                            <h5 class="mb-3" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-user-tie me-2"></i>Driver Information
                            </h5>
                            <?php if ($truck['driver_name']): ?>
                                <div class="card bg-light border-0">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p class="mb-2"><strong>Driver:</strong> <?php echo htmlspecialchars($truck['driver_name']); ?></p>
                                                <p class="mb-2"><strong>Email:</strong> <?php echo htmlspecialchars($truck['driver_email'] ?? 'N/A'); ?></p>
                                                <p class="mb-2"><strong>Phone:</strong> <?php echo htmlspecialchars($truck['driver_phone'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p class="mb-2"><strong>License:</strong> <?php echo htmlspecialchars($truck['license_number'] ?? 'N/A'); ?></p>
                                                <p class="mb-2"><strong>License Type:</strong> Class <?php echo htmlspecialchars($truck['license_type'] ?? 'N/A'); ?></p>
                                                <p class="mb-2"><strong>Experience:</strong> <?php echo htmlspecialchars($truck['experience_years'] ?? 0); ?> years</p>
                                                <p class="mb-0"><strong>Status:</strong> 
                                                    <span class="badge bg-<?php echo ($truck['driver_status'] ?? '') == 'available' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($truck['driver_status'] ?? 'N/A'); ?>
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    No driver assigned to this truck.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Quick Info Card -->
                        <div class="card border-0 mb-4" style="background: linear-gradient(135deg, #f8f9fa, #e9ecef);">
                            <div class="card-body">
                                <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                                    <i class="fas fa-info-circle me-2"></i>Quick Info
                                </h5>
                                
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                        <span>Truck ID:</span>
                                        <strong>#<?php echo $truck['id']; ?></strong>
                                    </div>
                                    <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                        <span>Created:</span>
                                        <span><?php echo date('M d, Y', strtotime($truck['created_at'])); ?></span>
                                    </div>
                                    <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                        <span>Last Updated:</span>
                                        <span><?php echo date('M d, Y', strtotime($truck['updated_at'])); ?></span>
                                    </div>
                                    <?php if ($truck['last_maintenance']): ?>
                                        <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                            <span>Last Maintenance:</span>
                                            <span><?php echo date('M d, Y', strtotime($truck['last_maintenance'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Insurance Status -->
                                <div class="mt-4">
                                    <h6 class="mb-2">Insurance Status</h6>
                                    <?php if ($truck['insurance_number']): ?>
                                        <div class="alert alert-success">
                                            <i class="fas fa-shield-alt me-2"></i>
                                            <strong>Insured</strong><br>
                                            <small>Number: <?php echo htmlspecialchars($truck['insurance_number']); ?></small>
                                            <?php if ($truck['insurance_expiry']): ?>
                                                <?php
                                                $expiry_date = new DateTime($truck['insurance_expiry']);
                                                $today = new DateTime();
                                                $days_diff = $today->diff($expiry_date)->days;
                                                
                                                if ($days_diff <= 30) {
                                                    $alert_class = 'alert-danger';
                                                    $message = 'Expires soon!';
                                                } elseif ($days_diff <= 60) {
                                                    $alert_class = 'alert-warning';
                                                    $message = 'Expiring soon';
                                                } else {
                                                    $alert_class = 'alert-success';
                                                    $message = 'Active';
                                                }
                                                ?>
                                                <div class="mt-2 alert <?php echo $alert_class; ?> p-2 small">
                                                    <?php echo $message; ?>: <?php echo date('M d, Y', strtotime($truck['insurance_expiry'])); ?>
                                                    (<?php echo $days_diff; ?> days left)
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-danger">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No insurance information
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <a href="trucks-edit.php?id=<?php echo $truck_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-2"></i> Edit Truck
                    </a>
                    <?php if ($truck['driver_id']): ?>
                        <a href="assign-truck.php?action=reassign&truck_id=<?php echo $truck_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-exchange-alt me-2"></i> Reassign Driver
                        </a>
                    <?php else: ?>
                        <a href="assign-truck.php?truck_id=<?php echo $truck_id; ?>" class="btn btn-success">
                            <i class="fas fa-user-plus me-2"></i> Assign Driver
                        </a>
                    <?php endif; ?>
                    <a href="trucks-maintenance.php?truck_id=<?php echo $truck_id; ?>" class="btn btn-info">
                        <i class="fas fa-tools me-2"></i> Maintenance
                    </a>
                    <a href="trucks.php?action=delete&id=<?php echo $truck_id; ?>" 
                       class="btn btn-danger"
                       onclick="return confirm('Are you sure you want to delete this truck? This action cannot be undone.');">
                        <i class="fas fa-trash me-2"></i> Delete Truck
                    </a>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="row">
            <!-- Courier Information -->
            <div class="col-md-6">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-building me-2"></i>Courier Information
                        </h5>
                        <?php if ($truck['courier_name']): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Courier Name:</strong></p>
                                    <p class="mb-2"><strong>Email:</strong></p>
                                    <p class="mb-2"><strong>Phone:</strong></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><?php echo htmlspecialchars($truck['courier_name']); ?></p>
                                    <p class="mb-2"><?php echo htmlspecialchars($truck['courier_email'] ?? 'N/A'); ?></p>
                                    <p class="mb-2"><?php echo htmlspecialchars($truck['courier_phone'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="couriers-view.php?id=<?php echo $truck['courier_id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt me-1"></i> View Courier Details
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                This truck is not assigned to any courier.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Notes and Additional Info -->
            <div class="col-md-6">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-sticky-note me-2"></i>Notes & Additional Information
                        </h5>
                        <?php if ($truck['notes']): ?>
                            <div class="bg-light p-3 rounded">
                                <?php echo nl2br(htmlspecialchars($truck['notes'])); ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No additional notes available.</p>
                        <?php endif; ?>
                        
                        <!-- Quick Actions -->
                        <div class="mt-4">
                            <h6 class="mb-3">Quick Actions</h6>
                            <div class="d-grid gap-2">
                                <a href="orders.php?truck=<?php echo $truck_id; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-box me-2"></i> View Truck's Orders
                                </a>
                                <a href="maintenance-log.php?truck=<?php echo $truck_id; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-clipboard-list me-2"></i> Maintenance History
                                </a>
                                <a href="reports.php?truck=<?php echo $truck_id; ?>" class="btn btn-outline-info">
                                    <i class="fas fa-chart-line me-2"></i> Performance Report
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fuel & Maintenance Status -->
        <div class="row">
            <div class="col-md-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-chart-bar me-2"></i>Status Overview
                        </h5>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="fas fa-gas-pump fa-2x text-primary"></i>
                                    </div>
                                    <h6>Fuel Type</h6>
                                    <p class="mb-0"><?php echo ucfirst($truck['fuel_type'] ?? 'N/A'); ?></p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="fas fa-weight-hanging fa-2x text-success"></i>
                                    </div>
                                    <h6>Capacity</h6>
                                    <p class="mb-0"><?php echo $truck['capacity']; ?> tons</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <i class="fas fa-calendar-check fa-2x text-info"></i>
                                    </div>
                                    <h6>Manufacturing Year</h6>
                                    <p class="mb-0"><?php echo $truck['year'] ?? 'N/A'; ?></p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <div class="mb-2">
                                        <?php
                                        $maintenance_icon = 'fa-calendar-alt';
                                        $maintenance_color = 'text-warning';
                                        if ($truck['last_maintenance']) {
                                            $last_maintenance = new DateTime($truck['last_maintenance']);
                                            $today = new DateTime();
                                            $months_diff = $today->diff($last_maintenance)->m;
                                            
                                            if ($months_diff >= 6) {
                                                $maintenance_icon = 'fa-exclamation-triangle';
                                                $maintenance_color = 'text-danger';
                                            } elseif ($months_diff >= 3) {
                                                $maintenance_icon = 'fa-calendar-check';
                                                $maintenance_color = 'text-warning';
                                            } else {
                                                $maintenance_icon = 'fa-calendar-alt';
                                                $maintenance_color = 'text-success';
                                            }
                                        }
                                        ?>
                                        <i class="fas <?php echo $maintenance_icon; ?> fa-2x <?php echo $maintenance_color; ?>"></i>
                                    </div>
                                    <h6>Last Maintenance</h6>
                                    <p class="mb-0">
                                        <?php echo $truck['last_maintenance'] ? date('M d, Y', strtotime($truck['last_maintenance'])) : 'Never'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Truck not found. Please check the truck ID and try again.
        </div>
        <div class="text-center mt-4">
            <a href="trucks.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i> Back to Trucks List
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
            if (!confirm('Are you sure you want to delete this truck? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // Calculate insurance days left
    function calculateInsuranceDays(expiryDate) {
        const today = new Date();
        const expiry = new Date(expiryDate);
        const timeDiff = expiry.getTime() - today.getTime();
        return Math.ceil(timeDiff / (1000 * 3600 * 24));
    }

    // Update insurance status dynamically
    const insuranceExpiry = document.querySelector('.insurance-expiry-date');
    if (insuranceExpiry) {
        const expiryDate = insuranceExpiry.textContent;
        const daysLeft = calculateInsuranceDays(expiryDate);
        
        const insuranceBadge = document.querySelector('.insurance-status');
        if (daysLeft <= 30) {
            insuranceBadge.className = 'alert alert-danger';
        } else if (daysLeft <= 60) {
            insuranceBadge.className = 'alert alert-warning';
        } else {
            insuranceBadge.className = 'alert alert-success';
        }
    }
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>