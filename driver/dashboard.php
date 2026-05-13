<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base directory
$base_dir = realpath(dirname(__DIR__));

// Include configuration files
require_once $base_dir . '/config/database.php';
require_once $base_dir . '/config/constants.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// Check if user is driver
if ($_SESSION['role_id'] != ROLE_DRIVER) {
    switch ($_SESSION['role_id']) {
        case ROLE_ADMIN:
            header('Location: ../admin/dashboard.php');
            break;
        case ROLE_CLIENT:
            header('Location: ../client/dashboard.php');
            break;
        default:
            header('Location: ../index.php');
    }
    exit();
}

$pageTitle = "Driver Dashboard";
$driver_id = $_SESSION['user_id'];

// Include header and sidebar
require_once $base_dir . '/includes/header.php';
require_once $base_dir . '/driver/driver-sidebar.php';

// Get driver-specific statistics
$stats = [];
$active_orders = [];
$recent_deliveries = [];
$notifications = [];
$truck_info = [];
$driver_status = 'available';

// Driver status colors
$driverStatusColors = [
    'available' => '#28a745',
    'on_delivery' => '#007bff',
    'on_break' => '#ffc107',
    'off_duty' => '#6c757d',
    'inactive' => '#dc3545'
];

// Check if database connection exists
if (isset($db)) {
    try {
        // Get driver info
        $driver_stmt = $db->prepare("
            SELECT d.*, u.name, u.email, u.phone, d.status as driver_status 
            FROM drivers d 
            INNER JOIN users u ON d.user_id = u.id 
            WHERE d.user_id = ?
        ");
        $driver_stmt->execute([$driver_id]);
        $driver_data = $driver_stmt->fetch();
        
        if ($driver_data) {
            $driver_status = $driver_data['driver_status'];
            
            // Get assigned truck info
            $truck_stmt = $db->prepare("
                SELECT t.*, c.name as courier_name 
                FROM trucks t 
                LEFT JOIN couriers c ON t.courier_id = c.id 
                WHERE t.driver_id = ? AND t.status != 'inactive'
            ");
            $truck_stmt->execute([$driver_data['id']]);
            $truck_info = $truck_stmt->fetch();
            
            // Get summary stats
            $today = date('Y-m-d');
            
            $stats_stmt = $db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM orders WHERE driver_id = ? AND status_id IN (3,4,5,6)) as active_orders,
                    (SELECT COUNT(*) FROM orders WHERE driver_id = ? AND status_id = 6 AND DATE(updated_at) = ?) as delivered_today,
                    (SELECT COUNT(*) FROM orders WHERE driver_id = ? AND status_id = 1) as pending_orders,
                    (SELECT COUNT(*) FROM orders WHERE driver_id = ? AND status_id = 6 AND DATE(updated_at) >= DATE_SUB(?, INTERVAL 7 DAY)) as delivered_this_week
            ");
            $stats_stmt->execute([$driver_data['id'], $driver_data['id'], $today, $driver_data['id'], $driver_data['id'], $today]);
            $stats = $stats_stmt->fetch();
            
            // Get active orders (limit 5)
            $active_orders_stmt = $db->prepare("
                SELECT o.*, c.company_name, c.address as client_address, 
                       c.phone as client_phone, os.name as status_name, 
                       os.color as status_color,
                       o.pickup_address, o.delivery_address,
                       DATE_FORMAT(o.pickup_time, '%Y-%m-%d %H:%i') as pickup_time_formatted,
                       DATE_FORMAT(o.delivery_time, '%Y-%m-%d %H:%i') as delivery_time_formatted
                FROM orders o
                LEFT JOIN clients c ON o.client_id = c.id
                LEFT JOIN order_status os ON o.status_id = os.id
                WHERE o.driver_id = ? AND o.status_id IN (3,4,5)
                ORDER BY o.priority DESC, o.delivery_time ASC
                LIMIT 5
            ");
            $active_orders_stmt->execute([$driver_data['id']]);
            $active_orders = $active_orders_stmt->fetchAll();
            
            // Get recent deliveries (last 5)
            $recent_deliveries_stmt = $db->prepare("
                SELECT o.*, c.company_name, os.name as status_name,
                       DATE_FORMAT(o.updated_at, '%Y-%m-%d %H:%i') as delivered_at_formatted
                FROM orders o
                LEFT JOIN clients c ON o.client_id = c.id
                LEFT JOIN order_status os ON o.status_id = os.id
                WHERE o.driver_id = ? AND o.status_id = 6
                ORDER BY o.updated_at DESC
                LIMIT 5
            ");
            $recent_deliveries_stmt->execute([$driver_data['id']]);
            $recent_deliveries = $recent_deliveries_stmt->fetchAll();
            
            // Get notifications for driver
            $notifications_stmt = $db->prepare("
                SELECT n.*, 
                       DATE_FORMAT(n.created_at, '%Y-%m-%d %H:%i') as time_formatted
                FROM notifications n
                WHERE n.user_id = ? OR n.user_type = 'driver'
                ORDER BY n.created_at DESC
                LIMIT 8
            ");
            $notifications_stmt->execute([$driver_id]);
            $notifications = $notifications_stmt->fetchAll();
            
            // Get overdue orders
            $overdue_stmt = $db->prepare("
                SELECT COUNT(*) as overdue_count
                FROM orders 
                WHERE driver_id = ? 
                AND status_id IN (3,4,5) 
                AND delivery_time < NOW()
            ");
            $overdue_stmt->execute([$driver_data['id']]);
            $overdue = $overdue_stmt->fetch();
            
            $stats['overdue_orders'] = $overdue['overdue_count'] ?? 0;
        }
        
    } catch (Exception $e) {
        error_log("Driver dashboard query error: " . $e->getMessage());
    }
}

// Default values if no data
if (empty($stats)) {
    $stats = [
        'active_orders' => 0,
        'delivered_today' => 0,
        'pending_orders' => 0,
        'delivered_this_week' => 0,
        'overdue_orders' => 0
    ];
}
?>

<!-- Driver Dashboard Styles -->
<style>
    :root {
        --driver-primary: #007bff;
        --driver-success: #28a745;
        --driver-warning: #ffc107;
        --driver-danger: #dc3545;
        --driver-info: #17a2b8;
        --driver-dark: #343a40;
        --driver-light: #f8f9fa;
        --driver-gray: #6c757d;
        --card-shadow: 0 2px 8px rgba(0,0,0,0.1);
        --hover-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    body {
        background-color: #f5f7fa;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .driver-container {
        margin-left: 250px;
        padding: 20px;
        margin-top: 60px;
        min-height: calc(100vh - 120px);
    }

    @media (max-width: 768px) {
        .driver-container {
            margin-left: 0;
            padding: 15px;
        }
    }

    /* Header Section */
    .driver-header {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        box-shadow: var(--card-shadow);
        border-left: 4px solid var(--driver-primary);
    }

    .driver-header h1 {
        color: var(--driver-dark);
        font-weight: 700;
        margin-bottom: 5px;
    }

    .driver-header .driver-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        margin-left: 10px;
    }

    /* Stat Cards */
    .stat-card-driver {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        transition: all 0.3s ease;
        height: 100%;
        border-top: 4px solid;
        cursor: pointer;
    }

    .stat-card-driver:hover {
        transform: translateY(-5px);
        box-shadow: var(--hover-shadow);
    }

    .stat-card-driver.active-orders {
        border-color: var(--driver-primary);
    }

    .stat-card-driver.delivered-today {
        border-color: var(--driver-success);
    }

    .stat-card-driver.pending-orders {
        border-color: var(--driver-warning);
    }

    .stat-card-driver.truck-info {
        border-color: var(--driver-dark);
    }

    .stat-number-driver {
        font-size: 2rem;
        font-weight: 800;
        color: var(--driver-dark);
        font-family: 'SF Mono', 'Consolas', monospace;
    }

    .stat-label-driver {
        font-size: 0.9rem;
        color: var(--driver-gray);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-icon-driver {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: white;
    }

    .stat-icon-driver.primary {
        background: linear-gradient(135deg, var(--driver-primary), #0056b3);
    }

    .stat-icon-driver.success {
        background: linear-gradient(135deg, var(--driver-success), #1e7e34);
    }

    .stat-icon-driver.warning {
        background: linear-gradient(135deg, var(--driver-warning), #e0a800);
    }

    .stat-icon-driver.dark {
        background: linear-gradient(135deg, var(--driver-dark), #121416);
    }

    /* Active Orders Table */
    .orders-table-container {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        margin-bottom: 25px;
    }

    .section-title-driver {
        color: var(--driver-dark);
        font-weight: 600;
        font-size: 1.1rem;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--driver-primary);
    }

    .order-row {
        display: flex;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid #eee;
        transition: all 0.2s ease;
    }

    .order-row:hover {
        background-color: #f8f9fa;
    }

    .order-id {
        font-weight: 700;
        color: var(--driver-primary);
        min-width: 100px;
    }

    .order-client {
        flex: 1;
        min-width: 200px;
    }

    .order-address {
        flex: 2;
        font-size: 0.9rem;
        color: var(--driver-gray);
    }

    .order-status {
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        margin-right: 15px;
        white-space: nowrap;
    }

    .order-actions {
        min-width: 150px;
        text-align: right;
    }

    /* Action Buttons */
    .driver-btn {
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 0.9rem;
        font-weight: 500;
        border: none;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .driver-btn-primary {
        background: var(--driver-primary);
        color: white;
    }

    .driver-btn-primary:hover {
        background: #0056b3;
        color: white;
        transform: translateY(-1px);
    }

    .driver-btn-success {
        background: var(--driver-success);
        color: white;
    }

    .driver-btn-success:hover {
        background: #218838;
        color: white;
    }

    .driver-btn-outline {
        background: white;
        color: var(--driver-primary);
        border: 1px solid var(--driver-primary);
    }

    .driver-btn-outline:hover {
        background: var(--driver-primary);
        color: white;
    }

    .driver-btn-sm {
        padding: 5px 10px;
        font-size: 0.8rem;
    }

    /* Quick Status Update */
    .status-update-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        margin-bottom: 25px;
        border-left: 4px solid var(--driver-info);
    }

    .current-status {
        display: inline-block;
        padding: 6px 15px;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: 600;
        margin-left: 10px;
    }

    /* Notifications */
    .notifications-container {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        margin-bottom: 25px;
    }

    .notification-item {
        padding: 12px 15px;
        border-bottom: 1px solid #eee;
        transition: all 0.2s ease;
    }

    .notification-item:hover {
        background-color: #f8f9fa;
    }

    .notification-item.unread {
        background-color: #e8f4fd;
        border-left: 3px solid var(--driver-primary);
    }

    .notification-time {
        font-size: 0.8rem;
        color: var(--driver-gray);
    }

    /* Truck Info Card */
    .truck-info-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        border-left: 4px solid var(--driver-dark);
    }

    .truck-status-badge {
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .truck-status-available {
        background-color: #d4edda;
        color: #155724;
    }

    .truck-status-in-use {
        background-color: #d1ecf1;
        color: #0c5460;
    }

    .truck-status-maintenance {
        background-color: #f8d7da;
        color: #721c24;
    }

    /* Alerts */
    .alert-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: var(--driver-danger);
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        font-size: 0.7rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .alert-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        border-left: 4px solid var(--driver-danger);
        margin-bottom: 25px;
    }

    /* Quick Links */
    .quick-links-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 15px;
    }

    .quick-link-item {
        background: white;
        border-radius: 8px;
        padding: 15px;
        text-align: center;
        text-decoration: none;
        color: var(--driver-dark);
        transition: all 0.2s ease;
        border: 1px solid #dee2e6;
    }

    .quick-link-item:hover {
        transform: translateY(-3px);
        box-shadow: var(--card-shadow);
        border-color: var(--driver-primary);
    }

    .quick-link-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin: 0 auto 10px;
        color: white;
    }

    .quick-link-icon.orders {
        background: linear-gradient(135deg, var(--driver-primary), #0056b3);
    }

    .quick-link-icon.truck {
        background: linear-gradient(135deg, var(--driver-dark), #121416);
    }

    .quick-link-icon.status {
        background: linear-gradient(135deg, var(--driver-info), #117a8b);
    }

    .quick-link-icon.notifications {
        background: linear-gradient(135deg, var(--driver-warning), #e0a800);
    }

    .quick-link-icon.report {
        background: linear-gradient(135deg, var(--driver-danger), #bd2130);
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .order-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .order-id, .order-client, .order-address, .order-status, .order-actions {
            width: 100%;
        }
        
        .order-actions {
            text-align: left;
        }
        
        .quick-links-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .stat-number-driver {
            font-size: 1.5rem;
        }
    }
</style>

<!-- Driver Dashboard Content -->
<div class="driver-container">
    <!-- Header -->
    <div class="driver-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h3 mb-2">
                    <i class="fas fa-truck-moving me-2"></i>Driver Dashboard
                </h1>
                <p class="text-muted mb-0">
                    Welcome, <span class="fw-bold"><?php echo htmlspecialchars($driver_data['name'] ?? 'Driver'); ?></span>
                    <span class="current-status" style="background-color: <?php echo $driverStatusColors[$driver_status] ?? '#6c757d'; ?>20; color: <?php echo $driverStatusColors[$driver_status] ?? '#6c757d'; ?>;">
                        <i class="fas fa-circle me-1" style="font-size: 0.7rem;"></i>
                        <?php echo ucfirst(str_replace('_', ' ', $driver_status)); ?>
                    </span>
                </p>
            </div>
            <div class="mt-2 mt-md-0">
                <button class="driver-btn driver-btn-primary" onclick="updateStatus()">
                    <i class="fas fa-sync-alt me-1"></i> Update My Status
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <!-- Active Orders Card -->
        <div class="col-md-3 col-sm-6">
            <a href="/driver/orders.php?status=active" class="text-decoration-none">
                <div class="stat-card-driver active-orders">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number-driver"><?php echo htmlspecialchars($stats['active_orders']); ?></div>
                            <div class="stat-label-driver">Active Orders</div>
                        </div>
                        <div class="stat-icon-driver primary">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                    <?php if ($stats['overdue_orders'] > 0): ?>
                        <div class="mt-3">
                            <span class="badge bg-danger">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <?php echo $stats['overdue_orders']; ?> overdue
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
        </div>

        <!-- Delivered Today Card -->
        <div class="col-md-3 col-sm-6">
            <a href="/driver/orders.php?status=delivered" class="text-decoration-none">
                <div class="stat-card-driver delivered-today">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number-driver"><?php echo htmlspecialchars($stats['delivered_today']); ?></div>
                            <div class="stat-label-driver">Delivered Today</div>
                        </div>
                        <div class="stat-icon-driver success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <?php echo htmlspecialchars($stats['delivered_this_week']); ?> this week
                        </small>
                    </div>
                </div>
            </a>
        </div>

        <!-- Pending Orders Card -->
        <div class="col-md-3 col-sm-6">
            <a href="/driver/orders.php?status=pending" class="text-decoration-none">
                <div class="stat-card-driver pending-orders">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number-driver"><?php echo htmlspecialchars($stats['pending_orders']); ?></div>
                            <div class="stat-label-driver">Pending Orders</div>
                        </div>
                        <div class="stat-icon-driver warning">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="/driver/orders.php?status=pending" class="text-decoration-none small">
                            <i class="fas fa-arrow-right me-1"></i> View pending
                        </a>
                    </div>
                </div>
            </a>
        </div>

        <!-- Truck Info Card -->
        <div class="col-md-3 col-sm-6">
            <a href="/driver/trucks.php?assigned=1" class="text-decoration-none">
                <div class="stat-card-driver truck-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <?php if (!empty($truck_info)): ?>
                                <div class="stat-number-driver" style="font-size: 1.5rem;">
                                    <?php echo htmlspecialchars($truck_info['plate_number'] ?? 'N/A'); ?>
                                </div>
                                <div class="stat-label-driver">Assigned Truck</div>
                            <?php else: ?>
                                <div class="stat-number-driver" style="font-size: 1.5rem;">No Truck</div>
                                <div class="stat-label-driver">Not Assigned</div>
                            <?php endif; ?>
                        </div>
                        <div class="stat-icon-driver dark">
                            <i class="fas fa-truck"></i>
                        </div>
                    </div>
                    <?php if (!empty($truck_info)): ?>
                        <div class="mt-3">
                            <span class="truck-status-badge truck-status-<?php echo $truck_info['status']; ?>">
                                <?php echo ucfirst($truck_info['status']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Left Column: Active Orders and Status -->
        <div class="col-lg-8">
            <!-- Active Orders Table -->
            <div class="orders-table-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="section-title-driver mb-0">
                        <i class="fas fa-list-check me-2"></i>Current Assignments
                    </h5>
                    <a href="/driver/orders.php" class="driver-btn driver-btn-outline driver-btn-sm">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                
                <?php if (!empty($active_orders)): ?>
                    <?php foreach ($active_orders as $order): ?>
                        <div class="order-row">
                            <div class="order-id">
                                #<?php echo htmlspecialchars($order['id']); ?>
                            </div>
                            <div class="order-client">
                                <strong><?php echo htmlspecialchars($order['company_name'] ?? 'No Client'); ?></strong>
                                <div class="small text-muted">
                                    <?php echo htmlspecialchars($order['client_phone'] ?? 'No phone'); ?>
                                </div>
                            </div>
                            <div class="order-address">
                                <div class="small">
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                    <?php echo htmlspecialchars(substr($order['pickup_address'], 0, 30)); ?>...
                                </div>
                                <div class="small">
                                    <i class="fas fa-flag-checkered text-success me-1"></i>
                                    <?php echo htmlspecialchars(substr($order['delivery_address'], 0, 30)); ?>...
                                </div>
                            </div>
                            <span class="order-status" style="background-color: <?php echo $order['status_color'] ?? '#6c757d'; ?>20; color: <?php echo $order['status_color'] ?? '#6c757d'; ?>;">
                                <?php echo ucfirst($order['status_name']); ?>
                            </span>
                            <div class="order-actions">
                                <a href="/driver/orders-view.php?id=<?php echo $order['id']; ?>" class="driver-btn driver-btn-outline driver-btn-sm me-1">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="/driver/update-status.php?id=<?php echo $order['id']; ?>" class="driver-btn driver-btn-primary driver-btn-sm me-1">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="https://maps.google.com/?q=<?php echo urlencode($order['delivery_address']); ?>" target="_blank" class="driver-btn driver-btn-outline driver-btn-sm">
                                    <i class="fas fa-map"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No active assignments</p>
                        <a href="/driver/orders.php" class="driver-btn driver-btn-outline">
                            <i class="fas fa-search me-1"></i> Browse Available Orders
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Status Update Quick Action -->
            <div class="status-update-card">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="section-title-driver mb-2">
                            <i class="fas fa-user-clock me-2"></i>Update Your Status
                        </h5>
                        <p class="text-muted mb-0">
                            Current status: 
                            <span class="fw-bold" style="color: <?php echo $driverStatusColors[$driver_status] ?? '#6c757d'; ?>;">
                                <?php echo ucfirst(str_replace('_', ' ', $driver_status)); ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <button class="driver-btn driver-btn-primary w-100" onclick="showStatusModal()">
                            <i class="fas fa-edit me-1"></i> Change Status
                        </button>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($driverStatusColors as $status => $color): ?>
                                <button class="driver-btn driver-btn-outline" onclick="updateDriverStatus('<?php echo $status; ?>')" style="border-color: <?php echo $color; ?>; color: <?php echo $color; ?>;">
                                    <i class="fas fa-circle me-1" style="color: <?php echo $color; ?>;"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Deliveries -->
            <?php if (!empty($recent_deliveries)): ?>
                <div class="orders-table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title-driver mb-0">
                            <i class="fas fa-history me-2"></i>Recent Deliveries
                        </h5>
                        <a href="/driver/orders.php?status=delivered" class="driver-btn driver-btn-outline driver-btn-sm">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    
                    <?php foreach ($recent_deliveries as $delivery): ?>
                        <div class="order-row">
                            <div class="order-id">
                                #<?php echo htmlspecialchars($delivery['id']); ?>
                            </div>
                            <div class="order-client">
                                <strong><?php echo htmlspecialchars($delivery['company_name']); ?></strong>
                            </div>
                            <div class="order-address">
                                <i class="fas fa-calendar-check text-success me-1"></i>
                                <?php echo htmlspecialchars($delivery['delivered_at_formatted']); ?>
                            </div>
                            <div class="order-actions">
                                <a href="/driver/orders-view.php?id=<?php echo $delivery['id']; ?>" class="driver-btn driver-btn-outline driver-btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Notifications, Truck Info, Quick Links -->
        <div class="col-lg-4">
            <!-- Notifications Panel -->
            <div class="notifications-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="section-title-driver mb-0">
                        <i class="fas fa-bell me-2"></i>Notifications
                        <?php if (!empty($notifications)): ?>
                            <span class="badge bg-primary ms-2"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </h5>
                    <a href="/driver/notifications.php" class="text-decoration-none small">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                
                <?php if (!empty($notifications)): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['is_read'] == 0 ? 'unread' : ''; ?>">
                            <div class="mb-2"><?php echo htmlspecialchars($notification['message']); ?></div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="notification-time">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php echo $notification['time_formatted']; ?>
                                </small>
                                <?php if ($notification['order_id']): ?>
                                    <a href="/driver/orders-view.php?id=<?php echo $notification['order_id']; ?>" class="text-decoration-none small">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-bell-slash fa-2x text-muted mb-3"></i>
                        <p class="text-muted mb-0">No notifications</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Truck Info -->
            <?php if (!empty($truck_info)): ?>
                <div class="truck-info-card mb-4">
                    <h5 class="section-title-driver mb-3">
                        <i class="fas fa-truck me-2"></i>Assigned Truck
                    </h5>
                    
                    <div class="mb-3">
                        <div class="row">
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Plate Number</small>
                                <div class="fw-bold"><?php echo htmlspecialchars($truck_info['plate_number']); ?></div>
                            </div>
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Model</small>
                                <div class="fw-bold"><?php echo htmlspecialchars($truck_info['model'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Capacity</small>
                                <div class="fw-bold"><?php echo htmlspecialchars($truck_info['capacity'] ?? 'N/A'); ?> kg</div>
                            </div>
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Status</small>
                                <span class="truck-status-badge truck-status-<?php echo $truck_info['status']; ?>">
                                    <?php echo ucfirst($truck_info['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="/driver/trucks.php?assigned=1" class="driver-btn driver-btn-outline flex-fill">
                            <i class="fas fa-info-circle me-1"></i> Details
                        </a>
                        <a href="/driver/trucks-report.php?id=<?php echo $truck_info['id']; ?>" class="driver-btn driver-btn-outline flex-fill" style="border-color: var(--driver-danger); color: var(--driver-danger);">
                            <i class="fas fa-exclamation-triangle me-1"></i> Report Issue
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Alerts & Warnings -->
            <?php if ($stats['overdue_orders'] > 0): ?>
                <div class="alert-card">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                        <h6 class="mb-0">Urgent Alerts</h6>
                    </div>
                    <p class="mb-2">
                        <span class="badge bg-danger me-1">!</span>
                        You have <?php echo $stats['overdue_orders']; ?> overdue orders
                    </p>
                    <a href="/driver/orders.php?status=overdue" class="driver-btn driver-btn-outline w-100" style="border-color: var(--driver-danger); color: var(--driver-danger);">
                        <i class="fas fa-clock me-1"></i> View Overdue Orders
                    </a>
                </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="orders-table-container">
                <h5 class="section-title-driver mb-3">
                    <i class="fas fa-bolt me-2"></i>Quick Access
                </h5>
                
                <div class="quick-links-grid">
                    <a href="/driver/orders.php" class="quick-link-item">
                        <div class="quick-link-icon orders">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="small fw-bold">My Orders</div>
                    </a>
                    
                    <a href="/driver/trucks.php?assigned=1" class="quick-link-item">
                        <div class="quick-link-icon truck">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="small fw-bold">My Truck</div>
                    </a>
                    
                    <a href="/driver/update-status.php" class="quick-link-item">
                        <div class="quick-link-icon status">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="small fw-bold">Update Status</div>
                    </a>
                    
                    <a href="/driver/notifications.php" class="quick-link-item">
                        <div class="quick-link-icon notifications">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="small fw-bold">Notifications</div>
                    </a>
                    
                    <a href="/driver/trucks-report.php" class="quick-link-item">
                        <div class="quick-link-icon report">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="small fw-bold">Report Issue</div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Driver Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="statusForm">
                    <div class="mb-3">
                        <label class="form-label">Select Status</label>
                        <select class="form-select" id="statusSelect" required>
                            <option value="">-- Select Status --</option>
                            <?php foreach ($driverStatusColors as $status => $color): ?>
                                <option value="<?php echo $status; ?>" <?php echo $status == $driver_status ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="statusNotes" rows="3" placeholder="Add any notes about your status..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitStatusUpdate()">Update Status</button>
            </div>
        </div>
    </div>
</div>

<script>
// Update driver status
function updateDriverStatus(status) {
    if (confirm('Change your status to: ' + status.replace('_', ' ') + '?')) {
        fetch('/driver/api/update-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                status: status,
                driver_id: <?php echo $driver_data['id'] ?? 0; ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Status updated successfully', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showNotification(data.message || 'Error updating status', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Network error', 'error');
        });
    }
}

// Show status modal
function showStatusModal() {
    const modal = new bootstrap.Modal(document.getElementById('statusModal'));
    modal.show();
}

// Submit status update via modal
function submitStatusUpdate() {
    const status = document.getElementById('statusSelect').value;
    const notes = document.getElementById('statusNotes').value;
    
    if (!status) {
        showNotification('Please select a status', 'error');
        return;
    }
    
    fetch('/driver/api/update-status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            status: status,
            notes: notes,
            driver_id: <?php echo $driver_data['id'] ?? 0; ?>
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Status updated successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message || 'Error updating status', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Network error', 'error');
    });
}

// Notification function
function showNotification(message, type = 'info') {
    // Create toast notification
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : 'danger'} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    
    const container = document.querySelector('.toast-container') || createToastContainer();
    container.appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    toast.addEventListener('hidden.bs.toast', function () {
        toast.remove();
    });
}

function createToastContainer() {
    const container = document.createElement('div');
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    document.body.appendChild(container);
    return container;
}

// Auto-refresh every 60 seconds for real-time updates
setTimeout(() => {
    console.log('Auto-refreshing dashboard...');
    window.location.reload();
}, 60000);

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Animate cards on load
    const cards = document.querySelectorAll('.stat-card-driver, .orders-table-container, .notifications-container');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});

// Map integration for addresses
function openGoogleMaps(address) {
    window.open(`https://maps.google.com/?q=${encodeURIComponent(address)}`, '_blank');
}
</script>

<?php
// Include footer
require_once $base_dir . '/includes/footer.php';
?>