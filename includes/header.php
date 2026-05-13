<?php
// DO NOT start session here - it should be started in the calling file

// Define BASE_PATH if not already defined
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__FILE__)));
}

// Database connection (silent fail)
$db = null;
$unreadNotifications = 0;
$notifications = [];
$userData = [];
$criticalAlerts = [];

// Get user data if logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userRoleId = $_SESSION['role_id'] ?? null;
$userRoleName = $_SESSION['role_name'] ?? null;
$userName = $_SESSION['user_name'] ?? 'Guest';
$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if ($isLoggedIn && file_exists(BASE_PATH . '/config/database.php')) {
    try {
        require_once BASE_PATH . '/config/database.php';
        
        if ($db) {
            // Get user profile image
            $userStmt = $db->prepare("SELECT profile_image FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $userData = $userStmt->fetch() ?? [];
            
            // Get unread notifications count
            $notifStmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
            $notifStmt->execute([$userId]);
            $result = $notifStmt->fetch();
            $unreadNotifications = $result['count'] ?? 0;
            
            // Get recent notifications (last 5)
            $recentNotifStmt = $db->prepare("
                SELECT id, title, message, type, is_read, link, 
                       DATE_FORMAT(created_at, '%b %d, %H:%i') as time_ago
                FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $recentNotifStmt->execute([$userId]);
            $notifications = $recentNotifStmt->fetchAll();
            
            // Get role-specific data
            if ($userRoleId == 1) { // Admin
                // Get critical alerts for admin
                $alertStmt = $db->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM couriers c 
                         WHERE (SELECT COUNT(*) FROM trucks t WHERE t.courier_id = c.id) > c.max_trucks) as exceeded_truck_limit,
                        (SELECT COUNT(*) FROM orders WHERE status_id = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)) as stuck_orders,
                        (SELECT COUNT(*) FROM orders WHERE status_id = 8) as failed_deliveries,
                        (SELECT COUNT(*) FROM trucks WHERE status = 'available' AND driver_id IS NULL) as unassigned_trucks
                ");
                $alertStmt->execute();
                $criticalAlerts = $alertStmt->fetch();
            }
            elseif ($userRoleId == 2) { // Driver
                // Get driver's current assignment
                $driverStmt = $db->prepare("
                    SELECT d.status as driver_status, t.plate_number,
                           o.tracking_number, os.name as order_status
                    FROM drivers d
                    LEFT JOIN trucks t ON d.id = t.driver_id
                    LEFT JOIN orders o ON d.id = o.driver_id AND o.status_id IN (4,5,6)
                    LEFT JOIN order_status os ON o.status_id = os.id
                    WHERE d.user_id = ?
                    ORDER BY o.created_at DESC 
                    LIMIT 1
                ");
                $driverStmt->execute([$userId]);
                $driverData = $driverStmt->fetch();
                $_SESSION['driver_data'] = $driverData;
            }
            elseif ($userRoleId == 3) { // Client
                // Get client's recent orders
                $clientStmt = $db->prepare("
                    SELECT o.tracking_number, os.name as status, os.color
                    FROM orders o
                    INNER JOIN clients c ON o.client_id = c.id
                    LEFT JOIN order_status os ON o.status_id = os.id
                    WHERE c.user_id = ?
                    ORDER BY o.created_at DESC 
                    LIMIT 3
                ");
                $clientStmt->execute([$userId]);
                $clientOrders = $clientStmt->fetchAll();
                $_SESSION['client_orders'] = $clientOrders;
            }
        }
    } catch (Exception $e) {
        error_log("Header initialization error: " . $e->getMessage());
    }
}

// Generate initials for avatar
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper($word[0]);
        }
    }
    return substr($initials, 0, 2);
}

// Get page title
$pageTitle = isset($pageTitle) ? $pageTitle : 'Dashboard';
$fullTitle = '';
switch($userRoleId) {
    case 1: $fullTitle = "Admin $pageTitle - Courier Management System"; break;
    case 2: $fullTitle = "Driver $pageTitle - Courier Management System"; break;
    case 3: $fullTitle = "Client $pageTitle - Courier Management System"; break;
    default: $fullTitle = "$pageTitle - Courier Management System";
}

