<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

// Only show sidebar for logged-in users
if (!isset($_SESSION['user_id'])) {
    return;
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_driver = ($_SESSION['role_id'] ?? 0) == ROLE_DRIVER;

// Only show driver sidebar for driver users
if (!$is_driver) {
    return;
}

// Get driver info for status display
$driver_status = 'available';
$unread_notifications = 0;
$overdue_orders = 0;

// Database connection for dynamic data
if (isset($db)) {
    try {
        // Get driver status
        $driver_stmt = $db->prepare("
            SELECT status FROM drivers WHERE user_id = ?
        ");
        $driver_stmt->execute([$_SESSION['user_id']]);
        $driver_data = $driver_stmt->fetch();
        
        if ($driver_data) {
            $driver_status = $driver_data['status'] ?? 'available';
        }
        
        // Get unread notifications count
        $notification_stmt = $db->prepare("
            SELECT COUNT(*) as count FROM notifications 
            WHERE (user_id = ? OR user_type = 'driver') 
            AND is_read = 0
        ");
        $notification_stmt->execute([$_SESSION['user_id']]);
        $notification_data = $notification_stmt->fetch();
        $unread_notifications = $notification_data['count'] ?? 0;
        
        // Get overdue orders count
        if (isset($driver_data['id'])) {
            $overdue_stmt = $db->prepare("
                SELECT COUNT(*) as count FROM orders 
                WHERE driver_id = ? 
                AND status_id IN (3,4,5) 
                AND delivery_time < NOW()
            ");
            $overdue_stmt->execute([$driver_data['id']]);
            $overdue_data = $overdue_stmt->fetch();
            $overdue_orders = $overdue_data['count'] ?? 0;
        }
        
    } catch (Exception $e) {
        error_log("Sidebar query error: " . $e->getMessage());
    }
}

// Status colors
$status_colors = [
    'available' => '#28a745',
    'on_delivery' => '#007bff',
    'on_break' => '#ffc107',
    'off_duty' => '#6c757d',
    'inactive' => '#dc3545'
];

$status_color = $status_colors[$driver_status] ?? '#6c757d';
?>

<!-- Driver Sidebar -->
<div class="sidebar-scrollable">
    <div class="sidebar-header p-3 border-bottom">
        <div class="d-flex align-items-center">
            <div class="me-3">
                <div class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border-radius: 50%;">
                    <i class="fas fa-user"></i>
                </div>
            </div>
            <div>
                <h6 class="mb-0"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Driver'); ?></h6>
                <small class="text-muted">Driver</small>
            </div>
        </div>
        
        <!-- Current Status Badge -->
        <div class="mt-3">
            <div class="d-flex align-items-center">
                <div class="status-dot me-2" style="background-color: <?php echo $status_color; ?>;"></div>
                <small class="text-muted">Status:</small>
                <span class="ms-1 fw-bold" style="color: <?php echo $status_color; ?>;">
                    <?php echo ucfirst(str_replace('_', ' ', $driver_status)); ?>
                </span>
            </div>
        </div>
    </div>
    
    <div class="sidebar-menu p-3">
        <ul class="nav flex-column">
            <!-- Dashboard -->
            <li class="nav-item mb-2">
                <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </span>
                    <span class="nav-text">Dashboard</span>
                    <?php if ($current_page == 'dashboard.php'): ?>
                        <span class="nav-active-indicator"></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <!-- My Orders Dropdown -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#ordersMenu">
                    <span class="nav-icon">
                        <i class="fas fa-box"></i>
                    </span>
                    <span class="nav-text">My Orders</span>
                    <?php if ($overdue_orders > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $overdue_orders; ?></span>
                    <?php endif; ?>
                    <span class="dropdown-arrow">
                        <i class="fas fa-chevron-down"></i>
                    </span>
                </a>
                <div class="collapse <?php echo strpos($current_page, 'orders') !== false ? 'show' : ''; ?>" id="ordersMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="orders.php?status=active" class="nav-link <?php echo $current_page == 'orders.php' && isset($_GET['status']) && $_GET['status'] == 'active' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-play-circle text-primary"></i>
                                </span>
                                <span class="nav-text">Active Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=pending" class="nav-link <?php echo $current_page == 'orders.php' && isset($_GET['status']) && $_GET['status'] == 'pending' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-clock text-warning"></i>
                                </span>
                                <span class="nav-text">Pending Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=delivered" class="nav-link <?php echo $current_page == 'orders.php' && isset($_GET['status']) && $_GET['status'] == 'delivered' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-check-circle text-success"></i>
                                </span>
                                <span class="nav-text">Delivered Orders</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=overdue" class="nav-link <?php echo $current_page == 'orders.php' && isset($_GET['status']) && $_GET['status'] == 'overdue' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-exclamation-triangle text-danger"></i>
                                </span>
                                <span class="nav-text">Overdue Orders</span>
                                <?php if ($overdue_orders > 0): ?>
                                    <span class="badge bg-danger badge-sm"><?php echo $overdue_orders; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php" class="nav-link <?php echo $current_page == 'orders.php' && !isset($_GET['status']) ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-list"></i>
                                </span>
                                <span class="nav-text">All Orders</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- My Truck -->
            <li class="nav-item mb-2">
                <a href="trucks.php?assigned=1" class="nav-link <?php echo $current_page == 'trucks.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-truck"></i>
                    </span>
                    <span class="nav-text">My Truck</span>
                    <?php if ($current_page == 'trucks.php'): ?>
                        <span class="nav-active-indicator"></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <!-- Update Status -->
            <li class="nav-item mb-2">
                <a href="update-status.php" class="nav-link <?php echo $current_page == 'update-status.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-sync-alt" style="color: <?php echo $status_color; ?>;"></i>
                    </span>
                    <span class="nav-text">Update Status</span>
                    <?php if ($current_page == 'update-status.php'): ?>
                        <span class="nav-active-indicator"></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <!-- Notifications -->
            <li class="nav-item mb-2">
                <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-bell"></i>
                    </span>
                    <span class="nav-text">Notifications</span>
                    <?php if ($unread_notifications > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                    <?php if ($current_page == 'notifications.php'): ?>
                        <span class="nav-active-indicator"></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-item my-3">
                <hr class="sidebar-divider">
            </li>
            
            <!-- Profile / Settings -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#profileMenu">
                    <span class="nav-icon">
                        <i class="fas fa-user"></i>
                    </span>
                    <span class="nav-text">Profile & Settings</span>
                    <span class="dropdown-arrow">
                        <i class="fas fa-chevron-down"></i>
                    </span>
                </a>
                <div class="collapse <?php echo strpos($current_page, 'profile') !== false ? 'show' : ''; ?>" id="profileMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="profile.php" class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-user-circle"></i>
                                </span>
                                <span class="nav-text">My Profile</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="profile.php?tab=edit" class="nav-link <?php echo $current_page == 'profile.php' && isset($_GET['tab']) && $_GET['tab'] == 'edit' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-edit"></i>
                                </span>
                                <span class="nav-text">Edit Profile</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="profile.php?tab=password" class="nav-link <?php echo $current_page == 'profile.php' && isset($_GET['tab']) && $_GET['tab'] == 'password' ? 'active' : ''; ?>">
                                <span class="nav-icon">
                                    <i class="fas fa-key"></i>
                                </span>
                                <span class="nav-text">Change Password</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Support / Help -->
            <li class="nav-item mb-2">
                <a href="support.php" class="nav-link <?php echo $current_page == 'support.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-life-ring"></i>
                    </span>
                    <span class="nav-text">Support & Help</span>
                    <?php if ($current_page == 'support.php'): ?>
                        <span class="nav-active-indicator"></span>
                    <?php endif; ?>
                </a>
            </li>
            
            <!-- Report Issue -->
            <li class="nav-item mb-2">
                <a href="trucks-report.php" class="nav-link <?php echo $current_page == 'trucks-report.php' ? 'active' : ''; ?>">
                    <span class="nav-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </span>
                    <span class="nav-text">Report Issue</span>
                    <?php if ($current_page == 'trucks-report.php'): ?>
                        <span class="nav-active-indicator"></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="sidebar-footer p-3 border-top mt-auto">
        <div class="d-grid">
            <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm d-flex align-items-center justify-content-center">
                <i class="fas fa-sign-out-alt me-2"></i> Logout
            </a>
        </div>
    </div>
</div>

<style>
    /* Sidebar Container */
    .sidebar-scrollable {
        width: 250px;
        background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
        color: #fff;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        display: flex;
        flex-direction: column;
    }

    /* Hide scrollbar for Chrome, Safari and Opera */
    .sidebar-scrollable::-webkit-scrollbar {
        width: 6px;
    }

    .sidebar-scrollable::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.1);
        border-radius: 3px;
    }

    .sidebar-scrollable::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.2);
        border-radius: 3px;
    }

    .sidebar-scrollable::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,0.3);
    }

    /* Sidebar Header */
    .sidebar-header {
        background: rgba(0,0,0,0.2);
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }

    .avatar-circle {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        font-size: 18px;
    }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }

    /* Sidebar Menu */
    .sidebar-menu {
        flex: 1;
    }

    .sidebar-divider {
        border-color: rgba(255,255,255,0.1);
        margin: 1rem 0;
    }

    /* Navigation Links */
    .sidebar-menu .nav-link {
        color: rgba(255,255,255,0.7);
        padding: 12px 15px;
        border-radius: 8px;
        margin-bottom: 5px;
        display: flex;
        align-items: center;
        text-decoration: none;
        transition: all 0.3s ease;
        position: relative;
    }

    .sidebar-menu .nav-link:hover {
        color: #fff;
        background: rgba(255,255,255,0.1);
        transform: translateX(5px);
    }

    .sidebar-menu .nav-link.active {
        color: #fff;
        background: linear-gradient(90deg, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%);
        border-left: 3px solid #3b82f6;
    }

    .nav-icon {
        width: 24px;
        text-align: center;
        margin-right: 12px;
        font-size: 1.1rem;
    }

    .nav-text {
        flex: 1;
        font-size: 0.95rem;
        font-weight: 500;
    }

    .dropdown-arrow {
        font-size: 0.8rem;
        transition: transform 0.3s ease;
    }

    .nav-link[aria-expanded="true"] .dropdown-arrow {
        transform: rotate(180deg);
    }

    /* Active Indicator */
    .nav-active-indicator {
        position: absolute;
        right: 15px;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #3b82f6;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(59, 130, 246, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
        }
    }

    /* Submenu */
    .sidebar-menu .collapse .nav-link {
        padding: 10px 15px;
        font-size: 0.9rem;
        border-left: 2px solid rgba(255,255,255,0.1);
        margin-bottom: 2px;
    }

    .sidebar-menu .collapse .nav-link:hover {
        border-left-color: #3b82f6;
    }

    .sidebar-menu .collapse .nav-link.active {
        color: #fff;
        background: rgba(59, 130, 246, 0.15);
        border-left-color: #3b82f6;
    }

    /* Badges */
    .badge-sm {
        font-size: 0.7rem;
        padding: 2px 6px;
    }

    .badge {
        font-weight: 600;
    }

    /* Footer */
    .sidebar-footer {
        background: rgba(0,0,0,0.2);
        border-top: 1px solid rgba(255,255,255,0.1);
    }

    .btn-outline-danger {
        border-color: rgba(220, 53, 69, 0.5);
        color: rgba(220, 53, 69, 0.8);
    }

    .btn-outline-danger:hover {
        background-color: #dc3545;
        border-color: #dc3545;
        color: white;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .sidebar-scrollable {
            width: 70px;
            transition: width 0.3s ease;
            overflow-x: hidden;
        }
        
        .sidebar-scrollable:hover,
        .sidebar-scrollable.expanded {
            width: 250px;
        }
        
        .sidebar-header h6,
        .sidebar-header small,
        .nav-text,
        .dropdown-arrow,
        .badge {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .sidebar-scrollable:hover .sidebar-header h6,
        .sidebar-scrollable:hover .sidebar-header small,
        .sidebar-scrollable:hover .nav-text,
        .sidebar-scrollable:hover .dropdown-arrow,
        .sidebar-scrollable:hover .badge {
            opacity: 1;
        }
        
        .sidebar-footer .btn span {
            display: none;
        }
        
        .sidebar-scrollable:hover .sidebar-footer .btn span {
            display: inline;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-expand current menu
    const currentMenu = document.querySelector('.nav-link.active').closest('.collapse');
    if (currentMenu) {
        currentMenu.classList.add('show');
    }
    
    // Mobile sidebar toggle
    const sidebar = document.querySelector('.sidebar-scrollable');
    if (window.innerWidth <= 768) {
        // Make sidebar collapsible on mobile
        sidebar.addEventListener('mouseenter', function() {
            this.classList.add('expanded');
        });
        
        sidebar.addEventListener('mouseleave', function() {
            this.classList.remove('expanded');
        });
        
        // Close sidebar when clicking outside
        document.addEventListener('click', function(event) {
            if (!sidebar.contains(event.target) && !event.target.classList.contains('mobile-menu-toggle')) {
                sidebar.classList.remove('expanded');
            }
        });
    }
    
    // Update status indicator color based on current status
    const statusDot = document.querySelector('.status-dot');
    const statusText = document.querySelector('.sidebar-header .fw-bold');
    if (statusDot && statusText) {
        // Color is already set via inline style
    }
    
    // Add click animation to nav links
    const navLinks = document.querySelectorAll('.sidebar-menu .nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!this.classList.contains('dropdown-toggle')) {
                // Remove active class from all links
                navLinks.forEach(l => l.classList.remove('active'));
                // Add active class to clicked link
                this.classList.add('active');
                
                // Mobile: close sidebar after selection
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('expanded');
                }
            }
        });
    });
    
    // Auto-collapse other menus when one opens
    const dropdownToggles = document.querySelectorAll('.nav-link.dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const targetId = this.getAttribute('href');
            const targetMenu = document.querySelector(targetId);
            
            // Close all other menus
            dropdownToggles.forEach(otherToggle => {
                if (otherToggle !== this) {
                    const otherTargetId = otherToggle.getAttribute('href');
                    const otherTargetMenu = document.querySelector(otherTargetId);
                    if (otherTargetMenu.classList.contains('show')) {
                        otherTargetMenu.classList.remove('show');
                    }
                }
            });
        });
    });
});
</script>