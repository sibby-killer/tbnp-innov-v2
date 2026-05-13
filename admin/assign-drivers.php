<?php
// assign-drivers.php
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
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$truck_filter = isset($_GET['truck_id']) ? intval($_GET['truck_id']) : 0;
$driver_filter = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : 0;
$assignment_filter = isset($_GET['assignment_status']) ? $_GET['assignment_status'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Initialize messages
$message = '';
$error = '';

// Handle truck-driver assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_truck'])) {
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $truck_id = intval($_POST['truck_id'] ?? 0);
    $assignment_notes = trim($_POST['assignment_notes'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $fuel_level = !empty($_POST['fuel_level']) ? intval($_POST['fuel_level']) : null;
    $odometer = !empty($_POST['odometer']) ? intval($_POST['odometer']) : null;
    
    if ($driver_id <= 0 || $truck_id <= 0) {
        $error = 'Invalid driver or truck selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if driver exists and is available
            $check_driver = $db->prepare("
                SELECT d.id, d.status as driver_status, u.name as driver_name
                FROM drivers d 
                JOIN users u ON d.user_id = u.id 
                WHERE d.id = ? AND d.status = ?
            ");
            $check_driver->execute([$driver_id, DRIVER_AVAILABLE]);
            $driver = $check_driver->fetch();
            
            if (!$driver) {
                throw new Exception('Driver not found or not available');
            }
            
            // Check if truck exists and is available
            $check_truck = $db->prepare("
                SELECT id, brand, model, plate_number, status as truck_status 
                FROM trucks 
                WHERE id = ? AND status = ?
            ");
            $check_truck->execute([$truck_id, TRUCK_AVAILABLE]);
            $truck = $check_truck->fetch();
            
            if (!$truck) {
                throw new Exception('Truck not found or not available');
            }
            
            // Check if driver already has active assignment
            $check_driver_assignment = $db->prepare("
                SELECT id FROM truck_driver_assignments 
                WHERE driver_id = ? AND status = 'active'
            ");
            $check_driver_assignment->execute([$driver_id]);
            
            if ($check_driver_assignment->fetch()) {
                throw new Exception('Driver already has an active truck assignment');
            }
            
            // Check if truck already has active assignment
            $check_truck_assignment = $db->prepare("
                SELECT id FROM truck_driver_assignments 
                WHERE truck_id = ? AND status = 'active'
            ");
            $check_truck_assignment->execute([$truck_id]);
            
            if ($check_truck_assignment->fetch()) {
                throw new Exception('Truck already assigned to another driver');
            }
            
            // Create truck-driver assignment
            $create_assignment = $db->prepare("
                INSERT INTO truck_driver_assignments 
                (truck_id, driver_id, assignment_notes, status, assigned_at) 
                VALUES (?, ?, ?, 'active', NOW())
            ");
            $create_assignment->execute([$truck_id, $driver_id, $assignment_notes]);
            $assignment_id = $db->lastInsertId();
            
            // Update driver status to ON_DELIVERY
            $update_driver = $db->prepare("
                UPDATE drivers SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_driver->execute([DRIVER_ON_DELIVERY, $driver_id]);
            
            // Update truck status to ASSIGNED
            $update_truck = $db->prepare("
                UPDATE trucks SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_truck->execute([TRUCK_ASSIGNED, $truck_id]);
            
            // Log status change
            $log_status = $db->prepare("
                INSERT INTO truck_status_logs 
                (truck_id, driver_id, old_status, new_status, location, fuel_level, odometer, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $log_status->execute([
                $truck_id,
                $driver_id,
                TRUCK_AVAILABLE,
                TRUCK_ASSIGNED,
                $location,
                $fuel_level,
                $odometer,
                "Assigned to driver: " . $driver['driver_name']
            ]);
            
            // Create notification for driver
            $notification_title = 'Truck Assigned';
            $notification_message = "You have been assigned Truck #" . $truck['plate_number'] . " (" . $truck['brand'] . " " . $truck['model'] . ")";
            
            $driver_user = $db->prepare("SELECT user_id FROM drivers WHERE id = ?");
            $driver_user->execute([$driver_id]);
            $driver_user_id = $driver_user->fetchColumn();
            
            if ($driver_user_id) {
                $notification = $db->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, type, related_to, created_at) 
                    VALUES (?, ?, ?, 'truck_assignment', ?, NOW())
                ");
                $notification->execute([
                    $driver_user_id,
                    $notification_title,
                    $notification_message,
                    'assignment_' . $assignment_id
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
    $unassignment_notes = trim($_POST['unassignment_notes'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $fuel_level = !empty($_POST['fuel_level']) ? intval($_POST['fuel_level']) : null;
    $odometer = !empty($_POST['odometer']) ? intval($_POST['odometer']) : null;
    
    if ($assignment_id <= 0) {
        $error = 'Invalid assignment selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Get assignment details
            $get_assignment = $db->prepare("
                SELECT tda.*, t.id as truck_id, t.plate_number, t.brand, t.model,
                       d.id as driver_id, du.name as driver_name
                FROM truck_driver_assignments tda
                JOIN trucks t ON tda.truck_id = t.id
                JOIN drivers d ON tda.driver_id = d.id
                JOIN users du ON d.user_id = du.id
                WHERE tda.id = ? AND tda.status = 'active'
            ");
            $get_assignment->execute([$assignment_id]);
            $assignment = $get_assignment->fetch();
            
            if (!$assignment) {
                throw new Exception('Active assignment not found');
            }
            
            // Update assignment status to completed
            $update_assignment = $db->prepare("
                UPDATE truck_driver_assignments 
                SET status = 'completed', unassigned_at = NOW() 
                WHERE id = ?
            ");
            $update_assignment->execute([$assignment_id]);
            
            // Update driver status to AVAILABLE
            $update_driver = $db->prepare("
                UPDATE drivers SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_driver->execute([DRIVER_AVAILABLE, $assignment['driver_id']]);
            
            // Update truck status to AVAILABLE
            $update_truck = $db->prepare("
                UPDATE trucks SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_truck->execute([TRUCK_AVAILABLE, $assignment['truck_id']]);
            
            // Log status change
            $log_status = $db->prepare("
                INSERT INTO truck_status_logs 
                (truck_id, driver_id, old_status, new_status, location, fuel_level, odometer, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $log_status->execute([
                $assignment['truck_id'],
                $assignment['driver_id'],
                TRUCK_ASSIGNED,
                TRUCK_AVAILABLE,
                $location,
                $fuel_level,
                $odometer,
                "Unassigned from driver: " . $assignment['driver_name'] . ". Notes: " . $unassignment_notes
            ]);
            
            // Create notification for driver
            $notification_title = 'Truck Unassigned';
            $notification_message = "Truck #" . $assignment['plate_number'] . " has been unassigned from you";
            
            $driver_user = $db->prepare("SELECT user_id FROM drivers WHERE id = ?");
            $driver_user->execute([$assignment['driver_id']]);
            $driver_user_id = $driver_user->fetchColumn();
            
            if ($driver_user_id) {
                $notification = $db->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, type, related_to, created_at) 
                    VALUES (?, ?, ?, 'truck_assignment', ?, NOW())
                ");
                $notification->execute([
                    $driver_user_id,
                    $notification_title,
                    $notification_message,
                    'assignment_' . $assignment_id
                ]);
            }
            
            $db->commit();
            $message = "Truck successfully unassigned from driver";
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $error = 'Unassignment failed: ' . $e->getMessage();
        }
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_truck_status'])) {
    $truck_id = intval($_POST['truck_id'] ?? 0);
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $fuel_level = !empty($_POST['fuel_level']) ? intval($_POST['fuel_level']) : null;
    $odometer = !empty($_POST['odometer']) ? intval($_POST['odometer']) : null;
    $status_notes = trim($_POST['status_notes'] ?? '');
    
    if ($truck_id <= 0 || $driver_id <= 0 || empty($new_status)) {
        $error = 'Invalid truck, driver or status selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Get current truck status
            $get_truck = $db->prepare("
                SELECT status as old_status FROM trucks WHERE id = ?
            ");
            $get_truck->execute([$truck_id]);
            $truck = $get_truck->fetch();
            
            if (!$truck) {
                throw new Exception('Truck not found');
            }
            
            // Get driver name
            $get_driver = $db->prepare("
                SELECT u.name FROM drivers d
                JOIN users u ON d.user_id = u.id
                WHERE d.id = ?
            ");
            $get_driver->execute([$driver_id]);
            $driver = $get_driver->fetch();
            
            // Update truck status
            $update_truck = $db->prepare("
                UPDATE trucks SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_truck->execute([$new_status, $truck_id]);
            
            // Log status change
            $log_status = $db->prepare("
                INSERT INTO truck_status_logs 
                (truck_id, driver_id, old_status, new_status, location, fuel_level, odometer, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $log_status->execute([
                $truck_id,
                $driver_id,
                $truck['old_status'],
                $new_status,
                $location,
                $fuel_level,
                $odometer,
                "Status updated by admin. Driver: " . ($driver['name'] ?? 'Unknown') . ". Notes: " . $status_notes
            ]);
            
            $db->commit();
            $message = "Truck status updated successfully";
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $error = 'Status update failed: ' . $e->getMessage();
        }
    }
}

// Handle driver status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_driver_status'])) {
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $new_status = trim($_POST['new_status'] ?? '');
    $status_notes = trim($_POST['status_notes'] ?? '');
    
    if ($driver_id <= 0 || !in_array($new_status, [DRIVER_AVAILABLE, DRIVER_ON_DELIVERY, DRIVER_INACTIVE, DRIVER_ON_BREAK])) {
        $error = 'Invalid driver or status selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if driver exists
            $check_driver = $db->prepare("
                SELECT d.id, d.status as old_status, u.name 
                FROM drivers d 
                JOIN users u ON d.user_id = u.id 
                WHERE d.id = ?
            ");
            $check_driver->execute([$driver_id]);
            $driver = $check_driver->fetch();
            
            if (!$driver) {
                throw new Exception('Driver not found');
            }
            
            // Update driver status
            $update_driver = $db->prepare("
                UPDATE drivers 
                SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_driver->execute([$new_status, $driver_id]);
            
            // Create notification for driver
            $driver_status_names = [
                DRIVER_AVAILABLE => 'Available',
                DRIVER_ON_DELIVERY => 'On Delivery',
                DRIVER_INACTIVE => 'Inactive',
                DRIVER_ON_BREAK => 'On Break'
            ];
            
            $notification_title = 'Status Updated';
            $notification_message = "Your status has been changed to: " . ($driver_status_names[$new_status] ?? $new_status);
            
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
                    'driver_' . $driver_id
                ]);
            }
            
            $db->commit();
            $message = "Driver status updated successfully";
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $error = 'Status update failed: ' . $e->getMessage();
        }
    }
}

// Get available drivers (not currently assigned to a truck)
$available_drivers = [];
try {
    $drivers_stmt = $db->prepare("
        SELECT d.id, u.name, d.license_type, d.experience_years, 
               d.status, d.phone_number
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        WHERE d.status = ? 
          AND d.id NOT IN (
              SELECT driver_id FROM truck_driver_assignments 
              WHERE status = 'active'
          )
        ORDER BY u.name
    ");
    $drivers_stmt->execute([DRIVER_AVAILABLE]);
    $available_drivers = $drivers_stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load drivers: ' . $e->getMessage();
}

// Get available trucks (not currently assigned)
$available_trucks = [];
try {
    $trucks_stmt = $db->prepare("
        SELECT id, brand, model, plate_number, capacity, status 
        FROM trucks 
        WHERE status = ?
          AND id NOT IN (
              SELECT truck_id FROM truck_driver_assignments 
              WHERE status = 'active'
          )
        ORDER BY brand, model
    ");
    $trucks_stmt->execute([TRUCK_AVAILABLE]);
    $available_trucks = $trucks_stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load trucks: ' . $e->getMessage();
}

// Get active assignments
$active_assignments = [];
try {
    $assignments_stmt = $db->prepare("
        SELECT tda.*, 
               t.brand as truck_brand, t.model as truck_model, 
               t.plate_number as truck_plate, t.capacity,
               u.name as driver_name, d.phone_number as driver_phone,
               d.license_type, d.license_number,
               (SELECT COUNT(*) FROM orders WHERE truck_id = t.id AND status_id NOT IN (?, ?)) as active_orders
        FROM truck_driver_assignments tda
        JOIN trucks t ON tda.truck_id = t.id
        JOIN drivers d ON tda.driver_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE tda.status = 'active'
        ORDER BY tda.assigned_at DESC
    ");
    $assignments_stmt->execute([STATUS_DELIVERED, STATUS_CANCELLED]);
    $active_assignments = $assignments_stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail - assignments might be empty
}

// Build query for drivers
$query_params = [];
$query_conditions = ["u.status = 'active'"];

// Build search conditions
if (!empty($search)) {
    $query_conditions[] = "(u.name LIKE ? OR 
                           u.email LIKE ? OR 
                           u.phone LIKE ? OR 
                           d.license_number LIKE ? OR
                           t.plate_number LIKE ?)";
    $search_term = "%$search%";
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

// Build status filter
if (!empty($status_filter)) {
    $query_conditions[] = "d.status = ?";
    $query_params[] = $status_filter;
}

// Build truck filter
if (!empty($truck_filter)) {
    $query_conditions[] = "t.id = ?";
    $query_params[] = $truck_filter;
}

// Build driver filter
if (!empty($driver_filter)) {
    $query_conditions[] = "d.id = ?";
    $query_params[] = $driver_filter;
}

// Build WHERE clause
$where_clause = '';
if (!empty($query_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $query_conditions);
}

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total 
    FROM drivers d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN truck_driver_assignments tda ON d.id = tda.driver_id AND tda.status = 'active'
    LEFT JOIN trucks t ON tda.truck_id = t.id
    $where_clause
";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($query_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $limit);

// Get drivers with their assignments
$query = "
    SELECT d.*, 
           u.name, u.email, u.profile_image,
           tda.id as assignment_id,
           tda.assigned_at, tda.assignment_notes,
           t.id as truck_id, t.brand as truck_brand, 
           t.model as truck_model, t.plate_number as truck_plate,
           t.status as truck_status
    FROM drivers d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN truck_driver_assignments tda ON d.id = tda.driver_id AND tda.status = 'active'
    LEFT JOIN trucks t ON tda.truck_id = t.id
    $where_clause
    ORDER BY 
        CASE WHEN tda.id IS NULL THEN 1 ELSE 0 END,
        u.name
    LIMIT ? OFFSET ?
";

// Add limit and offset to params
$query_params[] = $limit;
$query_params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($query_params);
$drivers = $stmt->fetchAll();

// Get driver status names
$driver_status_names = [
    DRIVER_AVAILABLE => 'Available',
    DRIVER_ON_DELIVERY => 'On Delivery',
    DRIVER_INACTIVE => 'Inactive',
    DRIVER_ON_BREAK => 'On Break'
];

$driver_status_colors = [
    DRIVER_AVAILABLE => 'success',
    DRIVER_ON_DELIVERY => 'warning',
    DRIVER_INACTIVE => 'secondary',
    DRIVER_ON_BREAK => 'info'
];

// Get truck status names - CORRECTED to match your constants
$truck_status_names = [
    TRUCK_AVAILABLE => 'Available',
    TRUCK_ASSIGNED => 'Assigned',
    TRUCK_MAINTENANCE => 'Maintenance',
    TRUCK_OUT_OF_SERVICE => 'Out of Service'
];

$truck_status_colors = [
    TRUCK_AVAILABLE => 'success',
    TRUCK_ASSIGNED => 'warning',
    TRUCK_MAINTENANCE => 'info',
    TRUCK_OUT_OF_SERVICE => 'danger'
];

// Get all trucks for filter
$all_trucks = $db->query("
    SELECT id, brand, model, plate_number 
    FROM trucks 
    ORDER BY brand, model
")->fetchAll();

// Get all drivers for filter
$all_drivers = $db->query("
    SELECT d.id, u.name 
    FROM drivers d 
    JOIN users u ON d.user_id = u.id 
    ORDER BY u.name
")->fetchAll();

// Get assignment history
$history_query = "
    SELECT tda.*, 
           t.brand as truck_brand, t.model as truck_model, t.plate_number,
           u.name as driver_name
    FROM truck_driver_assignments tda
    JOIN trucks t ON tda.truck_id = t.id
    JOIN drivers d ON tda.driver_id = d.id
    JOIN users u ON d.user_id = u.id
    WHERE tda.status = 'completed'
    ORDER BY tda.unassigned_at DESC
    LIMIT 10
";
$history_stmt = $db->prepare($history_query);
$history_stmt->execute();
$assignment_history = $history_stmt->fetchAll();

// Get truck status logs
$status_logs_query = "
    SELECT tsl.*, 
           t.plate_number, t.brand, t.model,
           u.name as driver_name
    FROM truck_status_logs tsl
    JOIN trucks t ON tsl.truck_id = t.id
    LEFT JOIN drivers d ON tsl.driver_id = d.id
    LEFT JOIN users u ON d.user_id = u.id
    ORDER BY tsl.changed_at DESC
    LIMIT 10
";
$status_logs_stmt = $db->prepare($status_logs_query);
$status_logs_stmt->execute();
$truck_status_logs = $status_logs_stmt->fetchAll();

// Set page title
$pageTitle = "Assign Trucks to Drivers";

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
                    <i class="fas fa-truck-loading me-2"></i>Truck-Driver Assignments
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Assign trucks to drivers and manage assignments
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="drivers.php" class="btn btn-outline-primary">
                    <i class="fas fa-users me-2"></i> Manage Drivers
                </a>
                <a href="trucks.php" class="btn btn-outline-success">
                    <i class="fas fa-truck me-2"></i> Manage Trucks
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
        <!-- Left Column: Statistics & Available Resources -->
        <div class="col-lg-4">
            <!-- Statistics Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Assignment Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="p-3 bg-light rounded">
                                <h3 class="mb-1 text-primary"><?php echo count($active_assignments); ?></h3>
                                <small class="text-muted">Active Assignments</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="p-3 bg-light rounded">
                                <h3 class="mb-1 text-success"><?php echo count($available_drivers); ?></h3>
                                <small class="text-muted">Available Drivers</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded">
                                <h3 class="mb-1 text-info"><?php echo count($available_trucks); ?></h3>
                                <small class="text-muted">Available Trucks</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-light rounded">
                                <h3 class="mb-1 text-warning">
                                    <?php echo array_reduce($active_assignments, function($carry, $assignment) {
                                        return $carry + ($assignment['active_orders'] ?? 0);
                                    }, 0); ?>
                                </h3>
                                <small class="text-muted">Active Orders</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Assignments Card -->
            <?php if (!empty($active_assignments)): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-warning text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-tasks me-2"></i>Active Assignments
                        <span class="badge bg-light text-warning ms-2"><?php echo count($active_assignments); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($active_assignments as $assignment): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($assignment['driver_name']); ?></h6>
                                        <small class="text-muted">
                                            <i class="fas fa-id-card me-1"></i>
                                            <?php echo htmlspecialchars($assignment['license_type']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-info">
                                            <?php echo date('M d', strtotime($assignment['assigned_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted">
                                            <i class="fas fa-truck me-1"></i>
                                            <?php echo htmlspecialchars($assignment['truck_brand'] . ' ' . $assignment['truck_model']); ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-tag me-1"></i>
                                            <?php echo htmlspecialchars($assignment['truck_plate']); ?>
                                        </small>
                                        <?php if ($assignment['active_orders'] > 0): ?>
                                            <br>
                                            <small class="text-warning">
                                                <i class="fas fa-box me-1"></i>
                                                <?php echo $assignment['active_orders']; ?> active orders
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#unassignModal<?php echo $assignment['id']; ?>">
                                        <i class="fas fa-times"></i>
                                    </button>
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
                                                        Unassigning will mark this assignment as completed and make both truck and driver available again.
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Current Assignment</label>
                                                        <div class="form-control bg-light">
                                                            <strong><?php echo htmlspecialchars($assignment['driver_name']); ?></strong>
                                                            assigned to 
                                                            <strong><?php echo htmlspecialchars($assignment['truck_brand'] . ' ' . $assignment['truck_model']); ?></strong>
                                                            (<?php echo htmlspecialchars($assignment['truck_plate']); ?>)
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Current Location *</label>
                                                        <input type="text" class="form-control" name="location" required 
                                                               placeholder="Where is the truck currently?">
                                                    </div>
                                                    
                                                    <div class="row mb-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label">Fuel Level (%)</label>
                                                            <input type="number" class="form-control" name="fuel_level" 
                                                                   min="0" max="100" placeholder="0-100">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label">Odometer Reading</label>
                                                            <input type="number" class="form-control" name="odometer" 
                                                                   min="0" placeholder="Current mileage">
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label">Reason/Notes *</label>
                                                        <textarea class="form-control" name="unassignment_notes" 
                                                                  rows="3" required placeholder="Why is this assignment being ended?"></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Unassign</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Available Resources Card -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-list me-2"></i>Available Resources
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6 class="text-success">
                            <i class="fas fa-users me-1"></i> Available Drivers
                            <span class="badge bg-light text-success"><?php echo count($available_drivers); ?></span>
                        </h6>
                        <?php if (empty($available_drivers)): ?>
                            <p class="text-muted small mb-0">No drivers available</p>
                        <?php else: ?>
                            <?php foreach ($available_drivers as $driver): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                    <div>
                                        <small class="fw-medium"><?php echo htmlspecialchars($driver['name']); ?></small><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($driver['license_type']); ?></small>
                                    </div>
                                    <span class="badge bg-success">Available</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="text-info">
                            <i class="fas fa-truck me-1"></i> Available Trucks
                            <span class="badge bg-light text-info"><?php echo count($available_trucks); ?></span>
                        </h6>
                        <?php if (empty($available_trucks)): ?>
                            <p class="text-muted small mb-0">No trucks available</p>
                        <?php else: ?>
                            <?php foreach ($available_trucks as $truck): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                    <div>
                                        <small class="fw-medium"><?php echo htmlspecialchars($truck['brand'] . ' ' . $truck['model']); ?></small><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($truck['plate_number']); ?></small>
                                    </div>
                                    <span class="badge bg-info">Available</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Drivers List & Assignment -->
        <div class="col-lg-8">
            <!-- Filter Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Driver, truck, license...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <?php foreach ($driver_status_names as $status_value => $status_name): ?>
                                    <option value="<?php echo $status_value; ?>" 
                                        <?php echo $status_filter == $status_value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($status_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver</label>
                            <select class="form-select" name="driver_id">
                                <option value="">All Drivers</option>
                                <?php foreach ($all_drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>" 
                                        <?php echo $driver_filter == $driver['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($driver['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Truck</label>
                            <select class="form-select" name="truck_id">
                                <option value="">All Trucks</option>
                                <?php foreach ($all_trucks as $truck): ?>
                                    <option value="<?php echo $truck['id']; ?>" 
                                        <?php echo $truck_filter == $truck['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($truck['plate_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i> Apply Filters
                                </button>
                                <a href="assign-drivers.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Clear Filters
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Drivers List -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="fas fa-users me-2"></i>Drivers
                            <span class="badge bg-primary ms-2"><?php echo $total_records; ?></span>
                        </h6>
                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignTruckModal">
                            <i class="fas fa-link me-1"></i> New Assignment
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($drivers)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No drivers found</h5>
                            <p class="text-muted">Try adjusting your filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Driver</th>
                                        <th>License</th>
                                        <th>Status</th>
                                        <th>Assigned Truck</th>
                                        <th>Assignment Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($drivers as $driver): 
                                        $status_color = $driver_status_colors[$driver['status']] ?? 'secondary';
                                        $status_name = $driver_status_names[$driver['status']] ?? 'Unknown';
                                        $has_assignment = !empty($driver['assignment_id']);
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if (!empty($driver['profile_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($driver['profile_image']); ?>" 
                                                             class="rounded-circle me-2" 
                                                             width="40" height="40" 
                                                             style="object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" 
                                                             style="width: 40px; height: 40px;">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="fw-medium"><?php echo htmlspecialchars($driver['name']); ?></div>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($driver['email']); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($driver['license_type']); ?></div>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($driver['license_number']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $status_color; ?>">
                                                    <?php echo htmlspecialchars($status_name); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($has_assignment): ?>
                                                    <div class="fw-medium">
                                                        <?php echo htmlspecialchars($driver['truck_brand'] . ' ' . $driver['truck_model']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($driver['truck_plate']); ?>
                                                    </small>
                                                    <?php if (!empty($driver['truck_status'])): ?>
                                                        <br>
                                                        <small class="badge bg-<?php echo $truck_status_colors[$driver['truck_status']] ?? 'secondary'; ?>">
                                                            <?php echo $truck_status_names[$driver['truck_status']] ?? 'Unknown'; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($has_assignment): ?>
                                                    <?php echo date('M d, Y', strtotime($driver['assigned_at'])); ?><br>
                                                    <small class="text-muted">
                                                        <?php echo date('h:i A', strtotime($driver['assigned_at'])); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <a class="dropdown-item" href="driver-view.php?id=<?php echo $driver['id']; ?>">
                                                                <i class="fas fa-eye me-2"></i> View Details
                                                            </a>
                                                        </li>
                                                        <?php if (!$has_assignment && $driver['status'] == DRIVER_AVAILABLE): ?>
                                                        <li>
                                                            <a class="dropdown-item" href="#" 
                                                               data-bs-toggle="modal" 
                                                               data-bs-target="#assignToDriverModal<?php echo $driver['id']; ?>">
                                                                <i class="fas fa-truck me-2"></i> Assign Truck
                                                            </a>
                                                        </li>
                                                        <?php endif; ?>
                                                        <li>
                                                            <a class="dropdown-item text-warning" href="#" 
                                                               data-bs-toggle="modal" 
                                                               data-bs-target="#updateDriverStatusModal<?php echo $driver['id']; ?>">
                                                                <i class="fas fa-sync-alt me-2"></i> Update Status
                                                            </a>
                                                        </li>
                                                        <?php if ($has_assignment): ?>
                                                        <li>
                                                            <a class="dropdown-item text-danger" href="#" 
                                                               data-bs-toggle="modal" 
                                                               data-bs-target="#unassignDriverModal<?php echo $driver['assignment_id']; ?>">
                                                                <i class="fas fa-times me-2"></i> Unassign Truck
                                                            </a>
                                                        </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Assign Truck to Driver Modal -->
                                        <?php if (!$has_assignment && $driver['status'] == DRIVER_AVAILABLE): ?>
                                        <div class="modal fade" id="assignToDriverModal<?php echo $driver['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Assign Truck to <?php echo htmlspecialchars($driver['name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="driver_id" value="<?php echo $driver['id']; ?>">
                                                            <input type="hidden" name="assign_truck" value="1">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Select Truck *</label>
                                                                <select class="form-select" name="truck_id" required>
                                                                    <option value="">-- Select Truck --</option>
                                                                    <?php foreach ($available_trucks as $truck): ?>
                                                                        <option value="<?php echo $truck['id']; ?>">
                                                                            <?php echo htmlspecialchars($truck['brand'] . ' ' . $truck['model']); ?> 
                                                                            (<?php echo htmlspecialchars($truck['plate_number']); ?>)
                                                                            - Capacity: <?php echo $truck['capacity']; ?> kg
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <?php if (empty($available_trucks)): ?>
                                                                    <div class="text-danger small mt-1">
                                                                        <i class="fas fa-exclamation-circle me-1"></i>
                                                                        No trucks available for assignment
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Current Location *</label>
                                                                <input type="text" class="form-control" name="location" required 
                                                                       placeholder="Where is the truck currently?">
                                                            </div>
                                                            
                                                            <div class="row mb-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Fuel Level (%)</label>
                                                                    <input type="number" class="form-control" name="fuel_level" 
                                                                           min="0" max="100" placeholder="0-100">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Odometer Reading</label>
                                                                    <input type="number" class="form-control" name="odometer" 
                                                                           min="0" placeholder="Current mileage">
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Assignment Notes (Optional)</label>
                                                                <textarea class="form-control" name="assignment_notes" rows="2" 
                                                                          placeholder="Special instructions..."></textarea>
                                                            </div>
                                                            
                                                            <div class="alert alert-info">
                                                                <small>
                                                                    <i class="fas fa-info-circle me-1"></i>
                                                                    Driver: <?php echo htmlspecialchars($driver['name']); ?><br>
                                                                    License: <?php echo htmlspecialchars($driver['license_type']); ?> 
                                                                    (<?php echo htmlspecialchars($driver['license_number']); ?>)<br>
                                                                    Experience: <?php echo $driver['experience_years']; ?> years<br>
                                                                    Phone: <?php echo htmlspecialchars($driver['phone_number']); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary" <?php echo empty($available_trucks) ? 'disabled' : ''; ?>>Assign Truck</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Update Driver Status Modal -->
                                        <div class="modal fade" id="updateDriverStatusModal<?php echo $driver['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Update Driver Status</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="driver_id" value="<?php echo $driver['id']; ?>">
                                                            <input type="hidden" name="update_driver_status" value="1">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Current Status</label>
                                                                <div class="form-control">
                                                                    <span class="badge bg-<?php echo $status_color; ?>">
                                                                        <?php echo htmlspecialchars($status_name); ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">New Status *</label>
                                                                <select class="form-select" name="new_status" required>
                                                                    <option value="">-- Select Status --</option>
                                                                    <?php foreach ($driver_status_names as $status_value => $status_display): ?>
                                                                        <?php if ($status_value != $driver['status']): ?>
                                                                            <option value="<?php echo $status_value; ?>">
                                                                                <?php echo htmlspecialchars($status_display); ?>
                                                                            </option>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Reason/Notes (Optional)</label>
                                                                <textarea class="form-control" name="status_notes" rows="2" 
                                                                          placeholder="Reason for status change..."></textarea>
                                                            </div>
                                                            
                                                            <div class="alert alert-warning">
                                                                <small>
                                                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                                                    Note: Changing status will affect truck assignment availability.
                                                                </small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Update Status</button>
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
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white">
                    <nav aria-label="Drivers pagination">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($_GET); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query($_GET); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($_GET); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

            <!-- Truck Status Logs -->
            <?php if (!empty($truck_status_logs)): ?>
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-history me-2"></i>Recent Truck Status Updates
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Truck</th>
                                    <th>Driver</th>
                                    <th>Status Change</th>
                                    <th>Location</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($truck_status_logs as $log): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($log['brand'] . ' ' . $log['model']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($log['plate_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['driver_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!empty($log['old_status'])): ?>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($log['old_status']); ?></span>
                                            <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                            <?php endif; ?>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($log['new_status']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['location'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php echo date('M d', strtotime($log['changed_at'])); ?><br>
                                            <small class="text-muted">
                                                <?php echo date('h:i A', strtotime($log['changed_at'])); ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Assignment Modal (Global) -->
<div class="modal fade" id="assignTruckModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Truck-Driver Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="assign_truck" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Select Driver *</label>
                            <select class="form-select" name="driver_id" required id="driverSelect">
                                <option value="">-- Select Driver --</option>
                                <?php foreach ($available_drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>" 
                                            data-license="<?php echo htmlspecialchars($driver['license_type']); ?>"
                                            data-experience="<?php echo $driver['experience_years']; ?>"
                                            data-phone="<?php echo htmlspecialchars($driver['phone_number']); ?>">
                                        <?php echo htmlspecialchars($driver['name']); ?> 
                                        (<?php echo htmlspecialchars($driver['license_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="driverInfo" class="mt-2 p-2 bg-light rounded d-none">
                                <small>
                                    <strong>Driver Details:</strong><br>
                                    License: <span id="licenseType"></span><br>
                                    Experience: <span id="experienceYears"></span> years<br>
                                    Phone: <span id="driverPhone"></span>
                                </small>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Select Truck *</label>
                            <select class="form-select" name="truck_id" required id="truckSelect">
                                <option value="">-- Select Truck --</option>
                                <?php foreach ($available_trucks as $truck): ?>
                                    <option value="<?php echo $truck['id']; ?>"
                                            data-capacity="<?php echo $truck['capacity']; ?>"
                                            data-model="<?php echo htmlspecialchars($truck['model']); ?>">
                                        <?php echo htmlspecialchars($truck['brand'] . ' ' . $truck['model']); ?> 
                                        (<?php echo htmlspecialchars($truck['plate_number']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="truckInfo" class="mt-2 p-2 bg-light rounded d-none">
                                <small>
                                    <strong>Truck Details:</strong><br>
                                    Model: <span id="truckModel"></span><br>
                                    Capacity: <span id="truckCapacity"></span> kg<br>
                                    Plate: <span id="truckPlate"></span>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Current Location *</label>
                            <input type="text" class="form-control" name="location" required 
                                   placeholder="Where is the truck currently?">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fuel Level (%)</label>
                            <input type="number" class="form-control" name="fuel_level" 
                                   min="0" max="100" placeholder="0-100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Odometer Reading</label>
                            <input type="number" class="form-control" name="odometer" 
                                   min="0" placeholder="Current mileage">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Assignment Notes (Optional)</label>
                        <textarea class="form-control" name="assignment_notes" rows="3" 
                                  placeholder="Special instructions, route details, etc..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            This assignment will:<br>
                            1. Mark the driver as "On Delivery"<br>
                            2. Mark the truck as "Assigned"<br>
                            3. Create an active assignment record<br>
                            4. Send a notification to the driver
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Assignment</button>
                </div>
            </form>
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

// Driver and truck selection info
document.getElementById('driverSelect')?.addEventListener('change', function() {
    const driverInfo = document.getElementById('driverInfo');
    const selectedOption = this.options[this.selectedIndex];
    
    if (selectedOption.value) {
        driverInfo.classList.remove('d-none');
        document.getElementById('licenseType').textContent = selectedOption.dataset.license || 'N/A';
        document.getElementById('experienceYears').textContent = selectedOption.dataset.experience || 'N/A';
        document.getElementById('driverPhone').textContent = selectedOption.dataset.phone || 'N/A';
    } else {
        driverInfo.classList.add('d-none');
    }
});

document.getElementById('truckSelect')?.addEventListener('change', function() {
    const truckInfo = document.getElementById('truckInfo');
    const selectedOption = this.options[this.selectedIndex];
    
    if (selectedOption.value) {
        truckInfo.classList.remove('d-none');
        document.getElementById('truckModel').textContent = selectedOption.dataset.model || 'N/A';
        document.getElementById('truckCapacity').textContent = selectedOption.dataset.capacity || 'N/A';
        document.getElementById('truckPlate').textContent = selectedOption.textContent.match(/\((.*?)\)/)?.[1] || 'N/A';
    } else {
        truckInfo.classList.add('d-none');
    }
});

// Form validation
document.querySelectorAll('form[action=""]').forEach(form => {
    if (form.querySelector('input[name="assign_truck"]')) {
        form.addEventListener('submit', function(e) {
            const driverSelect = this.querySelector('select[name="driver_id"]');
            const truckSelect = this.querySelector('select[name="truck_id"]');
            const location = this.querySelector('input[name="location"]');
            
            if (!driverSelect.value) {
                e.preventDefault();
                alert('Please select a driver.');
                return false;
            }
            
            if (!truckSelect.value) {
                e.preventDefault();
                alert('Please select a truck.');
                return false;
            }
            
            if (!location.value.trim()) {
                e.preventDefault();
                alert('Please enter the current location.');
                return false;
            }
            
            return true;
        });
    }
    
    if (form.querySelector('input[name="unassign_truck"]')) {
        form.addEventListener('submit', function(e) {
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

// Auto-refresh every 30 seconds for real-time updates
setTimeout(() => {
    window.location.reload();
}, 30000);
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>