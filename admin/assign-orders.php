<?php
// assign-orders.php
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
$driver_filter = isset($_GET['driver_id']) ? intval($_GET['driver_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Initialize messages
$message = '';
$error = '';

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_order'])) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $driver_id = intval($_POST['driver_id'] ?? 0);
    $vehicle_id = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($order_id <= 0 || $driver_id <= 0) {
        $error = 'Invalid order or driver selection';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if order is assignable (not delivered or cancelled)
            $check_order = $db->prepare("
                SELECT status_id FROM orders 
                WHERE id = ? AND status_id NOT IN (?, ?)
            ");
            $check_order->execute([$order_id, STATUS_DELIVERED, STATUS_CANCELLED]);
            $order = $check_order->fetch();
            
            if (!$order) {
                throw new Exception('Order cannot be assigned (already delivered or cancelled)');
            }
            
            // Check if driver is available
            $check_driver = $db->prepare("
                SELECT d.id, u.name, d.status 
                FROM drivers d 
                JOIN users u ON d.user_id = u.id 
                WHERE d.id = ? AND d.status = ?
            ");
            $check_driver->execute([$driver_id, DRIVER_AVAILABLE]);
            $driver = $check_driver->fetch();
            
            if (!$driver) {
                throw new Exception('Selected driver is not available');
            }
            
            // Check vehicle availability if selected
            if ($vehicle_id) {
                $check_vehicle = $db->prepare("
                    SELECT status FROM trucks WHERE id = ? AND status = ?
                ");
                $check_vehicle->execute([$vehicle_id, TRUCK_AVAILABLE]);
                $vehicle = $check_vehicle->fetch();
                
                if (!$vehicle) {
                    throw new Exception('Selected vehicle is not available');
                }
            }
            
            // Update order status to ASSIGNED
            $update_order = $db->prepare("
                UPDATE orders 
                SET status_id = ?, driver_id = ?, truck_id = ?, 
                    estimated_pickup = DATE_ADD(NOW(), INTERVAL 1 HOUR),
                    estimated_delivery = DATE_ADD(NOW(), INTERVAL 24 HOUR),
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $update_order->execute([
                STATUS_ASSIGNED,
                $driver_id,
                $vehicle_id,
                $order_id
            ]);
            
            // Update driver status to ON_DELIVERY
            $update_driver = $db->prepare("
                UPDATE drivers SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_driver->execute([DRIVER_ON_DELIVERY, $driver_id]);
            
            // Update vehicle status if assigned
            if ($vehicle_id) {
                $update_vehicle = $db->prepare("
                    UPDATE trucks SET status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_vehicle->execute([TRUCK_ASSIGNED, $vehicle_id]);
            }
            
            // Create assignment record - UPDATED to use truck_driver_assignments table
            // First, check if there's an existing active assignment for this driver
            $check_existing = $db->prepare("
                SELECT id FROM truck_driver_assignments 
                WHERE driver_id = ? AND status = 'active'
            ");
            $check_existing->execute([$driver_id]);
            
            if ($check_existing->fetch()) {
                // Update existing assignment
                $update_assignment = $db->prepare("
                    UPDATE truck_driver_assignments 
                    SET truck_id = ?, assignment_notes = CONCAT(COALESCE(assignment_notes, ''), '\nOrder #{$order_id}: ', ?)
                    WHERE driver_id = ? AND status = 'active'
                ");
                $update_assignment->execute([
                    $vehicle_id,
                    $notes,
                    $driver_id
                ]);
            } else {
                // Create new assignment
                $create_assignment = $db->prepare("
                    INSERT INTO truck_driver_assignments 
                    (truck_id, driver_id, assignment_notes, assigned_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $create_assignment->execute([
                    $vehicle_id,
                    $driver_id,
                    "Order #{$order_id}: " . $notes
                ]);
            }
            
            // Create notification
            $notification_title = 'Order Assigned';
            $notification_message = "Order #{$order_id} has been assigned to you";
            
            $driver_user = $db->prepare("SELECT user_id FROM drivers WHERE id = ?");
            $driver_user->execute([$driver_id]);
            $driver_user_id = $driver_user->fetchColumn();
            
            if ($driver_user_id) {
                $notification = $db->prepare("
                    INSERT INTO notifications 
                    (user_id, title, message, type, related_to, created_at) 
                    VALUES (?, ?, ?, 'order', ?, NOW())
                ");
                $notification->execute([
                    $driver_user_id,
                    $notification_title,
                    $notification_message,
                    'order_' . $order_id
                ]);
            }
            
            $db->commit();
            $message = "Order #{$order_id} successfully assigned to driver";
            
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            $error = 'Assignment failed: ' . $e->getMessage();
        }
    }
}

// Handle bulk assignment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_assign'])) {
    $order_ids = $_POST['order_ids'] ?? [];
    $driver_id = intval($_POST['bulk_driver_id'] ?? 0);
    
    if (empty($order_ids) || $driver_id <= 0) {
        $error = 'Please select orders and a driver';
    } else {
        $success_count = 0;
        $failed_count = 0;
        
        foreach ($order_ids as $order_id) {
            $order_id = intval($order_id);
            
            try {
                // Check if order is confirmable
                $check_order = $db->prepare("
                    SELECT status_id FROM orders 
                    WHERE id = ? AND status_id = ?
                ");
                $check_order->execute([$order_id, STATUS_CONFIRMED]);
                
                if ($check_order->fetch()) {
                    $update_order = $db->prepare("
                        UPDATE orders 
                        SET status_id = ?, driver_id = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $update_order->execute([STATUS_ASSIGNED, $driver_id, $order_id]);
                    
                    $success_count++;
                } else {
                    $failed_count++;
                }
            } catch (Exception $e) {
                $failed_count++;
            }
        }
        
        if ($success_count > 0) {
            $message = "Successfully assigned {$success_count} order(s)";
            if ($failed_count > 0) {
                $message .= ", {$failed_count} order(s) could not be assigned";
            }
        } else {
            $error = "No orders were assigned. Please check order status.";
        }
    }
}

// Get available drivers
$available_drivers = [];
try {
    $drivers_stmt = $db->prepare("
        SELECT d.id, u.name, d.license_type, d.experience_years, 
               d.status, c.name as courier_name
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN couriers c ON d.courier_id = c.id
        WHERE d.status = ? AND u.status = 'active'
        ORDER BY u.name
    ");
    $drivers_stmt->execute([DRIVER_AVAILABLE]);
    $available_drivers = $drivers_stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Failed to load drivers: ' . $e->getMessage();
}

// Get available vehicles - USING CORRECT COLUMN NAMES
$available_vehicles = [];
try {
    $vehicles_stmt = $db->prepare("
        SELECT id, brand, model, plate_number as license_plate, capacity 
        FROM trucks 
        WHERE status = ?
        ORDER BY brand, model
    ");
    $vehicles_stmt->execute([TRUCK_AVAILABLE]);
    $available_vehicles = $vehicles_stmt->fetchAll();
} catch (Exception $e) {
    // Silently fail - vehicles are optional
}

// Build query for unassigned orders
$query_params = [];
$query_conditions = ["o.status_id = " . STATUS_CONFIRMED]; // Only confirmed orders

// Build search conditions
if (!empty($search)) {
    $query_conditions[] = "(o.tracking_number LIKE ? OR 
                           cl.company_name LIKE ? OR 
                           u.name LIKE ?)";
    $search_term = "%$search%";
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

// Build status filter (for history)
if (!empty($status_filter)) {
    $query_conditions[] = "o.status_id = ?";
    $query_params[] = $status_filter;
}

// Build driver filter
if (!empty($driver_filter)) {
    $query_conditions[] = "o.driver_id = ?";
    $query_params[] = $driver_filter;
}

// Build date filter
if (!empty($date_from)) {
    $query_conditions[] = "o.created_at >= ?";
    $query_params[] = $date_from . ' 00:00:00';
}
if (!empty($date_to)) {
    $query_conditions[] = "o.created_at <= ?";
    $query_params[] = $date_to . ' 23:59:59';
}

// Build WHERE clause
$where_clause = '';
if (!empty($query_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $query_conditions);
}

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total 
    FROM orders o
    LEFT JOIN clients cl ON o.client_id = cl.id
    LEFT JOIN users u ON cl.user_id = u.id
    $where_clause
";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($query_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $limit);

// Get order status names from database
$status_names = [];
try {
    $status_stmt = $db->query("SELECT id, name FROM order_status");
    $statuses = $status_stmt->fetchAll();
    foreach ($statuses as $status) {
        $status_names[$status['id']] = $status['name'];
    }
} catch (Exception $e) {
    // Use default status names based on constants
    $status_names = [
        STATUS_PENDING => 'Pending',
        STATUS_CONFIRMED => 'Confirmed',
        STATUS_ASSIGNED => 'Assigned',
        STATUS_PICKED_UP => 'Picked Up',
        STATUS_IN_TRANSIT => 'In Transit',
        STATUS_OUT_FOR_DELIVERY => 'Out for Delivery',
        STATUS_DELIVERED => 'Delivered',
        STATUS_CANCELLED => 'Cancelled',
        STATUS_RETURNED => 'Returned'
    ];
}

// Get orders - USING CORRECT COLUMN NAMES FOR TRUCKS TABLE
$query = "
    SELECT o.*, 
           cl.company_name,
           u.name as client_name,
           u.email as client_email,
           u.phone as client_phone,
           d.id as driver_id,
           du.name as driver_name,
           t.brand as truck_brand,
           t.model as truck_model,
           t.plate_number as truck_plate
    FROM orders o
    LEFT JOIN clients cl ON o.client_id = cl.id
    LEFT JOIN users u ON cl.user_id = u.id
    LEFT JOIN drivers d ON o.driver_id = d.id
    LEFT JOIN users du ON d.user_id = du.id
    LEFT JOIN trucks t ON o.truck_id = t.id
    $where_clause
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";

// Add limit and offset to params
$query_params[] = $limit;
$query_params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($query_params);
$orders = $stmt->fetchAll();

// Get assignment history from orders table instead
$history_query = "
    SELECT o.id as order_id, o.tracking_number, 
           du.name as driver_name, u.name as client_name,
           au.name as assigned_by_name, o.updated_at as assigned_at,
           NULL as notes
    FROM orders o
    JOIN clients cl ON o.client_id = cl.id
    JOIN users u ON cl.user_id = u.id
    JOIN drivers d ON o.driver_id = d.id
    JOIN users du ON d.user_id = du.id
    JOIN users au ON au.id = (SELECT assigned_by FROM orders WHERE id = o.id LIMIT 1)
    WHERE o.status_id = ? AND o.driver_id IS NOT NULL
    ORDER BY o.updated_at DESC
    LIMIT 10
";
$history_stmt = $db->prepare($history_query);
$history_stmt->execute([STATUS_ASSIGNED]);
$assignment_history = $history_stmt->fetchAll();

// Set page title
$pageTitle = "Assign Orders";

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
                    <i class="fas fa-tasks me-2"></i>Assign Orders
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Assign orders to available drivers and vehicles
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="orders.php" class="btn btn-outline-primary">
                    <i class="fas fa-list me-2"></i> View All Orders
                </a>
                <a href="drivers.php" class="btn btn-outline-success">
                    <i class="fas fa-truck me-2"></i> Manage Drivers
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
        <!-- Left Column: Available Drivers & Vehicles -->
        <div class="col-lg-4">
            <!-- Available Drivers Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-users me-2"></i>Available Drivers
                        <span class="badge bg-light text-primary ms-2"><?php echo count($available_drivers); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($available_drivers)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-slash fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No drivers available</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($available_drivers as $driver): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($driver['name']); ?></h6>
                                            <small class="text-muted">
                                                <i class="fas fa-id-card me-1"></i>
                                                <?php echo htmlspecialchars($driver['license_type']); ?> License
                                            </small>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-briefcase me-1"></i>
                                                <?php echo $driver['experience_years']; ?> years experience
                                            </small>
                                            <?php if (!empty($driver['courier_name'])): ?>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-building me-1"></i>
                                                    <?php echo htmlspecialchars($driver['courier_name']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i> Available
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Available Vehicles Card -->
            <?php if (!empty($available_vehicles)): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-truck me-2"></i>Available Vehicles
                        <span class="badge bg-light text-info ms-2"><?php echo count($available_vehicles); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($available_vehicles as $vehicle): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">
                                            <?php 
                                                $vehicle_name = '';
                                                if (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
                                                    $vehicle_name = htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']);
                                                } elseif (!empty($vehicle['brand'])) {
                                                    $vehicle_name = htmlspecialchars($vehicle['brand']);
                                                } else {
                                                    $vehicle_name = 'Vehicle #' . $vehicle['id'];
                                                }
                                                echo $vehicle_name;
                                            ?>
                                        </h6>
                                        <small class="text-muted">
                                            <i class="fas fa-tag me-1"></i>
                                            <?php echo htmlspecialchars($vehicle['license_plate'] ?? 'No Plate'); ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-weight me-1"></i>
                                            Capacity: <?php echo $vehicle['capacity'] ?? 'N/A'; ?> kg
                                        </small>
                                    </div>
                                    <span class="badge bg-info">
                                        <i class="fas fa-check-circle me-1"></i> Available
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Orders and Assignment -->
        <div class="col-lg-8">
            <!-- Filter Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search Orders</label>
                            <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tracking number, client name...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="">All Status</option>
                                <option value="<?php echo STATUS_CONFIRMED; ?>" <?php echo $status_filter == STATUS_CONFIRMED ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="<?php echo STATUS_ASSIGNED; ?>" <?php echo $status_filter == STATUS_ASSIGNED ? 'selected' : ''; ?>>Assigned</option>
                                <option value="<?php echo STATUS_IN_TRANSIT; ?>" <?php echo $status_filter == STATUS_IN_TRANSIT ? 'selected' : ''; ?>>In Transit</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Driver</label>
                            <select class="form-select" name="driver_id">
                                <option value="">All Drivers</option>
                                <?php 
                                // Get all drivers for filter
                                $all_drivers = $db->query("
                                    SELECT d.id, u.name 
                                    FROM drivers d 
                                    JOIN users u ON d.user_id = u.id 
                                    ORDER BY u.name
                                ")->fetchAll();
                                foreach ($all_drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>" <?php echo $driver_filter == $driver['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($driver['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i> Apply Filters
                                </button>
                                <a href="assign-orders.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Clear Filters
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Orders List -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="fas fa-boxes me-2"></i>Orders
                            <span class="badge bg-primary ms-2"><?php echo $total_records; ?></span>
                        </h6>
                        <form method="POST" action="" id="bulkAssignForm" class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm" name="bulk_driver_id" style="width: auto;" required>
                                <option value="">Select Driver</option>
                                <?php foreach ($available_drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>">
                                        <?php echo htmlspecialchars($driver['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="bulk_assign" class="btn btn-sm btn-success" onclick="return confirmBulkAssign()">
                                <i class="fas fa-users me-1"></i> Bulk Assign
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($orders)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No orders found</h5>
                            <p class="text-muted">Try adjusting your filters or check back later.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAll" class="form-check-input">
                                        </th>
                                        <th>Order #</th>
                                        <th>Client</th>
                                        <th>Destination</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): 
                                        // Get status name
                                        $status_name = $status_names[$order['status_id']] ?? 'Unknown';
                                        // Get status color based on status
                                        $status_color = match($order['status_id']) {
                                            STATUS_PENDING => '#6c757d',
                                            STATUS_CONFIRMED => '#17a2b8',
                                            STATUS_ASSIGNED => '#ffc107',
                                            STATUS_PICKED_UP => '#28a745',
                                            STATUS_IN_TRANSIT => '#007bff',
                                            STATUS_OUT_FOR_DELIVERY => '#6610f2',
                                            STATUS_DELIVERED => '#20c997',
                                            STATUS_CANCELLED => '#dc3545',
                                            STATUS_RETURNED => '#fd7e14',
                                            default => '#6c757d'
                                        };
                                        
                                        // Get truck display name
                                        $truck_display = '';
                                        if (!empty($order['truck_brand']) && !empty($order['truck_model'])) {
                                            $truck_display = htmlspecialchars($order['truck_brand'] . ' ' . $order['truck_model']);
                                        } elseif (!empty($order['truck_brand'])) {
                                            $truck_display = htmlspecialchars($order['truck_brand']);
                                        } elseif (!empty($order['truck_plate'])) {
                                            $truck_display = 'Plate: ' . htmlspecialchars($order['truck_plate']);
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <?php if ($order['status_id'] == STATUS_CONFIRMED): ?>
                                                    <input type="checkbox" name="order_ids[]" value="<?php echo $order['id']; ?>" class="form-check-input order-checkbox">
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($order['tracking_number']); ?></strong><br>
                                                <small class="text-muted">$<?php echo number_format($order['total_amount'], 2); ?></small>
                                            </td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($order['client_name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($order['company_name']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($order['delivery_location']); ?><br>
                                                <small class="text-muted">
                                                    <?php echo number_format($order['weight'], 2); ?> kg
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge" style="background-color: <?php echo $status_color; ?>;">
                                                    <?php echo htmlspecialchars($status_name); ?>
                                                </span>
                                                <?php if ($order['driver_name']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-truck me-1"></i>
                                                        <?php echo htmlspecialchars($order['driver_name']); ?>
                                                    </small>
                                                    <?php if ($truck_display): ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <i class="fas fa-car me-1"></i>
                                                            <?php echo $truck_display; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M d', strtotime($order['created_at'])); ?><br>
                                                <small class="text-muted">
                                                    <?php echo date('h:i A', strtotime($order['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <a class="dropdown-item" href="orders-view.php?id=<?php echo $order['id']; ?>">
                                                                <i class="fas fa-eye me-2"></i> View Details
                                                            </a>
                                                        </li>
                                                        <?php if ($order['status_id'] == STATUS_CONFIRMED): ?>
                                                        <li>
                                                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $order['id']; ?>">
                                                                <i class="fas fa-user-check me-2"></i> Assign Driver
                                                            </a>
                                                        </li>
                                                        <?php endif; ?>
                                                        <?php if ($order['driver_id'] && $order['status_id'] == STATUS_ASSIGNED): ?>
                                                        <li>
                                                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#reassignModal<?php echo $order['id']; ?>">
                                                                <i class="fas fa-exchange-alt me-2"></i> Reassign
                                                            </a>
                                                        </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Assign Modal -->
                                        <div class="modal fade" id="assignModal<?php echo $order['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Assign Order #<?php echo htmlspecialchars($order['tracking_number']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                            <input type="hidden" name="assign_order" value="1">
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Select Driver *</label>
                                                                <select class="form-select" name="driver_id" required>
                                                                    <option value="">-- Select Driver --</option>
                                                                    <?php foreach ($available_drivers as $driver): ?>
                                                                        <option value="<?php echo $driver['id']; ?>">
                                                                            <?php echo htmlspecialchars($driver['name']); ?> 
                                                                            (<?php echo htmlspecialchars($driver['license_type']); ?> License)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            
                                                            <?php if (!empty($available_vehicles)): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Select Vehicle (Optional)</label>
                                                                <select class="form-select" name="vehicle_id">
                                                                    <option value="">-- No Vehicle --</option>
                                                                    <?php foreach ($available_vehicles as $vehicle): 
                                                                        // Determine vehicle display name for dropdown
                                                                        $dropdown_name = '';
                                                                        if (!empty($vehicle['brand']) && !empty($vehicle['model'])) {
                                                                            $dropdown_name = $vehicle['brand'] . ' ' . $vehicle['model'];
                                                                        } elseif (!empty($vehicle['brand'])) {
                                                                            $dropdown_name = $vehicle['brand'];
                                                                        } else {
                                                                            $dropdown_name = 'Vehicle #' . $vehicle['id'];
                                                                        }
                                                                    ?>
                                                                        <option value="<?php echo $vehicle['id']; ?>">
                                                                            <?php echo htmlspecialchars($dropdown_name); ?> 
                                                                            (<?php echo htmlspecialchars($vehicle['license_plate'] ?? 'No Plate'); ?>)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <?php endif; ?>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes (Optional)</label>
                                                                <textarea class="form-control" name="notes" rows="2" placeholder="Special instructions..."></textarea>
                                                            </div>
                                                            
                                                            <div class="alert alert-info">
                                                                <small>
                                                                    <i class="fas fa-info-circle me-1"></i>
                                                                    Client: <?php echo htmlspecialchars($order['client_name']); ?><br>
                                                                    Destination: <?php echo htmlspecialchars($order['delivery_location']); ?><br>
                                                                    Weight: <?php echo number_format($order['weight'], 2); ?> kg<br>
                                                                    Service: <?php echo htmlspecialchars(ucfirst($order['service_type'])); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Assign Order</button>
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
                    <nav aria-label="Orders pagination">
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

            <!-- Assignment History -->
            <?php if (!empty($assignment_history)): ?>
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-history me-2"></i>Recent Assignments
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Driver</th>
                                    <th>Client</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignment_history as $history): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($history['tracking_number']); ?></td>
                                        <td><?php echo htmlspecialchars($history['driver_name']); ?></td>
                                        <td><?php echo htmlspecialchars($history['client_name']); ?></td>
                                        <td><?php echo date('M d, h:i A', strtotime($history['assigned_at'])); ?></td>
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

<script>
// Select all checkboxes
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.order-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

// Bulk assign confirmation
function confirmBulkAssign() {
    const selectedCount = document.querySelectorAll('.order-checkbox:checked').length;
    const driverSelect = document.querySelector('select[name="bulk_driver_id"]');
    
    if (selectedCount === 0) {
        alert('Please select at least one order to assign.');
        return false;
    }
    
    if (!driverSelect.value) {
        alert('Please select a driver for assignment.');
        return false;
    }
    
    return confirm(`Assign ${selectedCount} order(s) to selected driver?`);
}

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Update bulk assign button state
function updateBulkAssignButton() {
    const selectedCount = document.querySelectorAll('.order-checkbox:checked').length;
    const bulkButton = document.querySelector('button[name="bulk_assign"]');
    
    if (bulkButton) {
        if (selectedCount > 0) {
            bulkButton.innerHTML = `<i class="fas fa-users me-1"></i> Assign ${selectedCount} Orders`;
        } else {
            bulkButton.innerHTML = `<i class="fas fa-users me-1"></i> Bulk Assign`;
        }
    }
}

// Listen for checkbox changes
document.querySelectorAll('.order-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateBulkAssignButton);
});

// Initialize bulk assign button
updateBulkAssignButton();

// Form validation for single assignment
document.querySelectorAll('form[action=""]').forEach(form => {
    if (form.querySelector('input[name="assign_order"]')) {
        form.addEventListener('submit', function(e) {
            const driverSelect = this.querySelector('select[name="driver_id"]');
            if (!driverSelect.value) {
                e.preventDefault();
                alert('Please select a driver.');
                return false;
            }
            return true;
        });
    }
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>