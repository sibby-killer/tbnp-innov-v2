<?php
// drivers.php
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

$pageTitle = "Manage Drivers";

// Initialize variables
$drivers = [];
$unregistered_drivers = []; // Users with role_id = 2 but not in drivers table
$couriers = [];
$search = $_GET['search'] ?? '';

// Get all couriers for display
try {
    $courier_stmt = $db->prepare("SELECT id, name FROM couriers WHERE status = 'active' ORDER BY name");
    $courier_stmt->execute();
    $couriers = $courier_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Courier query error: " . $e->getMessage());
}

// Get registered drivers (users in drivers table)
try {
    $query = "SELECT d.*, u.name as driver_name, u.email, u.phone, u.status as user_status 
              FROM drivers d 
              INNER JOIN users u ON d.user_id = u.id 
              WHERE u.role_id = ?";
    
    if (!empty($search)) {
        $query .= " AND (u.name LIKE ? OR u.email LIKE ? OR d.license_number LIKE ?)";
        $stmt = $db->prepare($query);
        $search_term = "%$search%";
        $stmt->execute([ROLE_DRIVER, $search_term, $search_term, $search_term]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute([ROLE_DRIVER]);
    }
    
    $registered_drivers = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Registered drivers query error: " . $e->getMessage());
    $registered_drivers = [];
}

// Get unregistered drivers (users with role_id = 2 but not in drivers table)
try {
    $query = "SELECT u.id, u.name, u.email, u.phone, u.status as user_status, u.created_at
              FROM users u 
              LEFT JOIN drivers d ON u.id = d.user_id 
              WHERE u.role_id = ? AND d.id IS NULL";
    
    if (!empty($search)) {
        $query .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $stmt = $db->prepare($query);
        $search_term = "%$search%";
        $stmt->execute([ROLE_DRIVER, $search_term, $search_term]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute([ROLE_DRIVER]);
    }
    
    $unregistered_drivers = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Unregistered drivers query error: " . $e->getMessage());
    $unregistered_drivers = [];
}

// Combine both arrays
$all_users_with_role_2 = array_merge($registered_drivers, $unregistered_drivers);

// Get courier names for each driver
$courier_names = [];
if (!empty($couriers)) {
    foreach ($couriers as $courier) {
        $courier_names[$courier['id']] = $courier['name'];
    }
}

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $id = $_GET['id'] ?? 0;
    
    switch ($action) {
        case 'register':
            // Add user to drivers table
            if ($id > 0) {
                try {
                    // Check if user already exists in drivers table
                    $check_stmt = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
                    $check_stmt->execute([$id]);
                    $exists = $check_stmt->fetch();
                    
                    if (!$exists) {
                        // Get user info for creating license number
                        $user_stmt = $db->prepare("SELECT name, phone FROM users WHERE id = ?");
                        $user_stmt->execute([$id]);
                        $user = $user_stmt->fetch();
                        
                        // Check if we have at least one active courier
                        $courier_stmt = $db->prepare("SELECT id, name FROM couriers WHERE status = 'active' LIMIT 1");
                        $courier_stmt->execute();
                        $courier = $courier_stmt->fetch();
                        
                        if (!$courier) {
                            // Create a default courier if none exists
                            $create_courier = $db->prepare("
                                INSERT INTO couriers (name, status, address, phone, email, created_at) 
                                VALUES ('Default Courier Company', 'active', 'Default Address', '0000000000', 'default@courier.com', NOW())
                            ");
                            $create_courier->execute();
                            $courier_id = $db->lastInsertId();
                            $courier_name = 'Default Courier Company';
                        } else {
                            $courier_id = $courier['id'];
                            $courier_name = $courier['name'];
                        }
                        
                        // Create a temporary license number using user info
                        $user_name_part = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user['name']), 0, 3));
                        $temp_license = "TEMP-" . $user_name_part . "-" . date('md') . "-" . substr($user['phone'], -4);
                        
                        // Insert with ALL required fields for drivers table
                        $insert_stmt = $db->prepare("
                            INSERT INTO drivers (
                                user_id, 
                                courier_id, 
                                license_number, 
                                license_type,
                                status, 
                                created_at, 
                                updated_at,
                                experience_years,
                                rating,
                                total_deliveries
                            ) 
                            VALUES (?, ?, ?, 'B', 'available', NOW(), NOW(), 0, 0.00, 0)
                        ");
                        
                        $insert_stmt->execute([$id, $courier_id, $temp_license]);
                        
                        // Log the registration
                        $log_stmt = $db->prepare("
                            INSERT INTO activity_logs (user_id, user_type, action, details, ip_address) 
                            VALUES (?, 'admin', 'driver_register', ?, ?)
                        ");
                        $log_stmt->execute([
                            $_SESSION['user_id'],
                            "Registered driver: {$user['name']} with temp license: {$temp_license} to courier: {$courier_name}",
                            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
                        ]);
                        
                        $_SESSION['success_message'] = "Driver registered successfully! Temporary license created: " . $temp_license;
                    } else {
                        $_SESSION['error_message'] = "Driver is already registered.";
                    }
                } catch (Exception $e) {
                    error_log("Driver registration error - User ID {$id}: " . $e->getMessage());
                    
                    // Provide more specific error message
                    if (strpos($e->getMessage(), 'courier_id') !== false) {
                        $_SESSION['error_message'] = "Cannot register driver. Please ensure there is at least one active courier in the system.";
                    } elseif (strpos($e->getMessage(), 'license_number') !== false) {
                        $_SESSION['error_message'] = "Cannot register driver. License number generation failed.";
                    } else {
                        $_SESSION['error_message'] = "Error registering driver: " . $e->getMessage();
                    }
                }
            }
            header('Location: drivers.php');
            exit();
            
        case 'activate':
        case 'deactivate':
            $new_status = ($action == 'activate') ? 'active' : 'inactive';
            try {
                $update_stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
                $update_stmt->execute([$new_status, $id]);
                
                // Also update driver status if exists
                $driver_update = $db->prepare("UPDATE drivers SET status = 'inactive' WHERE user_id = ?");
                $driver_update->execute([$id]);
                
                $_SESSION['success_message'] = "User status updated successfully!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Error updating user status: " . $e->getMessage();
            }
            header('Location: drivers.php');
            exit();
            
        case 'delete':
            if ($id > 0) {
                try {
                    // Check if driver is in drivers table
                    $check_stmt = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
                    $check_stmt->execute([$id]);
                    $driver_exists = $check_stmt->fetch();

                    if ($driver_exists) {
                        // Delete from drivers table first (due to foreign key constraints)
                        $delete_driver_stmt = $db->prepare("DELETE FROM drivers WHERE user_id = ?");
                        $delete_driver_stmt->execute([$id]);
                    }

                    // Delete from users table
                    $delete_user_stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role_id = ?");
                    $delete_user_stmt->execute([$id, ROLE_DRIVER]);

                    $_SESSION['success_message'] = "Driver deleted successfully!";
                } catch (Exception $e) {
                    $_SESSION['error_message'] = "Error deleting driver: " . $e->getMessage();
                }
            }
            header('Location: drivers.php');
            exit();

        case 'approve':
            // Approve pending driver - set status to active and register in drivers table
            if ($id > 0) {
                try {
                    // Get user info
                    $user_stmt = $db->prepare("SELECT name, phone FROM users WHERE id = ?");
                    $user_stmt->execute([$id]);
                    $user = $user_stmt->fetch();

                    if ($user) {
                        // Update user status to active
                        $update_user = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                        $update_user->execute([$id]);

                        // Check if driver record exists
                        $check_driver = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
                        $check_driver->execute([$id]);
                        $driver_exists = $check_driver->fetch();

                        if (!$driver_exists) {
                            // Get active courier
                            $courier_stmt = $db->prepare("SELECT id, name FROM couriers WHERE status = 'active' LIMIT 1");
                            $courier_stmt->execute();
                            $courier = $courier_stmt->fetch();

                            if (!$courier) {
                                // Create default courier if none exists
                                $create_courier = $db->prepare("
                                    INSERT INTO couriers (name, status, address, phone, email, created_at)
                                    VALUES ('Default Courier Company', 'active', 'Default Address', '0000000000', 'default@courier.com', NOW())
                                ");
                                $create_courier->execute();
                                $courier_id = $db->lastInsertId();
                            } else {
                                $courier_id = $courier['id'];
                            }

                            // Create driver record with temp license
                            $user_name_part = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user['name']), 0, 3));
                            $temp_license = "TEMP-" . $user_name_part . "-" . date('md') . "-" . substr($user['phone'], -4);

                            $insert_driver = $db->prepare("
                                INSERT INTO drivers (user_id, courier_id, license_number, license_type, status, created_at, updated_at, experience_years, rating, total_deliveries)
                                VALUES (?, ?, ?, 'B', 'available', NOW(), NOW(), 0, 0.00, 0)
                            ");
                            $insert_driver->execute([$id, $courier_id, $temp_license]);
                        } else {
                            // Update existing driver record to available
                            $update_driver = $db->prepare("UPDATE drivers SET status = 'available', license_number = CONCAT('APP-', license_number) WHERE user_id = ?");
                            $update_driver->execute([$id]);
                        }

                        // Log the approval
                        $log_stmt = $db->prepare("
                            INSERT INTO activity_logs (user_id, user_type, action, details, ip_address)
                            VALUES (?, 'admin', 'driver_approved', ?, ?)
                        ");
                        $log_stmt->execute([
                            $_SESSION['user_id'],
                            "Approved driver: {$user['name']} (ID: {$id})",
                            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
                        ]);

                        $_SESSION['success_message'] = "Driver approved successfully! They can now login.";
                    } else {
                        $_SESSION['error_message'] = "User not found.";
                    }
                } catch (Exception $e) {
                    error_log("Driver approval error: " . $e->getMessage());
                    $_SESSION['error_message'] = "Error approving driver: " . $e->getMessage();
                }
            }
            header('Location: drivers.php');
            exit();

        case 'reject':
            // Reject and delete pending driver
            if ($id > 0) {
                try {
                    // Get user info for logging
                    $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
                    $user_stmt->execute([$id]);
                    $user = $user_stmt->fetch();

                    // Delete driver record if exists
                    $delete_driver = $db->prepare("DELETE FROM drivers WHERE user_id = ?");
                    $delete_driver->execute([$id]);

                    // Delete user
                    $delete_user = $db->prepare("DELETE FROM users WHERE id = ? AND role_id = ?");
                    $delete_user->execute([$id, ROLE_DRIVER]);

                    // Log the rejection
                    $log_stmt = $db->prepare("
                        INSERT INTO activity_logs (user_id, user_type, action, details, ip_address)
                        VALUES (?, 'admin', 'driver_rejected', ?, ?)
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'],
                        "Rejected driver: " . ($user['name'] ?? "ID: {$id}"),
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
                    ]);

                    $_SESSION['success_message'] = "Driver registration rejected and removed.";
                } catch (Exception $e) {
                    $_SESSION['error_message'] = "Error rejecting driver: " . $e->getMessage();
                }
            }
            header('Location: drivers.php');
            exit();
    }
}

