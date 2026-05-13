<?php
// trucks.php - Driver's Assigned Truck Information
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

// Check if user is driver
if ($_SESSION['role_id'] != ROLE_DRIVER) {
    header('Location: ../index.php');
    exit();
}

$pageTitle = "My Truck - Driver Dashboard";
$driver_id = $_SESSION['user_id'];

// Get driver and truck information
$driver_info = [];
$truck_info = [];
$maintenance_history = [];
$upcoming_maintenance = [];
$assigned_orders = [];
$fuel_consumption = [];
$courier_info = [];

try {
    // First, get driver information from drivers table using user_id
    $driver_stmt = $db->prepare("
        SELECT d.*, u.name as driver_name, u.email, u.phone, u.created_at as join_date
        FROM drivers d 
        INNER JOIN users u ON d.user_id = u.id 
        WHERE u.id = ? AND u.role_id = ?
    ");
    $driver_stmt->execute([$driver_id, ROLE_DRIVER]);
    $driver_info = $driver_stmt->fetch();
    
    if (!$driver_info) {
        throw new Exception("Driver not found in drivers table. Please contact administrator.");
    }
    
    // Get assigned truck information - using driver_id from drivers table to match with trucks.driver_id
    $truck_stmt = $db->prepare("
        SELECT 
            t.*,
            c.name as courier_name,
            c.address as courier_address,
            c.phone as courier_phone,
            c.email as courier_email,
            t.capacity as max_capacity,
            t.fuel_type,
            t.insurance_number,
            t.insurance_expiry,
            t.last_maintenance as last_maintenance_date,
            t.status as truck_status,
            t.model,
            t.brand,
            t.year,
            t.plate_number,
            t.current_location,
            t.notes as truck_notes,
            (SELECT COUNT(*) FROM orders WHERE truck_id = t.id AND status_id IN (3,4,5)) as active_deliveries,
            (SELECT COUNT(*) FROM orders WHERE truck_id = t.id AND status_id = 6 AND DATE(updated_at) = CURDATE()) as deliveries_today,
            (SELECT COUNT(*) FROM orders WHERE truck_id = t.id AND status_id = 6 AND YEAR(updated_at) = YEAR(CURDATE())) as deliveries_this_year
        FROM trucks t
        LEFT JOIN couriers c ON t.courier_id = c.id
        WHERE t.driver_id = ? AND t.status != 'out_of_service'
        LIMIT 1
    ");
    $truck_stmt->execute([$driver_info['id']]);
    $truck_info = $truck_stmt->fetch();
    
    // Get courier info for contact details
    if ($driver_info['courier_id']) {
        $courier_stmt = $db->prepare("
            SELECT name, address, phone, email 
            FROM couriers 
            WHERE id = ?
        ");
        $courier_stmt->execute([$driver_info['courier_id']]);
        $courier_info = $courier_stmt->fetch();
    }
    
    // Get maintenance history if truck exists
    if (!empty($truck_info) && $truck_info['id']) {
        // Maintenance history - check if maintenance_history table exists
        try {
            $table_check = $db->prepare("SHOW TABLES LIKE 'maintenance_history'");
            $table_check->execute();
            $table_exists = $table_check->fetch();
            
            if ($table_exists) {
                $maintenance_stmt = $db->prepare("
                    SELECT 
                        mh.*,
                        mt.name as maintenance_type,
                        u.name as performed_by,
                        mh.cost,
                        mh.odometer_at_maintenance,
                        mh.notes,
                        DATE_FORMAT(mh.maintenance_date, '%d %b %Y') as maintenance_date_formatted,
                        DATE_FORMAT(mh.next_maintenance_date, '%d %b %Y') as next_maintenance_date_formatted
                    FROM maintenance_history mh
                    LEFT JOIN maintenance_types mt ON mh.maintenance_type_id = mt.id
                    LEFT JOIN users u ON mh.performed_by = u.id
                    WHERE mh.truck_id = ?
                    ORDER BY mh.maintenance_date DESC
                    LIMIT 10
                ");
                $maintenance_stmt->execute([$truck_info['id']]);
                $maintenance_history = $maintenance_stmt->fetchAll();
            }
        } catch (Exception $e) {
            error_log("Maintenance history query error: " . $e->getMessage());
        }
        
        // Upcoming maintenance
        try {
            if ($table_exists) {
                $upcoming_stmt = $db->prepare("
                    SELECT 
                        mh.*,
                        mt.name as maintenance_type,
                        DATEDIFF(mh.next_maintenance_date, CURDATE()) as days_remaining
                    FROM maintenance_history mh
                    LEFT JOIN maintenance_types mt ON mh.maintenance_type_id = mt.id
                    WHERE mh.truck_id = ? 
                    AND mh.next_maintenance_date >= CURDATE()
                    ORDER BY mh.next_maintenance_date ASC
                    LIMIT 5
                ");
                $upcoming_stmt->execute([$truck_info['id']]);
                $upcoming_maintenance = $upcoming_stmt->fetchAll();
            }
        } catch (Exception $e) {
            error_log("Upcoming maintenance query error: " . $e->getMessage());
        }
        
        // Get active orders for this truck
        try {
            $table_check = $db->prepare("SHOW TABLES LIKE 'orders'");
            $table_check->execute();
            $orders_table_exists = $table_check->fetch();
            
            if ($orders_table_exists) {
                $orders_stmt = $db->prepare("
                    SELECT 
                        o.*,
                        c.company_name,
                        c.phone as client_phone,
                        os.name as status_name,
                        os.color as status_color,
                        o.pickup_address,
                        o.delivery_address,
                        DATE_FORMAT(o.pickup_time, '%d %b %Y %H:%i') as pickup_time_formatted,
                        DATE_FORMAT(o.delivery_time, '%d %b %Y %H:%i') as delivery_time_formatted,
                        DATEDIFF(o.delivery_time, NOW()) as days_remaining
                    FROM orders o
                    LEFT JOIN clients c ON o.client_id = c.id
                    LEFT JOIN order_status os ON o.status_id = os.id
                    WHERE o.truck_id = ? AND o.status_id IN (3,4,5)
                    ORDER BY o.priority DESC, o.delivery_time ASC
                    LIMIT 5
                ");
                $orders_stmt->execute([$truck_info['id']]);
                $assigned_orders = $orders_stmt->fetchAll();
            }
        } catch (Exception $e) {
            error_log("Assigned orders query error: " . $e->getMessage());
        }
        
        // Get fuel consumption history - check if fuel_records table exists
        try {
            $table_check = $db->prepare("SHOW TABLES LIKE 'fuel_records'");
            $table_check->execute();
            $fuel_table_exists = $table_check->fetch();
            
            if ($fuel_table_exists) {
                $fuel_stmt = $db->prepare("
                    SELECT 
                        DATE_FORMAT(fuel_date, '%b %Y') as month,
                        SUM(fuel_amount) as total_fuel,
                        AVG(fuel_price_per_liter) as avg_price,
                        SUM(cost) as total_cost
                    FROM fuel_records 
                    WHERE truck_id = ? AND fuel_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY YEAR(fuel_date), MONTH(fuel_date)
                    ORDER BY YEAR(fuel_date) DESC, MONTH(fuel_date) DESC
                    LIMIT 6
                ");
                $fuel_stmt->execute([$truck_info['id']]);
                $fuel_consumption = $fuel_stmt->fetchAll();
            }
        } catch (Exception $e) {
            error_log("Fuel consumption query error: " . $e->getMessage());
        }
        
        // Calculate insurance status
        if ($truck_info['insurance_expiry']) {
            $insurance_expiry = new DateTime($truck_info['insurance_expiry']);
            $today = new DateTime();
            $days_until_expiry = $today->diff($insurance_expiry)->days;
            $truck_info['insurance_days_remaining'] = $days_until_expiry;
            $truck_info['insurance_status'] = $days_until_expiry <= 30 ? 'expiring_soon' : 
                                             ($days_until_expiry <= 0 ? 'expired' : 'valid');
        } else {
            $truck_info['insurance_status'] = 'unknown';
            $truck_info['insurance_days_remaining'] = null;
        }
        
        // Calculate maintenance status
        if ($truck_info['last_maintenance_date']) {
            $last_maintenance = new DateTime($truck_info['last_maintenance_date']);
            $today = new DateTime();
            $days_since_maintenance = $today->diff($last_maintenance)->days;
            
            // Assuming maintenance is needed every 90 days
            $days_until_next = 90 - $days_since_maintenance;
            $truck_info['maintenance_days_remaining'] = $days_until_next;
            $truck_info['maintenance_status'] = $days_until_next <= 7 ? 'due_soon' : 
                                               ($days_until_next <= 30 ? 'upcoming' : 'good');
        } else {
            $truck_info['maintenance_status'] = 'unknown';
            $truck_info['maintenance_days_remaining'] = null;
        }
        
        // Set default values for missing data
        $truck_info['utilization_percentage'] = 0;
        $truck_info['current_load'] = 0;
    }
    
} catch (Exception $e) {
    error_log("Truck page error: " . $e->getMessage());
    $error_message = "Error loading truck information: " . $e->getMessage();
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/driver/driver-sidebar.php';
?>

<style>
/* Prevent content from spilling over sidebar */
.driver-container {
    margin-left: 250px;
    padding: 20px;
    max-width: calc(100% - 250px);
    overflow-x: hidden;
    box-sizing: border-box;
}

@media (max-width: 992px) {
    .driver-container {
        margin-left: 0;
        max-width: 100%;
        padding: 15px;
    }
}

/* Ensure tables don't overflow */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Card adjustments */
.card {
    overflow: hidden;
}

/* Text truncation */
.text-truncate-multiline {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Fix for small screens */
@media (max-width: 768px) {
    .btn-group {
        flex-wrap: wrap;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
    }
    
    .col-lg-8, .col-lg-4, .col-lg-6 {
        padding-left: 10px;
        padding-right: 10px;
    }
}

/* Ensure proper spacing */
.row {
    margin-left: -10px;
    margin-right: -10px;
}

.col-lg-8, .col-lg-4, .col-lg-6 {
    padding-left: 10px;
    padding-right: 10px;
}

/* Fix for table cells */
.table td, .table th {
    white-space: nowrap;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* For addresses and longer text */
.table .text-truncate {
    max-width: 150px;
    display: inline-block;
    vertical-align: middle;
}
</style>

<!-- Driver Container -->
<div class="driver-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #3b82f6;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-truck me-2"></i>My Assigned Truck
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    <?php if (!empty($truck_info)): ?>
                        Viewing details for your assigned truck
                    <?php else: ?>
                        No truck assigned yet
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <?php if (!empty($truck_info)): ?>
                    <a href="trucks-report.php?id=<?php echo $truck_info['id']; ?>" class="btn btn-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> Report Issue
                    </a>
                    <a href="update-truck-status.php?truck_id=<?php echo $truck_info['id']; ?>" class="btn btn-warning">
                        <i class="fas fa-edit me-2"></i> Update Status
                    </a>
                <?php endif; ?>
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i> Print Details
                </button>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <?php if (empty($truck_info)): ?>
        <!-- No Truck Assigned -->
        <div class="card shadow-sm border-0">
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <i class="fas fa-truck fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted mb-3">No Truck Assigned</h4>
                    <p class="text-muted mb-4">You don't have a truck assigned to you yet.</p>
                </div>
                
                <?php if (!empty($driver_info)): ?>
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Driver Information:</strong><br>
                        <strong>Name:</strong> <?php echo htmlspecialchars($driver_info['driver_name']); ?><br>
                        <strong>License:</strong> <?php echo htmlspecialchars($driver_info['license_number']); ?><br>
                        <strong>Courier:</strong> <?php echo !empty($courier_info) ? htmlspecialchars($courier_info['name']) : 'Not assigned'; ?><br>
                        <strong>Driver ID:</strong> <?php echo $driver_info['id']; ?>
                    </div>
                <?php endif; ?>
                
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    Please contact your administrator or dispatcher to get a truck assigned.
                </div>
                <div class="mt-4">
                    <a href="dashboard.php" class="btn btn-primary me-2">
                        <i class="fas fa-home me-2"></i> Return to Dashboard
                    </a>
                    <a href="support.php" class="btn btn-outline-secondary">
                        <i class="fas fa-headset me-2"></i> Contact Support
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Truck Information Section -->
        <div class="row">
            <!-- Left Column: Truck Details -->
            <div class="col-lg-8">
                <!-- Truck Overview Card -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-truck me-2"></i>Truck Overview
                            </h5>
                            <span class="badge bg-<?php echo $truck_info['truck_status'] == 'available' ? 'success' : 
                                                      ($truck_info['truck_status'] == 'on_delivery' ? 'warning' : 
                                                      ($truck_info['truck_status'] == 'maintenance' ? 'danger' : 
                                                      ($truck_info['truck_status'] == 'assigned' ? 'primary' : 'secondary'))); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $truck_info['truck_status'])); ?>
                            </span>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <small class="text-muted d-block">Plate Number</small>
                                    <h4 class="mb-0 text-primary"><?php echo htmlspecialchars($truck_info['plate_number']); ?></h4>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted d-block">Model & Brand</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-car me-2 text-muted"></i>
                                        <span>
                                            <?php 
                                                $brand = htmlspecialchars($truck_info['brand'] ?? '');
                                                $model = htmlspecialchars($truck_info['model'] ?? '');
                                                echo !empty($brand) ? $brand . ' ' : '';
                                                echo $model;
                                            ?>
                                        </span>
                                        <?php if ($truck_info['year']): ?>
                                            <span class="ms-2 badge bg-light text-dark"><?php echo $truck_info['year']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted d-block">Capacity</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-weight me-2 text-muted"></i>
                                        <span><?php echo $truck_info['max_capacity']; ?> tons</span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted d-block">Fuel Type</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-gas-pump me-2 text-muted"></i>
                                        <span><?php echo htmlspecialchars(ucfirst($truck_info['fuel_type'] ?? 'Not specified')); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <small class="text-muted d-block">Current Location</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-map-marker-alt me-2 text-muted"></i>
                                        <span><?php echo htmlspecialchars($truck_info['current_location'] ?? 'Not specified'); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted d-block">Courier Company</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-building me-2 text-muted"></i>
                                        <span><?php echo htmlspecialchars($truck_info['courier_name'] ?? 'Not assigned'); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-muted d-block">Last Maintenance</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-tools me-2 text-muted"></i>
                                        <span>
                                            <?php if ($truck_info['last_maintenance_date']): ?>
                                                <?php echo date('d M Y', strtotime($truck_info['last_maintenance_date'])); ?>
                                            <?php else: ?>
                                                Not recorded
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($truck_info['truck_notes'])): ?>
                                <div class="mb-3">
                                    <small class="text-muted d-block">Notes</small>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-sticky-note me-2 text-muted"></i>
                                        <span><?php echo htmlspecialchars($truck_info['truck_notes']); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Performance Stats -->
                        <div class="row mt-3 pt-3 border-top">
                            <div class="col-md-4 text-center">
                                <div class="bg-light p-2 rounded">
                                    <small class="text-muted d-block">Active Deliveries</small>
                                    <h4 class="mb-0 text-primary"><?php echo $truck_info['active_deliveries'] ?? 0; ?></h4>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="bg-light p-2 rounded">
                                    <small class="text-muted d-block">Today's Deliveries</small>
                                    <h4 class="mb-0 text-success"><?php echo $truck_info['deliveries_today'] ?? 0; ?></h4>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <div class="bg-light p-2 rounded">
                                    <small class="text-muted d-block">Year Total</small>
                                    <h4 class="mb-0 text-info"><?php echo $truck_info['deliveries_this_year'] ?? 0; ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Deliveries Card -->
                <?php if (!empty($assigned_orders)): ?>
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-boxes me-2"></i>Active Deliveries
                            </h5>
                            <a href="orders.php?status=active" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Client</th>
                                        <th>Destination</th>
                                        <th>Status</th>
                                        <th>Delivery Time</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assigned_orders as $order): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-primary">#<?php echo $order['id']; ?></strong>
                                            </td>
                                            <td>
                                                <div class="small"><?php echo htmlspecialchars($order['company_name']); ?></div>
                                                <div class="text-muted smaller"><?php echo htmlspecialchars($order['client_phone']); ?></div>
                                            </td>
                                            <td>
                                                <div class="small text-truncate" style="max-width: 150px;" 
                                                     title="<?php echo htmlspecialchars($order['delivery_address']); ?>">
                                                    <?php echo htmlspecialchars($order['delivery_address']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge" style="background-color: <?php echo $order['status_color']; ?>;">
                                                    <?php echo ucfirst($order['status_name']); ?>
                                                </span>
                                                <?php if ($order['days_remaining'] < 0): ?>
                                                    <span class="badge bg-danger ms-1">Overdue</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small"><?php echo $order['delivery_time_formatted']; ?></div>
                                                <?php if ($order['days_remaining'] >= 0): ?>
                                                    <div class="text-muted smaller"><?php echo $order['days_remaining']; ?> days left</div>
                                                <?php else: ?>
                                                    <div class="text-danger smaller"><?php echo abs($order['days_remaining']); ?> days overdue</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="orders-view.php?id=<?php echo $order['id']; ?>" 
                                                       class="btn btn-outline-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="update-status.php?order_id=<?php echo $order['id']; ?>" 
                                                       class="btn btn-outline-warning" title="Update Status">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="https://maps.google.com/?q=<?php echo urlencode($order['delivery_address']); ?>" 
                                                       target="_blank" class="btn btn-outline-primary" title="Open in Maps">
                                                        <i class="fas fa-map"></i>
                                                    </a>
                                                </div>
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

            <!-- Right Column: Truck Status & Documents -->
            <div class="col-lg-4">
                <!-- Truck Status Card -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-clipboard-check me-2"></i>Truck Status
                        </h5>
                        
                        <!-- Insurance Status -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted">Insurance</small>
                                <?php if (isset($truck_info['insurance_status']) && $truck_info['insurance_status'] != 'unknown'): ?>
                                    <span class="badge bg-<?php echo $truck_info['insurance_status'] == 'valid' ? 'success' : 
                                                              ($truck_info['insurance_status'] == 'expiring_soon' ? 'warning' : 'danger'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $truck_info['insurance_status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($truck_info['insurance_number']): ?>
                                <div class="small">#<?php echo htmlspecialchars($truck_info['insurance_number']); ?></div>
                                <?php if ($truck_info['insurance_expiry']): ?>
                                    <div class="text-muted smaller">
                                        Expires: <?php echo date('d M Y', strtotime($truck_info['insurance_expiry'])); ?>
                                        <?php if (isset($truck_info['insurance_days_remaining'])): ?>
                                            (<?php echo $truck_info['insurance_days_remaining']; ?> days)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-muted small">No insurance information</div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Maintenance Status -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <small class="text-muted">Maintenance</small>
                                <?php if (isset($truck_info['maintenance_status']) && $truck_info['maintenance_status'] != 'unknown'): ?>
                                    <?php 
                                    $maintenance_badge_class = 'success';
                                    if ($truck_info['maintenance_status'] == 'due_soon') $maintenance_badge_class = 'danger';
                                    if ($truck_info['maintenance_status'] == 'upcoming') $maintenance_badge_class = 'warning';
                                    ?>
                                    <span class="badge bg-<?php echo $maintenance_badge_class; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $truck_info['maintenance_status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($truck_info['last_maintenance_date']): ?>
                                <div class="small">Last: <?php echo date('d M Y', strtotime($truck_info['last_maintenance_date'])); ?></div>
                                <?php if (isset($truck_info['maintenance_days_remaining'])): ?>
                                    <div class="text-muted smaller">
                                        Next due in: <?php echo $truck_info['maintenance_days_remaining']; ?> days
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-muted small">No maintenance recorded</div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Truck Information -->
                        <div class="mb-3">
                            <small class="text-muted d-block">Truck Information</small>
                            <div class="small">
                                <strong>ID:</strong> <?php echo $truck_info['id']; ?><br>
                                <strong>Assigned Date:</strong> <?php echo date('d M Y', strtotime($truck_info['updated_at'])); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Maintenance Card -->
                <?php if (!empty($upcoming_maintenance)): ?>
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-body">
                            <h5 class="card-title mb-3">
                                <i class="fas fa-tools me-2"></i>Upcoming Maintenance
                            </h5>
                            
                            <?php foreach ($upcoming_maintenance as $maintenance): ?>
                                <div class="mb-3 pb-3 border-bottom">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong><?php echo htmlspecialchars($maintenance['maintenance_type']); ?></strong>
                                        <span class="badge bg-<?php echo $maintenance['days_remaining'] <= 7 ? 'danger' : 
                                                                 ($maintenance['days_remaining'] <= 30 ? 'warning' : 'info'); ?>">
                                            <?php echo $maintenance['days_remaining']; ?> days
                                        </span>
                                    </div>
                                    <div class="text-muted smaller">
                                        Due: <?php echo date('d M Y', strtotime($maintenance['next_maintenance_date'])); ?>
                                    </div>
                                    <?php if ($maintenance['notes']): ?>
                                        <div class="small mt-1"><?php echo htmlspecialchars($maintenance['notes']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick Actions Card -->
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h5>
                        
                        <div class="d-grid gap-2">
                            <a href="trucks-report.php?id=<?php echo $truck_info['id']; ?>" class="btn btn-outline-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i> Report Issue
                            </a>
                            <a href="update-truck-status.php?truck_id=<?php echo $truck_info['id']; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-edit me-2"></i> Update Truck Status
                            </a>
                            <a href="fuel-log.php?truck_id=<?php echo $truck_info['id']; ?>" class="btn btn-outline-info">
                                <i class="fas fa-gas-pump me-2"></i> Log Fuel Usage
                            </a>
                            <a href="maintenance-request.php?truck_id=<?php echo $truck_info['id']; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-tools me-2"></i> Request Maintenance
                            </a>
                        </div>
                        
                        <hr class="my-3">
                        
                        <div class="small text-muted">
                            <p class="mb-2"><i class="fas fa-phone me-2"></i> Courier Contact:</p>
                            <?php if (!empty($truck_info['courier_phone'])): ?>
                                <div class="d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($truck_info['courier_phone']); ?></span>
                                    <a href="tel:<?php echo htmlspecialchars($truck_info['courier_phone']); ?>" class="text-primary">
                                        <i class="fas fa-phone-alt"></i>
                                    </a>
                                </div>
                            <?php elseif (!empty($courier_info['phone'])): ?>
                                <div class="d-flex justify-content-between">
                                    <span><?php echo htmlspecialchars($courier_info['phone']); ?></span>
                                    <a href="tel:<?php echo htmlspecialchars($courier_info['phone']); ?>" class="text-primary">
                                        <i class="fas fa-phone-alt"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($truck_info['courier_email'])): ?>
                                <div class="d-flex justify-content-between mt-1">
                                    <span><?php echo htmlspecialchars($truck_info['courier_email']); ?></span>
                                    <a href="mailto:<?php echo htmlspecialchars($truck_info['courier_email']); ?>" class="text-primary">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                </div>
                            <?php elseif (!empty($courier_info['email'])): ?>
                                <div class="d-flex justify-content-between mt-1">
                                    <span><?php echo htmlspecialchars($courier_info['email']); ?></span>
                                    <a href="mailto:<?php echo htmlspecialchars($courier_info['email']); ?>" class="text-primary">
                                        <i class="fas fa-envelope"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Information Section -->
        <div class="row mt-4">
            <!-- Recent Maintenance History -->
            <?php if (!empty($maintenance_history)): ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-history me-2"></i>Recent Maintenance History
                            </h5>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Performed By</th>
                                        <th>Cost</th>
                                        <th>Odometer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($maintenance_history as $maintenance): ?>
                                        <tr>
                                            <td><?php echo $maintenance['maintenance_date_formatted']; ?></td>
                                            <td><?php echo htmlspecialchars($maintenance['maintenance_type']); ?></td>
                                            <td><?php echo htmlspecialchars($maintenance['performed_by']); ?></td>
                                            <td>KSh <?php echo number_format($maintenance['cost'], 2); ?></td>
                                            <td><?php echo number_format($maintenance['odometer_at_maintenance']); ?> km</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Fuel Consumption -->
            <?php if (!empty($fuel_consumption)): ?>
            <div class="col-lg-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-chart-line me-2"></i>Fuel Consumption (Last 6 Months)
                        </h5>
                        
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Fuel Used (L)</th>
                                        <th>Avg Price/L</th>
                                        <th>Total Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fuel_consumption as $fuel): ?>
                                        <tr>
                                            <td><?php echo $fuel['month']; ?></td>
                                            <td><?php echo number_format($fuel['total_fuel'], 1); ?> L</td>
                                            <td>KSh <?php echo number_format($fuel['avg_price'], 2); ?></td>
                                            <td>KSh <?php echo number_format($fuel['total_cost'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript for Interactive Features -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh page every 5 minutes for live updates
    setInterval(() => {
        location.reload();
    }, 300000);

    // Print button functionality
    document.querySelectorAll('[onclick*="window.print"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const printContents = document.querySelector('.driver-container').innerHTML;
            const originalContents = document.body.innerHTML;
            
            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Truck Details - <?php echo htmlspecialchars($truck_info['plate_number'] ?? 'My Truck'); ?></title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        @media print {
                            .no-print { display: none !important; }
                            .card { border: 1px solid #dee2e6 !important; }
                            h1, h2, h3, h4, h5, h6 { color: #000 !important; }
                            .text-primary { color: #000 !important; }
                            .btn, .btn-group { display: none !important; }
                        }
                        body { font-size: 12px; }
                        .card-title { font-size: 14px; }
                        .small { font-size: 10px; }
                    </style>
                </head>
                <body>
                    <div class="container py-4">
                        <div class="text-center mb-4">
                            <h4>Truck Details Report</h4>
                            <p>Generated on: ${new Date().toLocaleString()}</p>
                            <hr>
                        </div>
                        ${printContents}
                    </div>
                </body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContents;
            location.reload();
        });
    });

    // Confirm report issue action
    document.querySelectorAll('a[href*="trucks-report.php"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to report an issue for this truck?')) {
                e.preventDefault();
            }
        });
    });

    // Add tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Make table rows clickable
    document.querySelectorAll('table.table-hover tbody tr').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function() {
            const viewLink = this.querySelector('a[href*="view"], a[href*="orders-view"]');
            if (viewLink) {
                window.location.href = viewLink.href;
            }
        });
    });
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>