// Breadcrumb data
$breadcrumbs = $_SESSION['breadcrumbs'] ?? [];
if (empty($breadcrumbs)) {
    $breadcrumbs = [
        ['name' => 'Dashboard', 'url' => ($userRoleId == 1 ? '../admin/dashboard.php' : ($userRoleId == 2 ? '../driver/dashboard.php' : '../client/dashboard.php'))]
    ];
}

// Get notification badge color based on type
function getNotificationBadgeClass($count) {
    if ($count == 0) return '';
    if ($count <= 3) return 'bg-success';
    if ($count <= 10) return 'bg-warning';
    return 'bg-danger';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($fullTitle); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-color: #dee2e6;
        }
        
        [data-bs-theme="dark"] {
            --primary-color: #1a252f;
            --secondary-color: #2c3e50;
            --light-color: #212529;
            --dark-color: #f8f9fa;
            --border-color: #495057;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
            padding-top: 120px;
            transition: all 0.3s ease;
        }
        
        .navbar {
            background-color: var(--primary-color);
            border-bottom: 3px solid var(--secondary-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 0.5rem 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-brand .logo-icon {
            font-size: 1.8rem;
            color: var(--secondary-color);
        }
        
        .breadcrumb {
            background: transparent;
            padding: 0.5rem 0;
            margin-bottom: 0;
            font-size: 0.85rem;
        }
        
        .breadcrumb-item a {
            color: var(--secondary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item.active {
            color: var(--dark-color);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 0.7rem;
            padding: 3px 7px;
            min-width: 20px;
            text-align: center;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border: 2px solid rgba(255,255,255,0.3);
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .role-badge {
            font-size: 0.7rem;
            padding: 3px 10px;
            border-radius: 15px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }
        
        .driver-badge {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: #2c3e50;
        }
        
        .client-badge {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
        }
        
        .quick-action-btn {
            border-radius: 20px;
            padding: 6px 15px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .notification-dropdown {
            max-width: 400px;
            min-width: 320px;
        }
        
        .notification-item {
            border-left: 3px solid transparent;
            padding: 12px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .notification-item.unread {
            border-left-color: var(--secondary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .notification-item:hover {
            background-color: rgba(0,0,0,0.05);
        }
        
        .notification-type {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
        }
        
        .notification-info { background-color: #e3f2fd; color: #1976d2; }
        .notification-success { background-color: #e8f5e9; color: #388e3c; }
        .notification-warning { background-color: #fff3e0; color: #f57c00; }
        .notification-error { background-color: #ffebee; color: #d32f2f; }
        .notification-order { background-color: #e8eaf6; color: #3f51b5; }
        
        .theme-toggle {
            background: transparent;
            border: none;
            color: var(--dark-color);
            font-size: 1.2rem;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.2s;
        }
        
        .theme-toggle:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .search-box {
            max-width: 300px;
        }
        
        .search-box input {
            background-color: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
        }
        
        .search-box input::placeholder {
            color: rgba(255,255,255,0.6);
        }
        
        .header-alert {
            position: fixed;
            top: 70px;
            left: 0;
            right: 0;
            z-index: 1035;
            border-radius: 0;
            padding: 8px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-100%); }
            to { transform: translateY(0); }
        }
        
        .assignment-preview {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 0.85rem;
            max-width: 250px;
        }
        
        .mobile-quick-actions {
            display: none;
        }
        
        @media (max-width: 992px) {
            body {
                padding-top: 140px;
            }
            
            .search-box {
                max-width: 100%;
                margin: 10px 0;
            }
            
            .mobile-quick-actions {
                display: block;
            }
            
            .desktop-quick-actions {
                display: none;
            }
            
            .assignment-preview {
                max-width: 200px;
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 768px) {
            .notification-dropdown {
                min-width: 280px;
                max-width: 90vw;
            }
            
            .assignment-preview {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Critical Alerts (Admin Only) -->
    <?php if ($userRoleId == 1 && !empty($criticalAlerts)): ?>
    <?php 
    $totalCritical = ($criticalAlerts['exceeded_truck_limit'] ?? 0) + 
                    ($criticalAlerts['stuck_orders'] ?? 0) + 
                    ($criticalAlerts['failed_deliveries'] ?? 0) + 
                    ($criticalAlerts['unassigned_trucks'] ?? 0);
    if ($totalCritical > 0): ?>
    <div class="header-alert alert alert-warning">
        <div>
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong><?php echo $totalCritical; ?> critical issue(s) require attention</strong>
        </div>
        <a href="../admin/dashboard.php#alerts" class="btn btn-sm btn-outline-warning">View Details</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    
    <!-- Main Navigation Header -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container-fluid">
            <!-- System Branding -->
            <a class="navbar-brand" href="<?php echo $isLoggedIn ? 
                ($userRoleId == 1 ? '../admin/dashboard.php' : 
                 ($userRoleId == 2 ? '../driver/dashboard.php' : 
                  '../client/dashboard.php')) : '../index.php'; ?>">
                <i class="fas fa-truck-moving logo-icon"></i>
                <div>
                    <div>Courier Management</div>
                    <small style="font-size: 0.75rem; opacity: 0.8;">Multiple Couriers System</small>
                </div>
            </a>
            
            <!-- Breadcrumb for Desktop -->
            <div class="d-none d-lg-block">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <li class="breadcrumb-item <?php echo ($index == count($breadcrumbs) - 1) ? 'active' : ''; ?>">
                            <?php if ($index == count($breadcrumbs) - 1): ?>
                            <?php echo htmlspecialchars($crumb['name']); ?>
                            <?php else: ?>
                            <a href="<?php echo $crumb['url']; ?>"><?php echo htmlspecialchars($crumb['name']); ?></a>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            </div>
            
            <!-- Toggle button for mobile -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarContent">
                <!-- Search Box (Visible on Desktop) -->
                <?php if ($isLoggedIn): ?>
                <div class="search-box me-3 d-none d-lg-block">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" placeholder="Search..." id="globalSearch">
                        <button class="btn btn-outline-light" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <ul class="navbar-nav ms-auto align-items-center">
                    <!-- Theme Toggle -->
                    <li class="nav-item me-2">
                        <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">
                            <i class="fas fa-moon"></i>
                        </button>
                    </li>
                    
                    <?php if ($isLoggedIn): ?>
                    <!-- Driver Assignment Preview -->
                    <?php if ($userRoleId == 2 && isset($_SESSION['driver_data']) && $_SESSION['driver_data']): ?>
                    <li class="nav-item me-3 d-none d-lg-block">
                        <div class="assignment-preview text-white">
                            <i class="fas fa-truck me-1"></i>
                            <span class="fw-bold"><?php echo $_SESSION['driver_data']['plate_number'] ?? 'No Truck'; ?></span>
                            <?php if ($_SESSION['driver_data']['tracking_number']): ?>
                            <span class="mx-2">•</span>
                            <i class="fas fa-box me-1"></i>
                            <span><?php echo $_SESSION['driver_data']['tracking_number']; ?></span>
                            <span class="badge bg-light text-dark ms-1"><?php echo $_SESSION['driver_data']['order_status']; ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Mobile Quick Actions -->
                    <div class="mobile-quick-actions d-block d-lg-none mt-2 mb-2">
                        <?php if ($userRoleId == 1): ?>
                        <!-- Admin Mobile Quick Actions -->
                        <div class="d-flex flex-wrap gap-2">
                            <a href="../admin/orders.php?action=create" class="quick-action-btn btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Order
                            </a>
                            <a href="../admin/couriers-add.php" class="quick-action-btn btn btn-success btn-sm">
                                <i class="fas fa-plus"></i> Courier
                            </a>
                            <a href="../admin/reports.php" class="quick-action-btn btn btn-info btn-sm">
                                <i class="fas fa-chart-bar"></i> Reports
                            </a>
                        </div>
                        <?php elseif ($userRoleId == 2): ?>
                        <!-- Driver Mobile Quick Actions -->
                        <div class="d-flex flex-wrap gap-2">
                            <a href="../driver/update-status.php" class="quick-action-btn btn btn-primary btn-sm">
                                <i class="fas fa-sync-alt"></i> Update Status
                            </a>
                            <a href="../driver/my-orders.php" class="quick-action-btn btn btn-success btn-sm">
                                <i class="fas fa-box"></i> My Orders
                            </a>
                        </div>
                        <?php elseif ($userRoleId == 3): ?>
                        <!-- Client Mobile Quick Actions -->
                        <div class="d-flex flex-wrap gap-2">
                            <a href="../client/create-order.php" class="quick-action-btn btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Create Order
                            </a>
                            <a href="../client/track-order.php" class="quick-action-btn btn btn-info btn-sm">
                                <i class="fas fa-search"></i> Track Order
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Desktop Quick Actions -->
                    <div class="desktop-quick-actions">
                        <?php if ($userRoleId == 1): ?>
                        <!-- Admin Quick Actions -->
                        <li class="nav-item dropdown me-2 d-none d-lg-block">
                            <a class="btn btn-sm btn-outline-light quick-action-btn dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-bolt"></i> Quick Actions
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header">Add New</h6></li>
                                <li><a class="dropdown-item" href="../admin/orders.php?action=create"><i class="fas fa-box me-2"></i> New Order</a></li>
                                <li><a class="dropdown-item" href="../admin/couriers-add.php"><i class="fas fa-building me-2"></i> New Courier</a></li>
                                <li><a class="dropdown-item" href="../admin/trucks-add.php"><i class="fas fa-truck me-2"></i> New Truck</a></li>
                                <li><a class="dropdown-item" href="../admin/drivers-add.php"><i class="fas fa-user-plus me-2"></i> New Driver</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Quick Links</h6></li>
                                <li><a class="dropdown-item" href="../admin/reports.php"><i class="fas fa-chart-bar me-2"></i> Reports</a></li>
                                <li><a class="dropdown-item" href="../admin/orders.php?status=pending"><i class="fas fa-clock me-2"></i> Pending Orders</a></li>
                                <li><a class="dropdown-item" href="../admin/trucks.php?status=available"><i class="fas fa-truck-moving me-2"></i> Available Trucks</a></li>
                                <li><a class="dropdown-item" href="../admin/drivers.php?status=available"><i class="fas fa-users me-2"></i> Available Drivers</a></li>
                            </ul>
                        </li>
                        <?php elseif ($userRoleId == 2): ?>
                        <!-- Driver Quick Actions -->
                        <li class="nav-item me-2 d-none d-lg-block">
                            <a href="../driver/update-status.php" class="btn btn-sm btn-primary quick-action-btn">
                                <i class="fas fa-sync-alt me-1"></i> Update Status
                            </a>
                        </li>
                        <?php elseif ($userRoleId == 3): ?>
                        <!-- Client Quick Actions -->
                        <li class="nav-item dropdown me-2 d-none d-lg-block">
                            <a class="btn btn-sm btn-primary quick-action-btn dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-bolt"></i> Quick Actions
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="../client/create-order.php"><i class="fas fa-plus me-2"></i> Create Order</a></li>
                                <li><a class="dropdown-item" href="../client/track-order.php"><i class="fas fa-search me-2"></i> Track Order</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../client/my-orders.php"><i class="fas fa-list me-2"></i> My Orders</a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#supportModal"><i class="fas fa-question-circle me-2"></i> Support</a></li>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Notifications -->
                    <li class="nav-item dropdown me-3">
                        <a class="nav-link position-relative" href="#" role="button" data-bs-toggle="dropdown" id="notificationDropdown">
                            <i class="fas fa-bell fa-lg"></i>
                            <?php if ($unreadNotifications > 0): ?>
                            <span class="notification-badge badge <?php echo getNotificationBadgeClass($unreadNotifications); ?> rounded-pill">
                                <?php echo $unreadNotifications; ?>
                            </span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown p-0">
                            <div class="dropdown-header bg-light py-3 px-4 border-bottom">
                                <h6 class="mb-0">Notifications</h6>
                                <small class="text-muted"><?php echo $unreadNotifications; ?> unread</small>
                            </div>
                            <div class="notification-list" style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($notifications)): ?>
                                <?php foreach ($notifications as $notification): ?>
                                <a href="<?php echo $notification['link'] ?: '#'; ?>" 
                                   class="dropdown-item notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" 
                                   data-notification-id="<?php echo $notification['id']; ?>">
                                    <div class="d-flex">
                                        <div class="notification-type notification-<?php echo $notification['type']; ?>">
                                            <i class="fas fa-<?php echo $notification['type'] == 'success' ? 'check-circle' : 
                                                                  ($notification['type'] == 'warning' ? 'exclamation-triangle' : 
                                                                  ($notification['type'] == 'error' ? 'times-circle' : 
                                                                  ($notification['type'] == 'order' ? 'box' : 'info-circle'))); ?> fa-sm"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-bold"><?php echo htmlspecialchars($notification['title']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars(substr($notification['message'], 0, 50)); ?>...</div>
                                            <div class="notification-time small text-muted"><?php echo $notification['time_ago']; ?></div>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-bell-slash fa-2x mb-2"></i>
                                    <p class="mb-0">No notifications</p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-footer text-center py-2 border-top">
                                <a href="<?php echo $userRoleId == 1 ? '../admin/notifications.php' : 
                                           ($userRoleId == 2 ? '../driver/dashboard.php?tab=notifications' : 
                                           '../client/dashboard.php?tab=notifications'); ?>" class="text-decoration-none small">
                                    View All Notifications
                                </a>
                            </div>
                        </div>
                    </li>
                    
                    <!-- User Profile -->
                    <li class="nav-item dropdown">
                        <a class="nav-link d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="user-avatar me-2">
                                <?php if (!empty($userData['profile_image'])): ?>
                                <img src="<?php echo htmlspecialchars($userData['profile_image']); ?>" alt="<?php echo htmlspecialchars($userName); ?>">
                                <?php else: ?>
                                <?php echo getInitials($userName); ?>
                                <?php endif; ?>
                            </div>
                            <div class="d-none d-md-block">
                                <div class="d-flex align-items-center">
                                    <span class="me-2"><?php echo htmlspecialchars($userName); ?></span>
                                    <?php if ($userRoleName): ?>
                                    <span class="role-badge <?php echo $userRoleName; ?>-badge" 
                                          data-bs-toggle="tooltip" 
                                          title="<?php echo ucfirst($userRoleName); ?> - <?php 
                                          echo $userRoleId == 1 ? 'Full Access Administrator' : 
                                                ($userRoleId == 2 ? 'Delivery Driver' : 
                                                'Client Account'); ?>">
                                        <i class="fas fa-<?php echo $userRoleId == 1 ? 'shield-alt' : 
                                                              ($userRoleId == 2 ? 'steering-wheel' : 'user'); ?>"></i>
                                        <?php echo ucfirst($userRoleName); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><?php echo htmlspecialchars($userEmail); ?></small>
                            </div>
                            <i class="fas fa-chevron-down ms-2"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <h6 class="dropdown-header">Account</h6>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php 
                                    echo $userRoleId == 1 ? '../admin/profile.php' :
                                          ($userRoleId == 2 ? '../driver/profile.php' : 
                                          '../client/profile.php');
                                ?>">
                                    <i class="fas fa-user me-2"></i> My Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php 
                                    echo $userRoleId == 1 ? '../admin/profile.php?tab=password' :
                                          ($userRoleId == 2 ? '../driver/profile.php?tab=password' : 
                                          '../client/profile.php?tab=password');
                                ?>">
                                    <i class="fas fa-key me-2"></i> Change Password
                                </a>
                            </li>
                            <?php if ($userRoleId == 1): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="../admin/settings.php">
                                    <i class="fas fa-cog me-2"></i> System Settings
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="../admin/activity-logs.php">
                                    <i class="fas fa-history me-2"></i> Activity Logs
                                </a>
                            </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="../auth/logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                    <?php else: ?>
                    <!-- Login/Register for non-logged in users -->
                    <li class="nav-item">
                        <a class="nav-link" href="../auth/login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../auth/register.php">Register</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Mobile Search Box -->
    <?php if ($isLoggedIn): ?>
    <div class="container-fluid d-lg-none mt-2">
        <div class="search-box">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Search orders, clients, drivers..." id="mobileSearch">
                <button class="btn btn-outline-secondary" type="button">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Support Modal (Client) -->
    <div class="modal fade" id="supportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Support & Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Need help with your orders?</p>
                    <ul>
                        <li>Email: support@courier-system.com</li>
                        <li>Phone: (555) 123-4567</li>
                        <li>Hours: Mon-Fri, 9AM-6PM</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="mailto:support@courier-system.com" class="btn btn-primary">Send Email</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Theme Toggle
        const themeToggle = document.getElementById('themeToggle');
        const currentTheme = localStorage.getItem('theme') || 'light';
        
        if (currentTheme === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            if (isDark) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                localStorage.setItem('theme', 'dark');
            }
        });
        
        // Mark notification as read
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    const notificationId = this.dataset.notificationId;
                    if (notificationId && this.classList.contains('unread')) {
                        // AJAX call to mark as read
                        fetch('../includes/mark-notification-read.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ notification_id: notificationId })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                this.classList.remove('unread');
                                // Update badge count
                                const badge = document.querySelector('.notification-badge');
                                if (badge) {
                                    const currentCount = parseInt(badge.textContent);
                                    if (currentCount > 1) {
                                        badge.textContent = currentCount - 1;
                                        // Update badge color
                                        badge.className = 'notification-badge badge ' + 
                                            getBadgeClass(currentCount - 1) + ' rounded-pill';
                                    } else {
                                        badge.remove();
                                    }
                                }
                            }
                        });
                    }
                });
            });
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Auto-refresh notifications every 30 seconds
            setInterval(refreshNotifications, 30000);
            
            // Global search
            const globalSearch = document.getElementById('globalSearch');
            const mobileSearch = document.getElementById('mobileSearch');
            
            if (globalSearch) {
                globalSearch.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch(this.value);
                    }
                });
            }
            
            if (mobileSearch) {
                mobileSearch.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performSearch(this.value);
                    }
                });
            }
        });
        
        function getBadgeClass(count) {
            if (count === 0) return '';
            if (count <= 3) return 'bg-success';
            if (count <= 10) return 'bg-warning';
            return 'bg-danger';
        }
        
        function refreshNotifications() {
            fetch('../includes/get-notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.unread_count !== undefined) {
                        const badge = document.querySelector('.notification-badge');
                        const unreadText = document.querySelector('.dropdown-header .text-muted');
                        
                        if (data.unread_count > 0) {
                            if (!badge) {
                                // Create new badge
                                const icon = document.querySelector('#notificationDropdown i');
                                const newBadge = document.createElement('span');
                                newBadge.className = 'notification-badge badge ' + getBadgeClass(data.unread_count) + ' rounded-pill';
                                newBadge.textContent = data.unread_count;
                                icon.parentNode.appendChild(newBadge);
                            } else {
                                badge.textContent = data.unread_count;
                                badge.className = 'notification-badge badge ' + getBadgeClass(data.unread_count) + ' rounded-pill';
                            }
                            if (unreadText) {
                                unreadText.textContent = data.unread_count + ' unread';
                            }
                        } else if (badge) {
                            badge.remove();
                            if (unreadText) {
                                unreadText.textContent = '0 unread';
                            }
                        }
                    }
                })
                .catch(error => console.error('Error refreshing notifications:', error));
        }
        
        function performSearch(query) {
            if (!query.trim()) return;
            
            // Redirect to search results page based on role
            const roleId = <?php echo $userRoleId ?? 0; ?>;
            let searchUrl = '';
            
            switch(roleId) {
                case 1: // Admin
                    searchUrl = '../admin/search.php?q=' + encodeURIComponent(query);
                    break;
                case 2: // Driver
                    searchUrl = '../driver/search.php?q=' + encodeURIComponent(query);
                    break;
                case 3: // Client
                    searchUrl = '../client/search.php?q=' + encodeURIComponent(query);
                    break;
                default:
                    searchUrl = '../index.php?search=' + encodeURIComponent(query);
            }
            
            window.location.href = searchUrl;
        }
        
        // Auto-hide header alerts after 10 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.header-alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 10000);
    </script>
</body>
</html>