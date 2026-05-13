<?php
// assign-truck.php
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

// Get parameters
$action = $_GET['action'] ?? 'assign'; // assign or reassign
$truck_id = $_GET['truck_id'] ?? 0;
$driver_id = $_GET['driver_id'] ?? 0;

// Initialize variables
$error = '';
$success = '';
$truck = null;
$current_driver = null;
$available_drivers = [];

// Validate truck ID
if (!$truck_id) {
    header('Location: trucks.php?error=no_truck_selected');
    exit();
}

// Get truck details
try {
    $truck_query = "SELECT 
                    t.*, 
                    c.name as courier_name,
                    u.name as current_driver_name,
                    d.id as current_driver_id
                  FROM trucks t
                  LEFT JOIN couriers c ON t.courier_id = c.id
                  LEFT JOIN drivers d ON t.driver_id = d.id
                  LEFT JOIN users u ON d.user_id = u.id
                  WHERE t.id = ?";
    
    $truck_stmt = $db->prepare($truck_query);
    $truck_stmt->execute([$truck_id]);
    $truck = $truck_stmt->fetch();
    
    if (!$truck) {
        header('Location: trucks.php?error=truck_not_found');
        exit();
    }
    
} catch (Exception $e) {
    $error = "Error loading truck details: " . $e->getMessage();
    error_log("Assign truck error: " . $e->getMessage());
}

// Get available drivers (excluding current driver if reassigning)
try {
    if ($action == 'reassign' && isset($truck['current_driver_id']) && $truck['current_driver_id']) {
        $driver_query = "SELECT d.id, u.name, d.license_number, d.status 
                        FROM drivers d 
                        INNER JOIN users u ON d.user_id = u.id 
                        WHERE d.status IN ('available', 'on_delivery')
                        AND d.id != ?
                        ORDER BY u.name";
        $driver_stmt = $db->prepare($driver_query);
        $driver_stmt->execute([$truck['current_driver_id']]);
    } else {
        $driver_query = "SELECT d.id, u.name, d.license_number, d.status 
                        FROM drivers d 
                        INNER JOIN users u ON d.user_id = u.id 
                        WHERE d.status IN ('available', 'on_delivery')
                        ORDER BY u.name";
        $driver_stmt = $db->query($driver_query);
    }
    $available_drivers = $driver_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching drivers: " . $e->getMessage());
    $available_drivers = [];
}

// Get current driver details if exists
if (isset($truck['current_driver_id']) && $truck['current_driver_id']) {
    try {
        $current_driver_query = "SELECT d.*, u.name, u.email, u.phone 
                                FROM drivers d 
                                INNER JOIN users u ON d.user_id = u.id 
                                WHERE d.id = ?";
        $current_driver_stmt = $db->prepare($current_driver_query);
        $current_driver_stmt->execute([$truck['current_driver_id']]);
        $current_driver = $current_driver_stmt->fetch();
    } catch (Exception $e) {
        error_log("Error fetching current driver: " . $e->getMessage());
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_driver_id = $_POST['driver_id'] ?? null;
    $assignment_notes = trim($_POST['assignment_notes'] ?? '');
    
    if (!$new_driver_id) {
        $error = "Please select a driver to assign.";
    } else {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // If truck already has a driver, unassign them first
            if (isset($truck['current_driver_id']) && $truck['current_driver_id']) {
                // Update old driver status to available
                $unassign_driver_query = "UPDATE drivers SET status = 'available' WHERE id = ?";
                $unassign_driver_stmt = $db->prepare($unassign_driver_query);
                $unassign_driver_stmt->execute([$truck['current_driver_id']]);
            }
            
            // Assign new driver
            $assign_driver_query = "UPDATE drivers SET status = 'on_delivery' WHERE id = ?";
            $assign_driver_stmt = $db->prepare($assign_driver_query);
            $assign_driver_stmt->execute([$new_driver_id]);
            
            // Update truck with new driver
            $update_truck_query = "UPDATE trucks SET driver_id = ?, status = 'assigned', updated_at = NOW() WHERE id = ?";
            $update_truck_stmt = $db->prepare($update_truck_query);
            $update_truck_stmt->execute([$new_driver_id, $truck_id]);
            
            // Commit transaction
            $db->commit();
            
            $success = "Driver " . ($action == 'reassign' ? 'reassigned' : 'assigned') . " successfully!";
            
            // Refresh truck data
            $truck_stmt->execute([$truck_id]);
            $truck = $truck_stmt->fetch();
            
            // Refresh available drivers list
            if ($action == 'reassign' && isset($truck['current_driver_id']) && $truck['current_driver_id']) {
                $driver_stmt->execute([$truck['current_driver_id']]);
            } else {
                $driver_stmt = $db->query($driver_query);
            }
            $available_drivers = $driver_stmt->fetchAll();
            
            // Get new driver details for display
            $new_driver_query = "SELECT d.*, u.name, u.email, u.phone 
                               FROM drivers d 
                               INNER JOIN users u ON d.user_id = u.id 
                               WHERE d.id = ?";
            $new_driver_stmt = $db->prepare($new_driver_query);
            $new_driver_stmt->execute([$new_driver_id]);
            $current_driver = $new_driver_stmt->fetch();
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Error assigning driver: " . $e->getMessage();
            error_log("Driver assignment error: " . $e->getMessage());
        }
    }
}

