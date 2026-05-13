<?php
// assign-trucks.php
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
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit();
}

// Initialize variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$driver_filter = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : 0;
$truck_filter = isset($_GET['truck_id']) ? intval($_GET['truck_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Initialize messages
$message = '';
$error = '';

// Handle truck assignment to driver
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_truck'])) {
    $truck_id = intval($_POST['truck_id'] ?? 0);
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $assignment_notes = trim($_POST['assignment_notes'] ?? '');
    $expected_return = !empty($_POST['expected_return']) ? $_POST['expected_return'] . ' 23:59:59' : null;
    
    if ($truck_id <= 0 || $driver_id <= 0) {
        $error = 'Invalid truck or driver selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if truck is available
            $check_truck = $db->prepare("
                SELECT id, status, driver_id 
                FROM trucks 
                WHERE id = ? AND status = ?
            ");
            $check_truck->execute([$truck_id, TRUCK_AVAILABLE]);
            $truck = $check_truck->fetch();
            
            if (!$truck) {
                throw new Exception('Selected truck is not available');
            }
            
            // Check if driver is available
            $check_driver = $db->prepare("
                SELECT d.id, u.name, d.status 
                FROM drivers d 
                JOIN users u ON d.user_id = u.id 
                WHERE d.id = ? AND d.status IN (?, ?)
            ");
            $check_driver->execute([$driver_id, DRIVER_AVAILABLE, DRIVER_ON_BREAK]);
            $driver = $check_driver->fetch();
            
            if (!$driver) {
                throw new Exception('Selected driver is not available (may be on delivery)');
            }
            
            // Check if driver already has an active truck assignment
            $check_existing_assignment = $db->prepare("
                SELECT id FROM truck_driver_assignments 
                WHERE driver_id = ? AND status = 'active'
            ");
            $check_existing_assignment->execute([$driver_id]);
            
            if ($check_existing_assignment->fetch()) {
                throw new Exception('Driver already has an active truck assignment');
            }
            
            // Check if truck is already assigned to another driver
            if ($truck['driver_id']) {
                throw new Exception('Truck is already assigned to another driver');
            }
            
            // Update truck status and assign to driver
            $update_truck = $db->prepare("
                UPDATE trucks 
                SET driver_id = ?, status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_truck->execute([$driver_id, TRUCK_ASSIGNED, $truck_id]);
            
            // Update driver status if they were on break
            if ($driver['status'] == DRIVER_ON_BREAK) {
                $update_driver = $db->prepare("
                    UPDATE drivers SET status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_driver->execute([DRIVER_AVAILABLE, $driver_id]);
            }
            
            // Create assignment record
            $create_assignment = $db->prepare("
                INSERT INTO truck_driver_assignments 
                (truck_id, driver_id, assignment_notes, assigned_at, status) 
                VALUES (?, ?, ?, NOW(), 'active')
            ");
            $create_assignment->execute([
                $truck_id,
                $driver_id,
                $assignment_notes
            ]);
            
            // Create notification for driver
            $notification_title = 'Truck Assigned';
            $notification_message = "A truck has been assigned to you";
            
            $driver_user = $db->prepare("SELECT user_id FROM drivers WHERE id = ?");
            $driver_user->execute([$driver_id]);
            $driver_user_id = $driver_user->fetchColumn();
            
            if ($driver_user_id) {
                $notification = $db->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, type, related_to, created_at) 
                    VALUES (?, ?, ?, 'driver', ?, NOW())
                ");
                $notification->execute([
                    $driver_user_id,
                    $notification_title,
                    $notification_message,
                    'truck_' . $truck_id
                ]);
            }
            
            $db->commit();
            $message = "Truck successfully assigned to driver";
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $error = 'Assignment failed: ' . $e->getMessage();
        }
    }
}

// Handle unassignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['unassign_truck'])) {
    $assignment_id = intval($_POST['assignment_id'] ?? 0);
    $unassign_notes = trim($_POST['unassign_notes'] ?? '');
    
    if ($assignment_id <= 0) {
        $error = 'Invalid assignment selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Get assignment details
            $get_assignment = $db->prepare("
                SELECT tda.*, t.id as truck_id, d.id as driver_id 
                FROM truck_driver_assignments tda
                JOIN trucks t ON tda.truck_id = t.id
                JOIN drivers d ON tda.driver_id = d.id
                WHERE tda.id = ? AND tda.status = 'active'
            ");
            $get_assignment->execute([$assignment_id]);
            $assignment = $get_assignment->fetch();
            
            if (!$assignment) {
                throw new Exception('Assignment not found or already completed');
            }
            
            // Update truck status and remove driver assignment
            $update_truck = $db->prepare("
                UPDATE trucks 
                SET driver_id = NULL, status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_truck->execute([TRUCK_AVAILABLE, $assignment['truck_id']]);
            
            // Check if driver has any pending orders before changing status
            $check_pending_orders = $db->prepare("
                SELECT COUNT(*) as order_count 
                FROM orders 
                WHERE driver_id = ? AND status_id IN (?, ?, ?, ?)
            ");
            $check_pending_orders->execute([
                $assignment['driver_id'],
                STATUS_ASSIGNED,
                STATUS_PICKED_UP,
                STATUS_IN_TRANSIT,
                STATUS_OUT_FOR_DELIVERY
            ]);
            $pending_orders = $check_pending_orders->fetch();
            
            // Only update driver status if no pending orders
            if ($pending_orders['order_count'] == 0) {
                $update_driver = $db->prepare("
                    UPDATE drivers SET status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_driver->execute([DRIVER_AVAILABLE, $assignment['driver_id']]);
            }
            
            // Update assignment record as completed
            $update_assignment = $db->prepare("
                UPDATE truck_driver_assignments 
                SET status = 'completed', unassigned_at = NOW(),
                    assignment_notes = CONCAT(COALESCE(assignment_notes, ''), '\nUnassigned: ', ?)
                WHERE id = ?
            ");
            $update_assignment->execute([$unassign_notes, $assignment_id]);
            
            // Create notification for driver
            $notification_title = 'Truck Unassigned';
            $notification_message = "Your truck assignment has been removed";
            
            $driver_user = $db->prepare("SELECT user_id FROM drivers WHERE id = ?");
            $driver_user->execute([$assignment['driver_id']]);
            $driver_user_id = $driver_user->fetchColumn();
            
            if ($driver_user_id) {
                $notification = $db->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, type, related_to, created_at) 
                    VALUES (?, ?, ?, 'driver', ?, NOW())
                ");
                $notification->execute([
                    $driver_user_id,
                    $notification_title,
                    $notification_message,
                    'truck_unassigned'
                ]);
            }
            
            $db->commit();
            $message = "Truck successfully unassigned";
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $error = 'Unassignment failed: ' . $e->getMessage();
        }
    }
}

// Get available trucks
$available_trucks = [];
try {
    $trucks_stmt = $db->prepare("
        SELECT t.*, c.name as courier_name 
        FROM trucks t
        LEFT JOIN couriers c ON t.courier_id = c.id
        WHERE t.status = ?
        ORDER BY t.brand, t.model
    ");
    $trucks_stmt->execute([TRUCK_AVAILABLE]);
    $available_trucks = $trucks_stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load trucks: ' . $e->getMessage();
}

// Get available drivers (not on delivery)
$available_drivers = [];
try {
    $drivers_stmt = $db->prepare("
        SELECT d.*, u.name, u.email, u.phone, c.name as courier_name
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN couriers c ON d.courier_id = c.id
        WHERE d.status IN (?, ?) AND u.status = 'active'
        ORDER BY u.name
    ");
    $drivers_stmt->execute([DRIVER_AVAILABLE, DRIVER_ON_BREAK]);
    $available_drivers = $drivers_stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load drivers: ' . $e->getMessage();
}

// Get active assignments
$active_assignments = [];
try {
    $assignments_stmt = $db->prepare("
        SELECT tda.*, 
               t.brand, t.model, t.plate_number, t.capacity,
               d.id as driver_id, u.name as driver_name, d.license_type,
               c.name as courier_name,
               DATE_FORMAT(tda.assigned_at, '%Y-%m-%d %H:%i:%s') as assigned_date
        FROM truck_driver_assignments tda
        JOIN trucks t ON tda.truck_id = t.id
        JOIN drivers d ON tda.driver_id = d.id
        JOIN users u ON d.user_id = u.id
        LEFT JOIN couriers c ON t.courier_id = c.id
        WHERE tda.status = 'active'
        ORDER BY tda.assigned_at DESC
    ");
    $assignments_stmt->execute();
    $active_assignments = $assignments_stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail if table doesn't exist yet
}

// Get assignment history
$assignment_history = [];
try {
    $history_stmt = $db->prepare("
        SELECT tda.*, 
               t.brand, t.model, t.plate_number,
               d.id as driver_id, u.name as driver_name,
               c.name as courier_name,
               DATE_FORMAT(tda.assigned_at, '%Y-%m-%d %H:%i:%s') as assigned_date,
               DATE_FORMAT(tda.unassigned_at, '%Y-%m-%d %H:%i:%s') as unassigned_date
        FROM truck_driver_assignments tda
        JOIN trucks t ON tda.truck_id = t.id
        JOIN drivers d ON tda.driver_id = d.id
        JOIN users u ON d.user_id = u.id
        LEFT JOIN couriers c ON t.courier_id = c.id
        WHERE tda.status = 'completed'
        ORDER BY tda.unassigned_at DESC
        LIMIT 10
    ");
    $history_stmt->execute();
    $assignment_history = $history_stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail if table doesn't exist yet
}

// Get assigned trucks (for filter)
$assigned_trucks = [];
try {
    $assigned_trucks_stmt = $db->query("
        SELECT t.*, u.name as driver_name, c.name as courier_name
        FROM trucks t
        LEFT JOIN drivers d ON t.driver_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        LEFT JOIN couriers c ON t.courier_id = c.id
        WHERE t.status = 'assigned'
        ORDER BY t.brand, t.model
    ");
    $assigned_trucks = $assigned_trucks_stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail
}

// Set page title
$pageTitle = "Assign Trucks";

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
                    <i class="fas fa-truck-loading me-2"></i>Assign Trucks
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Assign trucks to drivers and manage vehicle assignments
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="trucks.php" class="btn btn-outline-primary">
                    <i class="fas fa-truck me-2"></i> Manage Trucks
                </a>
                <a href="drivers.php" class="btn btn-outline-success">
                    <i class="fas fa-users me-2"></i> Manage Drivers
                </a>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column: Available Resources & Assignment Form -->
        <div class="col-lg-4">
            <!-- Assignment Form Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-tasks me-2"></i>Assign Truck to Driver
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="assignTruckForm">
                        <input type="hidden" name="assign_truck" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label">Select Truck *</label>
                            <select class="form-select" name="truck_id" required>
                                <option value="">-- Select Truck --</option>
                                <?php foreach ($available_trucks as $truck): ?>
                                    <option value="<?php echo $truck['id']; ?>">
                                        <?php echo htmlspecialchars($truck['brand'] . ' ' . $truck['model']); ?> 
                                        (<?php echo htmlspecialchars($truck['plate_number']); ?>)
                                        - <?php echo $truck['capacity']; ?> kg
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($available_trucks)): ?>
                                <div class="form-text text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    No trucks available for assignment
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Select Driver *</label>
                            <select class="form-select" name="driver_id" required>
                                <option value="">-- Select Driver --</option>
                                <?php foreach ($available_drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>">
                                        <?php echo htmlspecialchars($driver['name']); ?> 
                                        (<?php echo htmlspecialchars($driver['license_type']); ?>)
                                        <?php if ($driver['status'] == DRIVER_ON_BREAK): ?>
                                            <span class="badge bg-warning">On Break</span>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($available_drivers)): ?>
                                <div class="form-text text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    No drivers available for assignment
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assignment Notes (Optional)</label>
                            <textarea class="form-control" name="assignment_notes" rows="3" placeholder="Enter assignment notes, special instructions, or conditions..."></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" <?php echo (empty($available_trucks) || empty($available_drivers)) ? 'disabled' : ''; ?>>
                                <i class="fas fa-link me-2"></i> Assign Truck
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Statistics Card -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Assignment Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="stat-number text-primary"><?php echo count($available_trucks); ?></div>
                            <div class="stat-label">Available Trucks</div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="stat-number text-success"><?php echo count($available_drivers); ?></div>
                            <div class="stat-label">Available Drivers</div>
                        </div>
                        <div class="col-6">
                            <div class="stat-number text-warning"><?php echo count($active_assignments); ?></div>
                            <div class="stat-label">Active Assignments</div>
                        </div>
                        <div class="col-6">
                            <div class="stat-number text-secondary"><?php echo count($assigned_trucks); ?></div>
                            <div class="stat-label">Assigned Trucks</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Active Assignments & History -->
        <div class="col-lg-8">
            <!-- Active Assignments -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom">
                    <h6 class="mb-0">
                        <i class="fas fa-truck-moving me-2"></i>Active Assignments
                        <span class="badge bg-warning ms-2"><?php echo count($active_assignments); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($active_assignments)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-truck-loading fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No active assignments</h5>
                            <p class="text-muted">Assign a truck to a driver to get started.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Truck</th>
                                        <th>Driver</th>
                                        <th>Assigned On</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($active_assignments as $assignment): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($assignment['plate_number']); ?></small><br>
                                                <small class="text-muted"><?php echo $assignment['capacity']; ?> kg</small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($assignment['driver_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($assignment['license_type']); ?> License</small><br>
                                                <?php if (!empty($assignment['courier_name'])): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-building me-1"></i>
                                                        <?php echo htmlspecialchars($assignment['courier_name']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?><br>
                                                <small class="text-muted">
                                                    <?php echo date('h:i A', strtotime($assignment['assigned_date'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if (!empty($assignment['assignment_notes'])): ?>
                                                    <small><?php echo htmlspecialchars(substr($assignment['assignment_notes'], 0, 50)); ?>...</small>
                                                <?php else: ?>
                                                    <small class="text-muted">No notes</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#viewAssignmentModal<?php echo $assignment['id']; ?>">
                                                                <i class="fas fa-eye me-2"></i> View Details
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#unassignModal<?php echo $assignment['id']; ?>">
                                                                <i class="fas fa-unlink me-2"></i> Unassign
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item" href="trucks-view.php?id=<?php echo $assignment['truck_id']; ?>">
                                                                <i class="fas fa-truck me-2"></i> View Truck
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item" href="drivers-view.php?id=<?php echo $assignment['driver_id']; ?>">
                                                                <i class="fas fa-user me-2"></i> View Driver
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- View Assignment Modal -->
                                        <div class="modal fade" id="viewAssignmentModal<?php echo $assignment['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Assignment Details</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <h6 class="border-bottom pb-2">Truck Information</h6>
                                                                <p>
                                                                    <strong>Vehicle:</strong> <?php echo htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']); ?><br>
                                                                    <strong>Plate Number:</strong> <?php echo htmlspecialchars($assignment['plate_number']); ?><br>
                                                                    <strong>Capacity:</strong> <?php echo $assignment['capacity']; ?> kg<br>
                                                                    <strong>Courier:</strong> <?php echo htmlspecialchars($assignment['courier_name'] ?? 'N/A'); ?>
                                                                </p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <h6 class="border-bottom pb-2">Driver Information</h6>
                                                                <p>
                                                                    <strong>Name:</strong> <?php echo htmlspecialchars($assignment['driver_name']); ?><br>
                                                                    <strong>License Type:</strong> <?php echo htmlspecialchars($assignment['license_type']); ?><br>
                                                                    <strong>Assigned On:</strong> <?php echo date('F j, Y, g:i a', strtotime($assignment['assigned_date'])); ?>
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div class="mt-3">
                                                            <h6 class="border-bottom pb-2">Assignment Notes</h6>
                                                            <p>
                                                                <?php if (!empty($assignment['assignment_notes'])): ?>
                                                                    <?php echo nl2br(htmlspecialchars($assignment['assignment_notes'])); ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">No notes provided</span>
                                                                <?php endif; ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#unassignModal<?php echo $assignment['id']; ?>" data-bs-dismiss="modal">
                                                            <i class="fas fa-unlink me-2"></i> Unassign
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Unassign Modal -->
                                        <div class="modal fade" id="unassignModal<?php echo $assignment['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Unassign Truck</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                                            <input type="hidden" name="unassign_truck" value="1">
                                                            
                                                            <div class="alert alert-warning">
                                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                                Are you sure you want to unassign this truck from the driver?
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Unassignment Reason (Optional)</label>
                                                                <textarea class="form-control" name="unassign_notes" rows="3" placeholder="Enter reason for unassignment..."></textarea>
                                                            </div>
                                                            
                                                            <div class="alert alert-info">
                                                                <small>
                                                                    <i class="fas fa-info-circle me-1"></i>
                                                                    Truck: <?php echo htmlspecialchars($assignment['brand'] . ' ' . $assignment['model']); ?><br>
                                                                    Driver: <?php echo htmlspecialchars($assignment['driver_name']); ?><br>
                                                                    Assigned: <?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger">Unassign Truck</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Assignment History -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-history me-2"></i>Assignment History
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($assignment_history)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-history fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No assignment history available</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Truck</th>
                                        <th>Driver</th>
                                        <th>Assigned</th>
                                        <th>Unassigned</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignment_history as $history): ?>
                                        <tr>
                                            <td>
                                                <small><?php echo htmlspecialchars($history['brand'] . ' ' . $history['model']); ?></small><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($history['plate_number']); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($history['driver_name']); ?></small>
                                            </td>
                                            <td>
                                                <small><?php echo date('M d', strtotime($history['assigned_date'])); ?></small><br>
                                                <small class="text-muted"><?php echo date('h:i A', strtotime($history['assigned_date'])); ?></small>
                                            </td>
                                            <td>
                                                <?php if (!empty($history['unassigned_date'])): ?>
                                                    <small><?php echo date('M d', strtotime($history['unassigned_date'])); ?></small><br>
                                                    <small class="text-muted"><?php echo date('h:i A', strtotime($history['unassigned_date'])); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">N/A</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $history['status'] == 'completed' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($history['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
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
document.getElementById('assignTruckForm')?.addEventListener('submit', function(e) {
    const truckSelect = this.querySelector('select[name="truck_id"]');
    const driverSelect = this.querySelector('select[name="driver_id"]');
    
    if (!truckSelect.value) {
        e.preventDefault();
        alert('Please select a truck.');
        return false;
    }
    
    if (!driverSelect.value) {
        e.preventDefault();
        alert('Please select a driver.');
        return false;
    }
    
    return true;
});

// Show confirmation for unassignment
document.querySelectorAll('form[action=""]').forEach(form => {
    if (form.querySelector('input[name="unassign_truck"]')) {
        form.addEventListener('submit', function(e) {
            const notes = this.querySelector('textarea[name="unassign_notes"]').value;
            if (!confirm('Are you sure you want to unassign this truck? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
            return true;
        });
    }
});

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>