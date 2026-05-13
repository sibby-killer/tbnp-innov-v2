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
$is_admin = ($_SESSION['role_id'] ?? 0) == ROLE_ADMIN;
$is_driver = ($_SESSION['role_id'] ?? 0) == ROLE_DRIVER;
$is_client = ($_SESSION['role_id'] ?? 0) == ROLE_CLIENT;

// Only show admin sidebar for admin users
if (!$is_admin) {
    return;
}
?>

<style>
/* Mobile responsive styles for admin pages */
@media (max-width: 991.98px) {
    .main-container {
        margin-left: 0 !important;
        padding: 15px !important;
        padding-top: 60px !important;
    }
    .sidebar {
        position: fixed !important;
        top: 56px !important;
        left: -250px !important;
        transition: left 0.3s ease !important;
        z-index: 1050 !important;
    }
    .sidebar.show {
        left: 0 !important;
    }
    .sidebar.collapse.show {
        left: 0 !important;
    }
}
@media (min-width: 992px) {
    .main-container {
        margin-left: 250px !important;
    }
}
</style>

<!-- Mobile Toggle Button -->
<button class="btn btn-dark d-lg-none position-fixed" style="top: 70px; left: 10px; z-index: 1040;" type="button" data-bs-toggle="collapse" data-bs-target="#adminSidebar">
    <i class="fas fa-bars"></i>
</button>

<!-- Admin Sidebar -->
<div class="sidebar bg-dark text-white collapse d-lg-block" id="adminSidebar" style="min-width: 250px; position: fixed; top: 56px; left: 0; height: calc(100vh - 56px); overflow-y: auto; z-index: 1030;">
    <div class="sidebar-header p-3 border-bottom">
        <h5 class="mb-0">
            <i class="fas fa-tachometer-alt me-2"></i>Admin Panel
        </h5>
    </div>
    
    <div class="sidebar-menu p-3">
        <ul class="nav flex-column">
            <!-- Dashboard -->
            <li class="nav-item mb-2">
                <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active bg-primary' : ''; ?>">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>
            
            <!-- Couriers Dropdown -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#couriersMenu">
                    <i class="fas fa-building me-2"></i> Couriers
                </a>
                <div class="collapse" id="couriersMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="couriers.php" class="nav-link">View Couriers</a>
                        </li>
                        <li class="nav-item">
                            <a href="couriers-add.php" class="nav-link">Add Courier</a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Trucks Dropdown -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#trucksMenu">
                    <i class="fas fa-truck me-2"></i> Trucks
                </a>
                <div class="collapse" id="trucksMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="trucks.php" class="nav-link">View Trucks</a>
                        </li>
                        <li class="nav-item">
                            <a href="trucks-add.php" class="nav-link">Add Truck</a>
                        </li>
                        <li class="nav-item">
                            <a href="trucks-maintenance.php" class="nav-link">Maintenance</a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Drivers Dropdown -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#driversMenu">
                    <i class="fas fa-users me-2"></i> Drivers
                </a>
                <div class="collapse" id="driversMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="drivers.php" class="nav-link">View Drivers</a>
                        </li>
                        <li class="nav-item">
                            <a href="drivers-add.php" class="nav-link">Add Driver</a>
                        </li>
                        <li class="nav-item">
                            <a href="drivers.php?filter=unassigned" class="nav-link">Unassigned Drivers</a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Clients -->
            <li class="nav-item mb-2">
                <a href="clients.php" class="nav-link <?php echo $current_page == 'clients.php' ? 'active bg-primary' : ''; ?>">
                    <i class="fas fa-user-tie me-2"></i> Clients
                </a>
            </li>
            
            <!-- Orders Dropdown -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#ordersMenu">
                    <i class="fas fa-box me-2"></i> Orders
                </a>
                <div class="collapse" id="ordersMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="orders.php" class="nav-link">All Orders</a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=pending" class="nav-link">Pending Orders</a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=in_transit" class="nav-link">In Transit</a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=delivered" class="nav-link">Delivered</a>
                        </li>
                        <li class="nav-item">
                            <a href="orders.php?status=failed" class="nav-link">Failed</a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Assignments (Optional Group) -->
            <li class="nav-item mb-2">
                <a class="nav-link dropdown-toggle" data-bs-toggle="collapse" href="#assignmentsMenu">
                    <i class="fas fa-tasks me-2"></i> Assignments
                </a>
                <div class="collapse" id="assignmentsMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item">
                            <a href="assign-orders.php" class="nav-link">Assign Orders</a>
                        </li>
                        <li class="nav-item">
                            <a href="assign-trucks.php" class="nav-link">Assign Trucks</a>
                        </li>
                        <li class="nav-item">
                            <a href="assign-drivers.php" class="nav-link">Assign Drivers</a>
                        </li>
                    </ul>
                </div>
            </li>
            
            <!-- Reports -->
            <li class="nav-item mb-2">
                <a href="reports.php" class="nav-link <?php echo $current_page == 'reports.php' ? 'active bg-primary' : ''; ?>">
                    <i class="fas fa-chart-bar me-2"></i> Reports
                </a>
            </li>
            
            <!-- Settings -->
            <li class="nav-item mb-2">
                <a href="settings.php" class="nav-link <?php echo $current_page == 'settings.php' ? 'active bg-primary' : ''; ?>">
                    <i class="fas fa-cog me-2"></i> Settings
                </a>
            </li>
        </ul>
    </div>
    
    <div class="sidebar-footer p-3 border-top">
        <div class="d-grid">
            <a href="../auth/logout.php" class="btn btn-danger btn-sm">
                <i class="fas fa-sign-out-alt me-2"></i> Logout
            </a>
        </div>
    </div>
</div>

<style>
    .sidebar {
        min-height: calc(100vh - 70px);
        position: fixed;
        left: 0;
        top: 70px;
        z-index: 1000;
        overflow-y: auto;
    }
    
    .sidebar-menu .nav-link {
        color: rgba(255,255,255,0.8);
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 5px;
    }
    
    .sidebar-menu .nav-link:hover,
    .sidebar-menu .nav-link.active {
        color: white;
        background-color: rgba(255,255,255,0.1);
    }
    
    .sidebar-menu .nav-link.active {
        background-color: var(--primary-color);
    }
    
    .sidebar-menu .collapse .nav-link {
        padding: 8px 15px;
        font-size: 0.9rem;
    }
    
    .sidebar-footer {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
    }
</style>