// Handle unassignment if requested
if (isset($_GET['unassign']) && $_GET['unassign'] == '1') {
    if (!isset($truck['current_driver_id']) || !$truck['current_driver_id']) {
        $error = "No driver is currently assigned to this truck.";
    } else {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Update driver status to available
            $unassign_driver_query = "UPDATE drivers SET status = 'available' WHERE id = ?";
            $unassign_driver_stmt = $db->prepare($unassign_driver_query);
            $unassign_driver_stmt->execute([$truck['current_driver_id']]);
            
            // Update truck to remove driver
            $update_truck_query = "UPDATE trucks SET driver_id = NULL, status = 'available', updated_at = NOW() WHERE id = ?";
            $update_truck_stmt = $db->prepare($update_truck_query);
            $update_truck_stmt->execute([$truck_id]);
            
            // Commit transaction
            $db->commit();
            
            $success = "Driver unassigned successfully!";
            
            // Refresh data
            $truck_stmt->execute([$truck_id]);
            $truck = $truck_stmt->fetch();
            $current_driver = null;
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Error unassigning driver: " . $e->getMessage();
            error_log("Driver unassignment error: " . $e->getMessage());
        }
    }
}

$pageTitle = ($action == 'reassign' ? 'Reassign Driver' : 'Assign Driver') . " - " . (isset($truck['plate_number']) ? $truck['plate_number'] : 'Truck');

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
                    <i class="fas fa-user-tie me-2"></i>
                    <?php echo $action == 'reassign' ? 'Reassign Driver' : 'Assign Driver'; ?>
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    <?php echo $action == 'reassign' ? 'Change the driver assigned to this truck' : 'Assign a driver to this truck'; ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="trucks-view.php?id=<?php echo $truck_id; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Truck
                </a>
                <a href="trucks.php" class="btn btn-outline-secondary">
                    <i class="fas fa-truck me-2"></i> All Trucks
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
            <div class="mt-2">
                <a href="trucks-view.php?id=<?php echo $truck_id; ?>" class="btn btn-sm btn-outline-success me-2">
                    <i class="fas fa-eye me-1"></i> View Truck
                </a>
                <a href="assign-truck.php?action=reassign&truck_id=<?php echo $truck_id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-exchange-alt me-1"></i> Reassign Again
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$truck): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Unable to load truck information. Please check if the truck exists.
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-md-8">
                <!-- Truck Information Card -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-truck me-2"></i>Truck Information
                        </h5>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <strong>Plate Number:</strong>
                                    <div class="mt-1">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($truck['plate_number']); ?></span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <strong>Model:</strong>
                                    <div class="mt-1"><?php echo htmlspecialchars($truck['model']); ?></div>
                                </div>
                                <div class="mb-3">
                                    <strong>Capacity:</strong>
                                    <div class="mt-1">
                                        <span class="badge bg-info text-dark"><?php echo $truck['capacity']; ?> tons</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <strong>Current Status:</strong>
                                    <div class="mt-1">
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
                                        <span class="<?php echo $status_class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $truck['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <strong>Courier:</strong>
                                    <div class="mt-1">
                                        <?php if ($truck['courier_name']): ?>
                                            <i class="fas fa-building text-primary me-1"></i>
                                            <?php echo htmlspecialchars($truck['courier_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <strong>Location:</strong>
                                    <div class="mt-1">
                                        <?php echo $truck['current_location'] ? htmlspecialchars($truck['current_location']) : 'Not specified'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Driver Information (for reassignment) -->
                <?php if ($action == 'reassign' && $current_driver): ?>
                    <div class="card shadow-sm border-0 mb-4 border-warning">
                        <div class="card-body">
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-user-clock me-2"></i>Currently Assigned Driver
                            </h5>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <strong>Driver Name:</strong>
                                        <div class="mt-1">
                                            <i class="fas fa-user text-primary me-2"></i>
                                            <?php echo htmlspecialchars($current_driver['name']); ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <strong>License Number:</strong>
                                        <div class="mt-1">
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($current_driver['license_number']); ?></span>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <strong>Experience:</strong>
                                        <div class="mt-1">
                                            <?php echo $current_driver['experience_years']; ?> years
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <strong>Contact:</strong>
                                        <div class="mt-1">
                                            <i class="fas fa-phone text-success me-2"></i>
                                            <?php echo htmlspecialchars($current_driver['phone'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <strong>Email:</strong>
                                        <div class="mt-1">
                                            <i class="fas fa-envelope text-info me-2"></i>
                                            <?php echo htmlspecialchars($current_driver['email'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <strong>Driver Status:</strong>
                                        <div class="mt-1">
                                            <span class="badge bg-<?php echo $current_driver['status'] == 'available' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($current_driver['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <a href="assign-truck.php?action=reassign&truck_id=<?php echo $truck_id; ?>&unassign=1" 
                                   class="btn btn-outline-danger btn-sm"
                                   onclick="return confirm('Are you sure you want to unassign this driver? The driver will be marked as available.');">
                                    <i class="fas fa-user-times me-2"></i> Unassign Driver
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Driver Assignment Form -->
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-user-plus me-2"></i>
                            <?php echo $action == 'reassign' ? 'Select New Driver' : 'Select Driver to Assign'; ?>
                        </h5>
                        
                        <?php if (empty($available_drivers)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No available drivers found. All drivers might be currently assigned or unavailable.
                                <div class="mt-2">
                                    <a href="drivers.php" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-users me-1"></i> View All Drivers
                                    </a>
                                    <a href="drivers-add.php" class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-user-plus me-1"></i> Add New Driver
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" id="assignDriverForm">
                                <div class="mb-4">
                                    <label for="driver_id" class="form-label">Select Driver *</label>
                                    <select class="form-select" id="driver_id" name="driver_id" required>
                                        <option value="">-- Select a driver --</option>
                                        <?php foreach ($available_drivers as $driver): ?>
                                            <option value="<?php echo $driver['id']; ?>" 
                                                    data-status="<?php echo $driver['status']; ?>">
                                                <?php echo htmlspecialchars($driver['name']); ?> 
                                                (License: <?php echo htmlspecialchars($driver['license_number']); ?>)
                                                - Status: <?php echo ucfirst($driver['status']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        Available drivers are shown. Selecting a driver will change their status to "on_delivery".
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="assignment_notes" class="form-label">Assignment Notes</label>
                                    <textarea class="form-control" id="assignment_notes" name="assignment_notes" 
                                              rows="3" placeholder="Add any notes about this assignment (optional)..."></textarea>
                                    <div class="form-text">
                                        These notes will be recorded in the assignment log.
                                    </div>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Important:</strong> Assigning a driver will automatically:
                                    <ul class="mb-0 mt-2">
                                        <li>Change the driver's status to "on_delivery"</li>
                                        <li>Change the truck's status to "assigned"</li>
                                        <?php if ($action == 'reassign' && isset($current_driver) && $current_driver): ?>
                                            <li>Unassign the current driver and mark them as "available"</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="trucks-view.php?id=<?php echo $truck_id; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-check me-2"></i>
                                        <?php echo $action == 'reassign' ? 'Reassign Driver' : 'Assign Driver'; ?>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Quick Stats -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-chart-bar me-2"></i>Assignment Statistics
                        </h5>
                        
                        <?php
                        // Simplified statistics without causing errors
                        try {
                            // Get driver stats
                            $driver_stats_query = "SELECT 
                                COUNT(*) as total_drivers,
                                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_drivers,
                                SUM(CASE WHEN status = 'on_delivery' THEN 1 ELSE 0 END) as assigned_drivers
                                FROM drivers";
                            $driver_stats_stmt = $db->query($driver_stats_query);
                            $driver_stats = $driver_stats_stmt->fetch();
                            
                            // Get truck stats
                            $truck_stats_query = "SELECT 
                                COUNT(*) as total_trucks,
                                SUM(CASE WHEN driver_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_trucks,
                                SUM(CASE WHEN driver_id IS NULL THEN 1 ELSE 0 END) as unassigned_trucks
                                FROM trucks";
                            $truck_stats_stmt = $db->query($truck_stats_query);
                            $truck_stats = $truck_stats_stmt->fetch();
                        } catch (Exception $e) {
                            $driver_stats = ['total_drivers' => 0, 'available_drivers' => 0, 'assigned_drivers' => 0];
                            $truck_stats = ['total_trucks' => 0, 'assigned_trucks' => 0, 'unassigned_trucks' => 0];
                        }
                        ?>
                        
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Available Drivers:</span>
                                <span class="badge bg-success"><?php echo $driver_stats['available_drivers']; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Assigned Drivers:</span>
                                <span class="badge bg-warning"><?php echo $driver_stats['assigned_drivers']; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Unassigned Trucks:</span>
                                <span class="badge bg-info"><?php echo $truck_stats['unassigned_trucks']; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Total Assignments:</span>
                                <span class="badge bg-primary"><?php echo $truck_stats['assigned_trucks']; ?></span>
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
                            <a href="drivers-add.php" class="btn btn-outline-success">
                                <i class="fas fa-user-plus me-2"></i> Add New Driver
                            </a>
                            <a href="drivers.php?status=available" class="btn btn-outline-info">
                                <i class="fas fa-users me-2"></i> View Available Drivers
                            </a>
                            <a href="trucks.php?status=available" class="btn btn-outline-warning">
                                <i class="fas fa-truck me-2"></i> View Available Trucks
                            </a>
                        </div>
                    </div>
                </div>
            </div>
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
    document.getElementById('assignDriverForm').addEventListener('submit', function(e) {
        const driverSelect = document.getElementById('driver_id');
        const selectedDriver = driverSelect.value;
        const selectedOption = driverSelect.options[driverSelect.selectedIndex];
        const driverStatus = selectedOption.getAttribute('data-status');
        
        if (!selectedDriver) {
            e.preventDefault();
            alert('Please select a driver to assign.');
            return false;
        }
        
        // Warn if driver is already on delivery
        if (driverStatus === 'on_delivery') {
            const driverName = selectedOption.text.split('(')[0].trim();
            if (!confirm('This driver is currently marked as "on_delivery". Are you sure you want to reassign them to this truck?\n\nDriver: ' + driverName)) {
                e.preventDefault();
                return false;
            }
        }
        
        // Additional confirmation for reassignment
        <?php if ($action == 'reassign' && isset($current_driver) && $current_driver): ?>
            if (!confirm('Are you sure you want to reassign this truck? The current driver will be unassigned and marked as available.')) {
                e.preventDefault();
                return false;
            }
        <?php else: ?>
            if (!confirm('Are you sure you want to assign this driver to the truck?')) {
                e.preventDefault();
                return false;
            }
        <?php endif; ?>
    });

    // Update driver status display based on selection
    document.getElementById('driver_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const driverStatus = selectedOption.getAttribute('data-status');
        const notesField = document.getElementById('assignment_notes');
        
        if (driverStatus === 'on_delivery' && notesField && !notesField.value.includes('Reassigning from another truck')) {
            notesField.value = 'Reassigning from another truck. ' + notesField.value;
        }
    });
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>