// Check for success/error messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

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
                    <i class="fas fa-users me-2"></i>Manage Drivers
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    View and manage all driver accounts (Registered and Pending Registration)
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="drivers-add.php" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i> Add New Driver
                </a>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search and Filter -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-10">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search drivers by name, email, or license number...">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i> Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Total Drivers</h6>
                            <h3 class="mb-0"><?php echo count($all_users_with_role_2); ?></h3>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Registered</h6>
                            <h3 class="mb-0"><?php echo count($registered_drivers); ?></h3>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-user-check fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Pending Registration</h6>
                            <h3 class="mb-0"><?php echo count($unregistered_drivers); ?></h3>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-user-clock fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-white border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Active</h6>
                            <h3 class="mb-0">
                                <?php 
                                    $active = 0;
                                    foreach ($all_users_with_role_2 as $user) {
                                        if (($user['user_status'] ?? 'active') == 'active') {
                                            $active++;
                                        }
                                    }
                                    echo $active;
                                ?>
                            </h3>
                        </div>
                        <div class="text-info">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="driverTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                All Drivers (<?php echo count($all_users_with_role_2); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="registered-tab" data-bs-toggle="tab" data-bs-target="#registered" type="button" role="tab">
                Registered (<?php echo count($registered_drivers); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                Pending Registration (<?php echo count($unregistered_drivers); ?>)
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="driverTabsContent">
        <!-- All Drivers Tab -->
        <div class="tab-pane fade show active" id="all" role="tabpanel">
            <?php if (empty($all_users_with_role_2)): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-users-slash fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Drivers Found</h5>
                        <p class="text-muted mb-4">
                            <?php if (!empty($search)): ?>
                                No drivers match your search criteria.
                            <?php else: ?>
                                No drivers have been registered yet.
                            <?php endif; ?>
                        </p>
                        <a href="drivers-add.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i> Add Your First Driver
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Driver Name</th>
                                        <th>Contact</th>
                                        <th>Driver Info</th>
                                        <th>Courier</th>
                                        <th>Status</th>
                                        <th>Registration</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_users_with_role_2 as $driver): 
                                        $is_registered = isset($driver['license_number']); // Check if in drivers table
                                    ?>
                                        <tr class="<?php echo !$is_registered ? 'table-warning' : ''; ?>">
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($driver['driver_name'] ?? $driver['name']); ?></div>
                                                <small class="text-muted">User ID: <?php echo $driver['id']; ?></small>
                                                <?php if (!$is_registered): ?>
                                                    <br><small class="badge bg-warning text-dark">Pending Registration</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div><i class="fas fa-envelope text-muted me-2"></i> <?php echo htmlspecialchars($driver['email']); ?></div>
                                                <div><i class="fas fa-phone text-muted me-2"></i> <?php echo htmlspecialchars($driver['phone'] ?? 'N/A'); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($is_registered): ?>
                                                    <div><strong>License:</strong> <?php echo htmlspecialchars($driver['license_number']); ?></div>
                                                    <div><strong>Type:</strong> Class <?php echo htmlspecialchars($driver['license_type'] ?? 'B'); ?></div>
                                                    <div><strong>Exp:</strong> <?php echo htmlspecialchars($driver['experience_years'] ?? 0); ?> years</div>
                                                <?php else: ?>
                                                    <div class="text-muted">Not registered as driver yet</div>
                                                    <small class="text-muted">User since: <?php echo date('Y-m-d', strtotime($driver['created_at'])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($is_registered && !empty($driver['courier_id']) && isset($courier_names[$driver['courier_id']])): ?>
                                                    <?php echo htmlspecialchars($courier_names[$driver['courier_id']]); ?>
                                                <?php elseif ($is_registered && !empty($driver['courier_id'])): ?>
                                                    <span class="text-muted">Courier ID: <?php echo $driver['courier_id']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not Assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $user_status = $driver['user_status'] ?? 'active';
                                                $status_class = $user_status == 'active' ? 'badge bg-success' : 'badge bg-secondary';
                                                ?>
                                                <span class="<?php echo $status_class; ?>">
                                                    <?php echo ucfirst($user_status); ?>
                                                </span>
                                                
                                                <?php if ($is_registered): ?>
                                                    <?php
                                                    $driver_status_class = '';
                                                    switch ($driver['status']) {
                                                        case 'available':
                                                            $driver_status_class = 'badge bg-success';
                                                            break;
                                                        case 'on_delivery':
                                                            $driver_status_class = 'badge bg-warning';
                                                            break;
                                                        case 'on_break':
                                                            $driver_status_class = 'badge bg-info';
                                                            break;
                                                        case 'inactive':
                                                            $driver_status_class = 'badge bg-secondary';
                                                            break;
                                                        default:
                                                            $driver_status_class = 'badge bg-light text-dark';
                                                    }
                                                    ?>
                                                    <br><span class="<?php echo $driver_status_class; ?> mt-1">
                                                        <?php echo ucfirst($driver['status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($is_registered): ?>
                                                    <span class="badge bg-success">Registered</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <?php if (!$is_registered): ?>
                                                        <a href="drivers.php?action=register&id=<?php echo $driver['id']; ?>" 
                                                           class="btn btn-success" 
                                                           title="Register as Driver"
                                                           onclick="return confirm('Register this user as a driver? This will assign them to a courier and create a temporary license.');">
                                                            <i class="fas fa-user-check"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="drivers-view.php?id=<?php echo $driver['id']; ?>" class="btn btn-outline-primary" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="drivers-edit.php?id=<?php echo $driver['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($user_status == 'active'): ?>
                                                        <a href="drivers.php?action=deactivate&id=<?php echo $driver['id']; ?>" 
                                                           class="btn btn-outline-secondary" 
                                                           title="Deactivate"
                                                           onclick="return confirm('Deactivate this user? They will not be able to login.');">
                                                            <i class="fas fa-ban"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="drivers.php?action=activate&id=<?php echo $driver['id']; ?>" 
                                                           class="btn btn-outline-success" 
                                                           title="Activate"
                                                           onclick="return confirm('Activate this user?');">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="drivers.php?action=delete&id=<?php echo $driver['id']; ?>" 
                                                       class="btn btn-outline-danger"
                                                       title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this driver? This action cannot be undone.');">
                                                        <i class="fas fa-trash"></i>
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

        <!-- Registered Drivers Tab -->
        <div class="tab-pane fade" id="registered" role="tabpanel">
            <?php if (empty($registered_drivers)): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-user-check fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Registered Drivers</h5>
                        <p class="text-muted mb-4">No drivers have been fully registered yet.</p>
                        <?php if (!empty($unregistered_drivers)): ?>
                            <a href="#pending" class="btn btn-warning" onclick="switchToPendingTab()">
                                <i class="fas fa-users me-2"></i> View Pending Registrations
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Driver Name</th>
                                        <th>Contact</th>
                                        <th>License Info</th>
                                        <th>Courier</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registered_drivers as $driver): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($driver['driver_name']); ?></div>
                                                <small class="text-muted">Driver ID: <?php echo $driver['id']; ?></small>
                                            </td>
                                            <td>
                                                <div><i class="fas fa-envelope text-muted me-2"></i> <?php echo htmlspecialchars($driver['email']); ?></div>
                                                <div><i class="fas fa-phone text-muted me-2"></i> <?php echo htmlspecialchars($driver['phone']); ?></div>
                                            </td>
                                            <td>
                                                <div><strong>License:</strong> <?php echo htmlspecialchars($driver['license_number']); ?></div>
                                                <div><strong>Type:</strong> Class <?php echo htmlspecialchars($driver['license_type'] ?? 'B'); ?></div>
                                                <div><strong>Exp:</strong> <?php echo htmlspecialchars($driver['experience_years'] ?? 0); ?> years</div>
                                            </td>
                                            <td>
                                                <?php if (!empty($driver['courier_id']) && isset($courier_names[$driver['courier_id']])): ?>
                                                    <?php echo htmlspecialchars($courier_names[$driver['courier_id']]); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Courier ID: <?php echo $driver['courier_id']; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status_class = '';
                                                switch ($driver['status']) {
                                                    case 'available':
                                                        $status_class = 'badge bg-success';
                                                        break;
                                                    case 'on_delivery':
                                                        $status_class = 'badge bg-warning';
                                                        break;
                                                    case 'on_break':
                                                        $status_class = 'badge bg-info';
                                                        break;
                                                    case 'inactive':
                                                        $status_class = 'badge bg-secondary';
                                                        break;
                                                    default:
                                                        $status_class = 'badge bg-light text-dark';
                                                }
                                                ?>
                                                <span class="<?php echo $status_class; ?>">
                                                    <?php echo ucfirst($driver['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="drivers-view.php?id=<?php echo $driver['id']; ?>" class="btn btn-outline-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="drivers-edit.php?id=<?php echo $driver['id']; ?>" class="btn btn-outline-warning">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="drivers.php?action=delete&id=<?php echo $driver['id']; ?>" 
                                                       class="btn btn-outline-danger"
                                                       onclick="return confirm('Are you sure you want to delete this driver? This action cannot be undone.');">
                                                        <i class="fas fa-trash"></i>
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

        <!-- Pending Registration Tab -->
        <div class="tab-pane fade" id="pending" role="tabpanel">
            <?php if (empty($unregistered_drivers)): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-user-clock fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Pending Registrations</h5>
                        <p class="text-muted mb-4">All drivers are fully registered.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="alert alert-info mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            These users have registered with role "Driver" but need to be added to the drivers table.
                            <strong>Note:</strong> Your drivers table requires courier_id and license_number fields.
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>User Name</th>
                                        <th>Contact</th>
                                        <th>Registration Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unregistered_drivers as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($user['name']); ?></div>
                                                <small class="text-muted">ID: <?php echo $user['id']; ?></small>
                                            </td>
                                            <td>
                                                <div><i class="fas fa-envelope text-muted me-2"></i> <?php echo htmlspecialchars($user['email']); ?></div>
                                                <div><i class="fas fa-phone text-muted me-2"></i> <?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></div>
                                            </td>
                                            <td>
                                                <?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $status_class = $user['user_status'] == 'active' ? 'badge bg-success' : 'badge bg-secondary';
                                                ?>
                                                <span class="<?php echo $status_class; ?>">
                                                    <?php echo ucfirst($user['user_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="drivers.php?action=approve&id=<?php echo $user['id']; ?>"
                                                       class="btn btn-success"
                                                       title="Approve Driver"
                                                       onclick="return confirm('Approve this driver? They will be able to login and accept deliveries.');">
                                                        <i class="fas fa-check"></i> Approve
                                                    </a>
                                                    <a href="drivers.php?action=reject&id=<?php echo $user['id']; ?>"
                                                       class="btn btn-danger"
                                                       title="Reject Driver"
                                                       onclick="return confirm('Reject this driver? Their registration will be removed.');">
                                                        <i class="fas fa-times"></i> Reject
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
    </div>

    <!-- Export Options -->
    <div class="mt-4 d-flex justify-content-between align-items-center">
        <div class="text-muted small">
            Showing <?php echo count($all_users_with_role_2); ?> drivers total
            (<?php echo count($registered_drivers); ?> registered, <?php echo count($unregistered_drivers); ?> pending)
        </div>
        <div>
            <button class="btn btn-sm btn-outline-success" onclick="exportDrivers()">
                <i class="fas fa-file-excel me-2"></i> Export to Excel
            </button>
            <button class="btn btn-sm btn-outline-primary ms-2" onclick="printDrivers()">
                <i class="fas fa-print me-2"></i> Print List
            </button>
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

    // Switch to pending tab from registered tab
    function switchToPendingTab() {
        const pendingTab = new bootstrap.Tab(document.getElementById('pending-tab'));
        pendingTab.show();
    }

    // Export drivers to Excel
    function exportDrivers() {
        window.open('export-drivers.php?search=<?php echo urlencode($search); ?>', '_blank');
    }

    // Print drivers list
    function printDrivers() {
        const printContents = document.querySelector('.tab-pane.active').innerHTML;
        const originalContents = document.body.innerHTML;
        
        document.body.innerHTML = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Drivers List - <?php echo date('Y-m-d'); ?></title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    @media print {
                        .no-print { display: none !important; }
                        .table { font-size: 12px; }
                        h1, h2, h3, h4, h5, h6 { color: #000 !important; }
                        .btn { display: none !important; }
                        .nav-tabs { display: none !important; }
                        .badge { border: 1px solid #000; }
                    }
                    body { font-size: 12px; }
                    .badge { font-size: 10px !important; }
                </style>
            </head>
            <body>
                <div class="container py-4">
                    <div class="text-center mb-4">
                        <h4>Drivers List - <?php echo date('Y-m-d H:i'); ?></h4>
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
    }

    // Tab click handler
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('click', function() {
            // Update URL with active tab
            const tabId = this.getAttribute('data-bs-target').substring(1);
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);
        });
    });

    // On page load, activate saved tab
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) {
            const tabElement = document.getElementById(`${activeTab}-tab`);
            if (tabElement) {
                const tab = new bootstrap.Tab(tabElement);
                tab.show();
            }
        }
    });
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>