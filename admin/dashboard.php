<?php
/**
 * Enhanced Admin Dashboard
 * 
 * Production-ready admin dashboard with operational metrics
 * Preserves original working database connection pattern
 * FIXED: Uses correct column names from your database schema
 * 
 * @package CourierTruckManagement
 * @subpackage Admin
 * @version 2.0.2
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
// 4. VERIFY DATABASE CONNECTION (PRESERVED EXACTLY AS WORKING)
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
    error_log("Dashboard database connection error: " . $connection_error);
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

$pageTitle = "Enhanced Admin Dashboard";

// ============================================================================
// 6. INITIALIZE VARIABLES (PRESERVED ORIGINAL + NEW ONES)
// ============================================================================
$stats = [];
$order_status = [];
$recent_orders = [];
$active_drivers = [];
$courier_utilization = [];
$activity_items = [];

// NEW: Enhanced metrics variables
$operational_metrics = [];
$fleet_status = [];
$driver_activity = [];
$alerts = [];
$delivery_trend = [];
$notifications = [];
$recent_user_activity = [];
$fleet_utilization = [];

// Default order status colors (preserved)
$statusColors = [
    'pending' => '#ffc107',
    'confirmed' => '#17a2b8',
    'assigned' => '#007bff',
    'picked_up' => '#6f42c1',
    'in_transit' => '#20c997',
    'out_for_delivery' => '#fd7e14',
    'delivered' => '#28a745',
    'cancelled' => '#dc3545',
    'returned' => '#6c757d'
];

// ============================================================================
// 7. GET DASHBOARD STATISTICS (PRESERVED ORIGINAL + NEW QUERIES)
// ============================================================================
if ($db_connected) {
    try {
        // ========== ORIGINAL STATS QUERY (PRESERVED EXACTLY) ==========
        $stmt = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM couriers WHERE status = 'active') as total_couriers,
                (SELECT COUNT(*) FROM trucks) as total_trucks,
                (SELECT COUNT(*) FROM drivers WHERE status IN ('available', 'on_delivery', 'on_break')) as total_drivers,
                (SELECT COUNT(*) FROM clients) as total_clients,
                (SELECT COUNT(*) FROM orders) as total_orders,
                (SELECT COUNT(*) FROM trucks WHERE status = 'available') as available_trucks,
                (SELECT COUNT(*) FROM trucks WHERE status IN ('assigned', 'on_delivery')) as trucks_on_delivery,
                (SELECT COUNT(*) FROM trucks WHERE status = 'maintenance') as trucks_maintenance,
                (SELECT COUNT(*) FROM drivers WHERE status = 'available') as drivers_available,
                (SELECT COUNT(*) FROM drivers WHERE status = 'on_delivery') as drivers_on_delivery,
                (SELECT COUNT(*) FROM drivers WHERE status = 'on_break') as drivers_on_break,
                (SELECT COUNT(*) FROM drivers WHERE status = 'inactive') as drivers_inactive
        ");
        $stmt->execute();
        $stats = $stmt->fetch();
        
        if (!$stats) {
            $stats = [];
        }

        // ========== NEW: Enhanced Operational Metrics (FIXED: removed expected_delivery) ==========
        $metrics_stmt = $db->prepare("
            SELECT
                -- Active Deliveries (orders in progress)
                (SELECT COUNT(*) FROM orders WHERE status_id IN (
                    SELECT id FROM order_status WHERE name IN ('assigned', 'picked_up', 'in_transit', 'out_for_delivery')
                )) as active_deliveries,
                
                -- Pending Orders
                (SELECT COUNT(*) FROM orders WHERE status_id IN (
                    SELECT id FROM order_status WHERE name = 'pending'
                )) as pending_orders,
                
                -- Completed Today
                (SELECT COUNT(*) FROM orders WHERE status_id IN (
                    SELECT id FROM order_status WHERE name = 'delivered'
                ) AND DATE(updated_at) = CURDATE()) as completed_today,
                
                -- Delayed Deliveries - Using estimated_delivery instead (FIXED)
                (SELECT COUNT(*) FROM orders WHERE estimated_delivery < NOW() 
                 AND status_id NOT IN (SELECT id FROM order_status WHERE name IN ('delivered', 'cancelled', 'returned'))) as delayed_deliveries,
                
                -- Delivery Success Rate (last 30 days)
                (SELECT 
                    ROUND(
                        (COUNT(CASE WHEN status_id IN (SELECT id FROM order_status WHERE name = 'delivered') THEN 1 END) * 100.0) / 
                        NULLIF(COUNT(*), 0), 1
                    ) 
                FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as delivery_success_rate,
                
                -- Average Delivery Time (hours) - Using created_at and updated_at
                (SELECT 
                    ROUND(AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)), 1) 
                FROM orders 
                WHERE status_id IN (SELECT id FROM order_status WHERE name = 'delivered')
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as avg_delivery_time
        ");
        $metrics_stmt->execute();
        $operational_metrics = $metrics_stmt->fetch();

        // ========== NEW: Fleet Status Overview ==========
        $fleet_stmt = $db->prepare("
            SELECT 
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN status IN ('assigned', 'on_delivery') THEN 1 ELSE 0 END) as on_delivery,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                SUM(CASE WHEN status = 'out_of_service' THEN 1 ELSE 0 END) as inactive
            FROM trucks
        ");
        $fleet_stmt->execute();
        $fleet_status = $fleet_stmt->fetch();

        // Calculate fleet utilization percentages (memory efficient)
        $total_trucks = ($fleet_status['available'] ?? 0) + ($fleet_status['on_delivery'] ?? 0) + 
                       ($fleet_status['maintenance'] ?? 0) + ($fleet_status['inactive'] ?? 0);
        if ($total_trucks > 0) {
            $fleet_utilization = [
                'delivering' => round((($fleet_status['on_delivery'] ?? 0) / $total_trucks) * 100),
                'idle' => round((($fleet_status['available'] ?? 0) / $total_trucks) * 100),
                'maintenance' => round((($fleet_status['maintenance'] ?? 0) / $total_trucks) * 100)
            ];
        }

        // ========== NEW: Driver Activity Overview ==========
        $driver_stmt = $db->prepare("
            SELECT 
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN status = 'on_delivery' THEN 1 ELSE 0 END) as on_delivery,
                SUM(CASE WHEN status = 'on_break' THEN 1 ELSE 0 END) as on_break,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
            FROM drivers
        ");
        $driver_stmt->execute();
        $driver_activity = $driver_stmt->fetch();

        // ========== ORIGINAL ORDER STATUS (PRESERVED) ==========
        $order_status_stmt = $db->prepare("
            SELECT os.id, os.name, os.color, COUNT(o.id) as count 
            FROM order_status os 
            LEFT JOIN orders o ON os.id = o.status_id 
            GROUP BY os.id, os.name, os.color, os.sequence 
            ORDER BY os.sequence
        ");
        $order_status_stmt->execute();
        $order_status = $order_status_stmt->fetchAll();

        // ========== NEW: Delivery Trend (Last 7 days) ==========
        $trend_stmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_orders,
                SUM(CASE WHEN status_id IN (SELECT id FROM order_status WHERE name = 'delivered') THEN 1 ELSE 0 END) as delivered
            FROM orders
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
            LIMIT 7
        ");
        $trend_stmt->execute();
        $delivery_trend = $trend_stmt->fetchAll();

        // ========== ORIGINAL RECENT ORDERS (ENHANCED with destination) ==========
        $recent_orders_stmt = $db->prepare("
            SELECT 
                o.id, 
                o.tracking_number, 
                o.created_at,
                o.delivery_location as destination_address,
                COALESCE(c.company_name, 'No Client') as company_name, 
                COALESCE(os.name, 'Unknown') as status_name, 
                COALESCE(os.color, '#6c757d') as status_color,
                COALESCE(u.name, 'Unassigned') as driver_name,
                o.estimated_delivery
            FROM orders o
            LEFT JOIN clients c ON o.client_id = c.id
            LEFT JOIN order_status os ON o.status_id = os.id
            LEFT JOIN drivers d ON o.driver_id = d.id
            LEFT JOIN users u ON d.user_id = u.id
            ORDER BY o.created_at DESC 
            LIMIT 10
        ");
        $recent_orders_stmt->execute();
        $recent_orders = $recent_orders_stmt->fetchAll();

        // ========== ORIGINAL ACTIVE DRIVERS (PRESERVED) ==========
        $active_drivers_stmt = $db->prepare("
            SELECT 
                d.id,
                u.name as driver_name, 
                d.status,
                COALESCE(t.plate_number, 'No Truck') as plate_number,
                COALESCE(c.name, 'No Courier') as courier_name,
                d.rating,
                d.total_deliveries
            FROM drivers d
            INNER JOIN users u ON d.user_id = u.id
            LEFT JOIN trucks t ON d.id = t.driver_id
            LEFT JOIN couriers c ON d.courier_id = c.id
            WHERE d.status IN ('available', 'on_delivery')
            ORDER BY FIELD(d.status, 'on_delivery', 'available'), d.rating DESC
            LIMIT 8
        ");
        $active_drivers_stmt->execute();
        $active_drivers = $active_drivers_stmt->fetchAll();

        // ========== NEW: Operational Alerts (FIXED: using estimated_delivery) ==========
        $alerts_stmt = $db->prepare("
            -- Trucks needing maintenance (last maintenance > 30 days ago)
            (SELECT 'warning' as type, CONCAT('Truck ', plate_number, ' needs maintenance') as message, 'maintenance' as category
            FROM trucks 
            WHERE status = 'maintenance' AND last_maintenance < DATE_SUB(NOW(), INTERVAL 30 DAY)
            LIMIT 2)
            
            UNION ALL
            
            -- Delayed deliveries (using estimated_delivery) (FIXED)
            (SELECT 'danger' as type, CONCAT('Order #', id, ' (', tracking_number, ') delayed') as message, 'delay' as category
            FROM orders
            WHERE estimated_delivery < NOW() 
            AND status_id NOT IN (SELECT id FROM order_status WHERE name IN ('delivered', 'cancelled', 'returned'))
            LIMIT 2)
            
            UNION ALL
            
            -- Drivers inactive > 7 days (using last_login from users table) (FIXED)
            (SELECT 'warning' as type, CONCAT('Driver ', u.name, ' inactive') as message, 'inactive' as category
            FROM drivers d
            JOIN users u ON d.user_id = u.id
            WHERE d.status = 'inactive' AND u.last_login < DATE_SUB(NOW(), INTERVAL 7 DAY)
            LIMIT 2)
            
            LIMIT 5
        ");
        $alerts_stmt->execute();
        $alerts = $alerts_stmt->fetchAll();

        // ========== NEW: Notifications Center ==========
        $notifications_stmt = $db->prepare("
            (SELECT 'new_order' as type, CONCAT('New order: ', tracking_number) as message, created_at
            FROM orders
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 3)
            
            UNION ALL
            
            (SELECT 'driver_assigned' as type, 
                    CONCAT('Driver assigned to order #', order_id) as message,
                    created_at
            FROM order_logs
            WHERE status_id IN (SELECT id FROM order_status WHERE name = 'assigned') 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            LIMIT 3)
            
            ORDER BY created_at DESC
            LIMIT 8
        ");
        $notifications_stmt->execute();
        $notifications = $notifications_stmt->fetchAll();

        // ========== ORIGINAL COURIER UTILIZATION (PRESERVED) ==========
        $courier_stmt = $db->prepare("
            SELECT 
                c.id,
                c.name,
                c.contact_person,
                COUNT(DISTINCT t.id) as truck_count,
                COUNT(DISTINCT d.id) as driver_count
            FROM couriers c
            LEFT JOIN trucks t ON c.id = t.courier_id
            LEFT JOIN drivers d ON c.id = d.courier_id AND d.status IN ('available', 'on_delivery')
            WHERE c.status = 'active'
            GROUP BY c.id
            ORDER BY truck_count DESC
            LIMIT 5
        ");
        $courier_stmt->execute();
        $courier_utilization = $courier_stmt->fetchAll();

        // ========== ORIGINAL ACTIVITY LOGS (PRESERVED) ==========
        $activity_stmt = $db->prepare("
            SELECT al.*, u.name as user_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 10
        ");
        $activity_stmt->execute();
        $activity_items = $activity_stmt->fetchAll();

        // ========== NEW: User Activity Monitoring (FIXED: using last_login) ==========
        $user_activity_stmt = $db->prepare("
            SELECT 
                MAX(CASE WHEN id = ? THEN last_login END) as last_admin_login,
                COUNT(CASE WHEN last_login >= CURDATE() THEN 1 END) as today_logins,
                COUNT(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as active_users_24h
            FROM users
            WHERE status = 'active'
        ");
        $user_activity_stmt->execute([$_SESSION['user_id']]);
        $recent_user_activity = $user_activity_stmt->fetch();

    } catch (Exception $e) {
        error_log("Dashboard query error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading dashboard data: " . $e->getMessage();
    }
} else {
    $_SESSION['error_message'] = "Database connection error: " . ($connection_error ?? 'Unknown error');
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

// ============================================================================
// 9. HANDLE FILTERS (GET parameters)
// ============================================================================
$date_filter = $_GET['date_range'] ?? 'today';
$courier_filter = $_GET['courier_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// ============================================================================
// 10. CHECK FOR MESSAGES (Same as working files)
// ============================================================================
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// ============================================================================
// 11. INCLUDE HEADER AND SIDEBAR (Same as working files)
// ============================================================================
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<!-- ============================================================================
     Enhanced Dashboard Content - Preserves original structure while adding new features
     ============================================================================ -->
<div class="main-container" style="margin-left: 250px; padding: 20px; min-height: 100vh;">
    
    <!-- Page Header with Search Bar (NEW) -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #306998;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #0D2B4E; font-weight: 700;">
                    <i class="fas fa-tachometer-alt me-2"></i>Operations Dashboard
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Welcome back, <span class="fw-bold" style="color: #306998;"><?php echo htmlspecialchars($user_name); ?></span>
                    • <?php echo date('l, F j, Y'); ?> • <?php echo date('H:i'); ?>
                </p>
            </div>
            
            <!-- Global Search Bar (NEW - Feature 14) -->
            <div class="d-flex gap-2 mt-2 mt-md-0">
                <form class="d-flex" action="search.php" method="GET">
                    <div class="input-group">
                        <input type="text" class="form-control" name="q" placeholder="Search orders, drivers, trucks..." style="min-width: 250px;">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
                <button class="btn btn-outline-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
        
        <!-- Dashboard Filters (NEW - Feature 18) -->
        <div class="row mt-3">
            <div class="col-md-9">
                <form method="GET" class="d-flex flex-wrap gap-2">
                    <select name="date_range" class="form-select form-select-sm" style="width: auto;">
                        <option value="today" <?php echo $date_filter == 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo $date_filter == 'week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo $date_filter == 'month' ? 'selected' : ''; ?>>This Month</option>
                    </select>
                    
                    <select name="courier_id" class="form-select form-select-sm" style="width: auto;">
                        <option value="">All Couriers</option>
                        <?php if (!empty($courier_utilization)): ?>
                            <?php foreach ($courier_utilization as $courier): ?>
                                <option value="<?php echo $courier['id']; ?>" <?php echo $courier_filter == $courier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($courier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    
                    <select name="status" class="form-select form-select-sm" style="width: auto;">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="delivered" <?php echo $status_filter == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    </select>
                    
                    <button type="submit" class="btn btn-sm btn-primary">Apply Filters</button>
                    <a href="?" class="btn btn-sm btn-outline-secondary">Clear</a>
                </form>
            </div>
        </div>
    </div>

    <!-- Messages (Preserved) -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$db_connected): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Database Connection Issue:</strong> Unable to connect to the database. Some dashboard features may be limited.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- System Health Monitoring (NEW - Feature 12) -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex gap-3 small">
                <span class="badge <?php echo $db_connected ? 'bg-success' : 'bg-danger'; ?>">
                    <i class="fas fa-database me-1"></i>DB: <?php echo $db_connected ? 'Connected' : 'Error'; ?>
                </span>
                <span class="badge bg-info">
                    <i class="fas fa-users me-1"></i>Active Users (24h): <?php echo $recent_user_activity['active_users_24h'] ?? 0; ?>
                </span>
                <span class="badge bg-secondary">
                    <i class="fas fa-clock me-1"></i>Last Login: <?php echo isset($recent_user_activity['last_admin_login']) ? date('H:i', strtotime($recent_user_activity['last_admin_login'])) : 'Today'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Operational Alerts Panel (NEW - Feature 8) -->
    <?php if (!empty($alerts)): ?>
        <div class="alert alert-warning alert-dismissible fade show mb-4" role="alert">
            <strong><i class="fas fa-exclamation-triangle me-2"></i>Operational Alerts:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($alerts as $alert): ?>
                    <li class="text-<?php echo $alert['type']; ?>">
                        <?php echo htmlspecialchars($alert['message']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ENHANCED KPI CARDS (Feature 1) - 6 Key Operational Metrics -->
    <div class="row mb-4">
        <div class="col-md-2 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Active Deliveries</h6>
                            <h3 class="mb-0"><?php echo number_format($operational_metrics['active_deliveries'] ?? 0); ?></h3>
                        </div>
                        <div class="text-primary"><i class="fas fa-truck-loading fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Pending Orders</h6>
                            <h3 class="mb-0"><?php echo number_format($operational_metrics['pending_orders'] ?? 0); ?></h3>
                        </div>
                        <div class="text-warning"><i class="fas fa-clock fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Completed Today</h6>
                            <h3 class="mb-0"><?php echo number_format($operational_metrics['completed_today'] ?? 0); ?></h3>
                        </div>
                        <div class="text-success"><i class="fas fa-check-circle fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Available Drivers</h6>
                            <h3 class="mb-0"><?php echo number_format($stats['drivers_available'] ?? 0); ?></h3>
                        </div>
                        <div class="text-info"><i class="fas fa-user-check fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Available Trucks</h6>
                            <h3 class="mb-0"><?php echo number_format($stats['available_trucks'] ?? 0); ?></h3>
                        </div>
                        <div class="text-secondary"><i class="fas fa-truck fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Delayed</h6>
                            <h3 class="mb-0"><?php echo number_format($operational_metrics['delayed_deliveries'] ?? 0); ?></h3>
                        </div>
                        <div class="text-danger"><i class="fas fa-exclamation-triangle fa-2x"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery Performance Cards (Feature 2) -->
    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Delivery Success Rate</h6>
                    <div class="d-flex align-items-center">
                        <h3 class="mb-0"><?php echo $operational_metrics['delivery_success_rate'] ?? 0; ?>%</h3>
                        <div class="ms-2">
                            <?php 
                            $rate = $operational_metrics['delivery_success_rate'] ?? 0;
                            if ($rate >= 95): ?>
                                <span class="badge bg-success">Excellent</span>
                            <?php elseif ($rate >= 85): ?>
                                <span class="badge bg-warning">Good</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Needs Improvement</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Avg. Delivery Time</h6>
                    <div class="d-flex align-items-center">
                        <h3 class="mb-0"><?php echo $operational_metrics['avg_delivery_time'] ?? 0; ?></h3>
                        <span class="ms-1 text-muted">hours</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Today's Completed</h6>
                    <h3 class="mb-0"><?php echo number_format($operational_metrics['completed_today'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-2">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Delayed Deliveries</h6>
                    <h3 class="mb-0 text-danger"><?php echo number_format($operational_metrics['delayed_deliveries'] ?? 0); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Fleet Status & Driver Activity Row (Features 3 & 4) -->
    <div class="row mb-4">
        <!-- Fleet Status Overview -->
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0" style="color: #0D2B4E;">
                        <i class="fas fa-truck me-2"></i>Fleet Status Overview
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="p-2 bg-success bg-opacity-10 rounded">
                                <h5><?php echo $fleet_status['available'] ?? 0; ?></h5>
                                <small class="text-muted">Available</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="p-2 bg-warning bg-opacity-10 rounded">
                                <h5><?php echo $fleet_status['on_delivery'] ?? 0; ?></h5>
                                <small class="text-muted">On Delivery</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="p-2 bg-danger bg-opacity-10 rounded">
                                <h5><?php echo $fleet_status['maintenance'] ?? 0; ?></h5>
                                <small class="text-muted">Maintenance</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="p-2 bg-secondary bg-opacity-10 rounded">
                                <h5><?php echo $fleet_status['inactive'] ?? 0; ?></h5>
                                <small class="text-muted">Inactive</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fleet Utilization Chart (Feature 11) - Simple CSS bars -->
                    <?php if (!empty($fleet_utilization)): ?>
                        <div class="mt-3">
                            <small class="text-muted">Fleet Utilization</small>
                            <div class="d-flex align-items-center mt-1">
                                <span class="me-2" style="width: 70px;">Delivering:</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $fleet_utilization['delivering'] ?? 0; ?>%"></div>
                                </div>
                                <span class="ms-2"><?php echo $fleet_utilization['delivering'] ?? 0; ?>%</span>
                            </div>
                            <div class="d-flex align-items-center mt-1">
                                <span class="me-2" style="width: 70px;">Idle:</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $fleet_utilization['idle'] ?? 0; ?>%"></div>
                                </div>
                                <span class="ms-2"><?php echo $fleet_utilization['idle'] ?? 0; ?>%</span>
                            </div>
                            <div class="d-flex align-items-center mt-1">
                                <span class="me-2" style="width: 70px;">Maintenance:</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo $fleet_utilization['maintenance'] ?? 0; ?>%"></div>
                                </div>
                                <span class="ms-2"><?php echo $fleet_utilization['maintenance'] ?? 0; ?>%</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Driver Activity Overview -->
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0" style="color: #0D2B4E;">
                        <i class="fas fa-users me-2"></i>Driver Activity Overview
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="p-2 bg-success bg-opacity-10 rounded">
                                <h5><?php echo $driver_activity['available'] ?? 0; ?></h5>
                                <small class="text-muted">Available</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="p-2 bg-warning bg-opacity-10 rounded">
                                <h5><?php echo $driver_activity['on_delivery'] ?? 0; ?></h5>
                                <small class="text-muted">On Delivery</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="p-2 bg-info bg-opacity-10 rounded">
                                <h5><?php echo $driver_activity['on_break'] ?? 0; ?></h5>
                                <small class="text-muted">On Break</small>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="p-2 bg-secondary bg-opacity-10 rounded">
                                <h5><?php echo $driver_activity['inactive'] ?? 0; ?></h5>
                                <small class="text-muted">Inactive</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Driver Status Summary -->
                    <div class="mt-3">
                        <small class="text-muted">Total Active: <?php echo ($driver_activity['available'] ?? 0) + ($driver_activity['on_delivery'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Status Distribution (Feature 5) - Enhanced with progress bars -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-chart-pie me-2"></i>Order Status Distribution
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($order_status)): ?>
                <?php 
                $total_orders = array_sum(array_column($order_status, 'count'));
                ?>
                <div class="row">
                    <?php foreach ($order_status as $status): ?>
                        <?php 
                        $percentage = $total_orders > 0 ? round(($status['count'] / $total_orders) * 100) : 0;
                        ?>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="text-center p-3 border rounded">
                                <div style="width: 20px; height: 20px; background-color: <?php echo htmlspecialchars($status['color'] ?? '#6c757d'); ?>; border-radius: 50%; margin: 0 auto 10px;"></div>
                                <h5 class="mb-1"><?php echo number_format($status['count'] ?? 0); ?></h5>
                                <small class="text-muted"><?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($status['name'] ?? 'Unknown'))); ?></small>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar" style="width: <?php echo $percentage; ?>%; background-color: <?php echo htmlspecialchars($status['color'] ?? '#6c757d'); ?>;"></div>
                                </div>
                                <small class="text-muted"><?php echo $percentage; ?>%</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3 mb-0">No order status data available</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delivery Trend Analytics (Feature 10) - Simple CSS chart -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-chart-line me-2"></i>7-Day Delivery Trend
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($delivery_trend)): ?>
                <div class="d-flex justify-content-between align-items-end" style="height: 150px;">
                    <?php 
                    $max_orders = max(array_column($delivery_trend, 'total_orders') ?: [1]);
                    foreach ($delivery_trend as $day): 
                        $height = $max_orders > 0 ? ($day['total_orders'] / $max_orders) * 100 : 0;
                    ?>
                        <div class="text-center" style="flex: 1;">
                            <div style="height: 100px; display: flex; align-items: flex-end; justify-content: center;">
                                <div style="width: 30px; height: <?php echo $height; ?>px; background-color: #306998; border-radius: 5px 5px 0 0;"></div>
                            </div>
                            <small><?php echo date('D', strtotime($day['date'])); ?></small>
                            <div><small><?php echo $day['total_orders']; ?></small></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-2">
                    <span class="badge bg-info">Total: <?php echo array_sum(array_column($delivery_trend, 'total_orders')); ?></span>
                    <span class="badge bg-success">Delivered: <?php echo array_sum(array_column($delivery_trend, 'delivered')); ?></span>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3">No trend data available for the last 7 days</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notifications Center (Feature 13) -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0" style="color: #0D2B4E;">
                        <i class="fas fa-bell me-2"></i>Recent Notifications
                    </h5>
                </div>
                <div class="card-body" style="max-height: 200px; overflow-y: auto;">
                    <?php if (!empty($notifications)): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($notifications as $note): ?>
                                <div class="list-group-item px-0 py-2 border-0">
                                    <i class="fas fa-circle me-2" style="color: #306998; font-size: 8px;"></i>
                                    <small><?php echo htmlspecialchars($note['message']); ?></small>
                                    <small class="text-muted d-block">
                                        <?php echo date('H:i', strtotime($note['created_at'])); ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-2 mb-0">No new notifications</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Role Activity Monitoring (Feature 15) -->
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0" style="color: #0D2B4E;">
                        <i class="fas fa-shield-alt me-2"></i>Security Activity
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <div class="text-center p-2">
                                <h6><?php echo $recent_user_activity['today_logins'] ?? 0; ?></h6>
                                <small class="text-muted">Logins Today</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center p-2">
                                <h6><?php echo $recent_user_activity['active_users_24h'] ?? 0; ?></h6>
                                <small class="text-muted">Active Users (24h)</small>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($recent_user_activity['last_admin_login'])): ?>
                        <div class="mt-2 small text-muted">
                            Last admin login: <?php echo date('M d, H:i', strtotime($recent_user_activity['last_admin_login'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Drivers (Preserved Original) -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-users me-2"></i>Active Drivers
            </h5>
            <a href="drivers.php" class="btn btn-sm btn-outline-primary">View All Drivers</a>
        </div>
        <div class="card-body">
            <?php if (!empty($active_drivers)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Driver</th>
                                <th>Courier</th>
                                <th>Truck</th>
                                <th>Status</th>
                                <th>Rating</th>
                                <th>Deliveries</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_drivers as $driver): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($driver['driver_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($driver['courier_name'] ?? 'No Courier'); ?></td>
                                    <td><?php echo htmlspecialchars($driver['plate_number'] ?? 'No Truck'); ?></td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        switch ($driver['status'] ?? '') {
                                            case 'available':
                                                $status_class = 'badge bg-success';
                                                break;
                                            case 'on_delivery':
                                                $status_class = 'badge bg-warning';
                                                break;
                                            default:
                                                $status_class = 'badge bg-secondary';
                                        }
                                        ?>
                                        <span class="<?php echo $status_class; ?>">
                                            <?php echo ucfirst($driver['status'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($driver['rating'] ?? 0, 1); ?> ⭐</td>
                                    <td><?php echo number_format($driver['total_deliveries'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3 mb-0">No active drivers found</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Orders (Enhanced with destination - Feature 6) -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-clock me-2"></i>Recent Orders
            </h5>
            <a href="orders.php" class="btn btn-sm btn-outline-primary">View All Orders</a>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($recent_orders)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order #</th>
                                <th>Tracking</th>
                                <th>Client</th>
                                <th>Destination</th>
                                <th>Driver</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($order['id']); ?></td>
                                    <td><?php echo htmlspecialchars($order['tracking_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($order['company_name'] ?? 'No Client'); ?></td>
                                    <td>
                                        <?php 
                                        $dest = $order['destination_address'] ?? 'N/A';
                                        echo htmlspecialchars(strlen($dest) > 20 ? substr($dest, 0, 20) . '...' : $dest);
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($order['driver_name'] ?? 'Unassigned'); ?></td>
                                    <td>
                                        <span class="badge" style="background-color: <?php echo htmlspecialchars($order['status_color'] ?? '#6c757d'); ?>; color: white;">
                                            <?php echo ucfirst(str_replace('_', ' ', $order['status_name'] ?? 'Unknown')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, H:i', strtotime($order['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3 mb-0">No recent orders found</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions (Enhanced - Feature 9) -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-bolt me-2"></i>Quick Actions
            </h5>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <a href="orders-add.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i> Create New Order
                </a>
                <a href="drivers-add.php" class="btn btn-outline-warning">
                    <i class="fas fa-user-plus me-2"></i> Register Driver
                </a>
                <a href="trucks-add.php" class="btn btn-outline-info">
                    <i class="fas fa-truck-plus me-2"></i> Add Truck
                </a>
                <a href="couriers-add.php" class="btn btn-outline-success">
                    <i class="fas fa-building me-2"></i> Add Courier
                </a>
                <a href="assign-orders.php" class="btn btn-outline-secondary">
                    <i class="fas fa-tasks me-2"></i> Assign Orders
                </a>
                <a href="reports.php" class="btn btn-outline-danger">
                    <i class="fas fa-chart-bar me-2"></i> Reports
                </a>
            </div>
        </div>
    </div>

    <!-- Reports Shortcut Section (Feature 16) -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-file-alt me-2"></i>Quick Reports
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 col-6 mb-2">
                    <a href="reports.php?type=delivery" class="btn btn-outline-primary w-100">
                        <i class="fas fa-truck me-2"></i>Delivery Performance
                    </a>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <a href="reports.php?type=fleet" class="btn btn-outline-success w-100">
                        <i class="fas fa-truck-moving me-2"></i>Fleet Utilization
                    </a>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <a href="reports.php?type=driver" class="btn btn-outline-warning w-100">
                        <i class="fas fa-users me-2"></i>Driver Activity
                    </a>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <a href="reports.php?type=revenue" class="btn btn-outline-danger w-100">
                        <i class="fas fa-dollar-sign me-2"></i>Revenue Report
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity (Preserved Original) -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-history me-2"></i>Recent Activity
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($activity_items)): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($activity_items as $activity): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></h6>
                                <small class="text-muted"><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></small>
                            </div>
                            <p class="mb-1"><?php echo htmlspecialchars($activity['action'] ?? 'Performed action'); ?></p>
                            <?php if (!empty($activity['description'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($activity['description']); ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted text-center py-3 mb-0">No recent activity found</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer Stats (Preserved) -->
    <div class="mt-4 d-flex justify-content-between align-items-center">
        <div class="text-muted small">
            System Status: <span class="badge <?php echo $db_connected ? 'bg-success' : 'bg-danger'; ?>">
                <?php echo $db_connected ? 'Database Connected' : 'Database Error'; ?>
            </span>
        </div>
        <div class="text-muted small">
            Last Updated: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</div>

<!-- Auto-hide alerts after 5 seconds - Same as drivers.php -->
<script>
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
</script>

<?php
// ============================================================================
// 12. INCLUDE FOOTER (Same as working files)
// ============================================================================
require_once ROOT_PATH . '/includes/footer.php';
?>