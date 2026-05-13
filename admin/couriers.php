<?php
/**
 * Couriers Management
 * 
 * Production-ready couriers management following the same pattern as working files
 * 
 * @package CourierTruckManagement
 * @subpackage Admin
 * @version 1.0.0
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
// 4. VERIFY DATABASE CONNECTION (Same as working files)
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
    error_log("Couriers database connection error: " . $connection_error);
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

$pageTitle = "Couriers Management";

// ============================================================================
// 6. HANDLE POST REQUESTS (Add, Edit, Delete, Status Change)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connected) {
    
    // ========== ADD NEW COURIER ==========
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        try {
            $name = trim($_POST['name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $max_trucks = intval($_POST['max_trucks'] ?? 10);
            $status = $_POST['status'] ?? 'active';
            
            // Validate required fields
            if (empty($name)) {
                throw new Exception("Courier name is required");
            }
            
            // Handle logo upload if any
            $logo_path = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = ROOT_PATH . '/uploads/couriers/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $filename = 'courier_' . time() . '_' . uniqid() . '.' . $file_ext;
                $target_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                    $logo_path = '/uploads/couriers/' . $filename;
                }
            }
            
            $stmt = $db->prepare("
                INSERT INTO couriers (name, contact_person, phone, email, address, max_trucks, status, logo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([$name, $contact_person, $phone, $email, $address, $max_trucks, $status, $logo_path]);
            
            // Log activity
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'add_courier', ?, ?, ?)
            ");
            $log_stmt->execute([
                $_SESSION['user_id'],
                "Added new courier: $name",
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $_SESSION['success_message'] = "Courier added successfully";
            
        } catch (Exception $e) {
            error_log("Add courier error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error adding courier: " . $e->getMessage();
        }
        
        header('Location: couriers.php');
        exit();
    }
    
    // ========== EDIT COURIER ==========
    elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
        try {
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $max_trucks = intval($_POST['max_trucks'] ?? 10);
            $status = $_POST['status'] ?? 'active';
            
            if ($id <= 0) {
                throw new Exception("Invalid courier ID");
            }
            
            if (empty($name)) {
                throw new Exception("Courier name is required");
            }
            
            // Handle logo upload if any
            $logo_path = null;
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = ROOT_PATH . '/uploads/couriers/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $filename = 'courier_' . time() . '_' . uniqid() . '.' . $file_ext;
                $target_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                    $logo_path = '/uploads/couriers/' . $filename;
                    
                    // Delete old logo if exists
                    $old_logo_stmt = $db->prepare("SELECT logo FROM couriers WHERE id = ?");
                    $old_logo_stmt->execute([$id]);
                    $old_logo = $old_logo_stmt->fetchColumn();
                    
                    if ($old_logo && file_exists(ROOT_PATH . $old_logo)) {
                        unlink(ROOT_PATH . $old_logo);
                    }
                }
            }
            
            if ($logo_path) {
                $stmt = $db->prepare("
                    UPDATE couriers 
                    SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, 
                        max_trucks = ?, status = ?, logo = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $max_trucks, $status, $logo_path, $id]);
            } else {
                $stmt = $db->prepare("
                    UPDATE couriers 
                    SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?, 
                        max_trucks = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$name, $contact_person, $phone, $email, $address, $max_trucks, $status, $id]);
            }
            
            // Log activity
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'edit_courier', ?, ?, ?)
            ");
            $log_stmt->execute([
                $_SESSION['user_id'],
                "Updated courier: $name (ID: $id)",
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $_SESSION['success_message'] = "Courier updated successfully";
            
        } catch (Exception $e) {
            error_log("Edit courier error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error updating courier: " . $e->getMessage();
        }
        
        header('Location: couriers.php');
        exit();
    }
    
    // ========== DELETE COURIER ==========
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        try {
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception("Invalid courier ID");
            }
            
            // Check if courier has trucks or drivers
            $check_stmt = $db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM trucks WHERE courier_id = ?) as truck_count,
                    (SELECT COUNT(*) FROM drivers WHERE courier_id = ?) as driver_count
            ");
            $check_stmt->execute([$id, $id]);
            $counts = $check_stmt->fetch();
            
            if (($counts['truck_count'] ?? 0) > 0) {
                throw new Exception("Cannot delete courier with assigned trucks. Please reassign or delete trucks first.");
            }
            
            if (($counts['driver_count'] ?? 0) > 0) {
                throw new Exception("Cannot delete courier with assigned drivers. Please reassign or delete drivers first.");
            }
            
            // Get courier name for log
            $name_stmt = $db->prepare("SELECT name FROM couriers WHERE id = ?");
            $name_stmt->execute([$id]);
            $courier_name = $name_stmt->fetchColumn();
            
            // Delete logo if exists
            $logo_stmt = $db->prepare("SELECT logo FROM couriers WHERE id = ?");
            $logo_stmt->execute([$id]);
            $logo = $logo_stmt->fetchColumn();
            
            if ($logo && file_exists(ROOT_PATH . $logo)) {
                unlink(ROOT_PATH . $logo);
            }
            
            // Delete courier
            $stmt = $db->prepare("DELETE FROM couriers WHERE id = ?");
            $stmt->execute([$id]);
            
            // Log activity
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'delete_courier', ?, ?, ?)
            ");
            $log_stmt->execute([
                $_SESSION['user_id'],
                "Deleted courier: $courier_name (ID: $id)",
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $_SESSION['success_message'] = "Courier deleted successfully";
            
        } catch (Exception $e) {
            error_log("Delete courier error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error deleting courier: " . $e->getMessage();
        }
        
        header('Location: couriers.php');
        exit();
    }
    
    // ========== TOGGLE COURIER STATUS ==========
    elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        try {
            $id = intval($_POST['id'] ?? 0);
            $new_status = $_POST['status'] ?? 'active';
            
            if ($id <= 0) {
                throw new Exception("Invalid courier ID");
            }
            
            $stmt = $db->prepare("UPDATE couriers SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            // Get courier name for log
            $name_stmt = $db->prepare("SELECT name FROM couriers WHERE id = ?");
            $name_stmt->execute([$id]);
            $courier_name = $name_stmt->fetchColumn();
            
            // Log activity
            $log_stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, 'toggle_courier_status', ?, ?, ?)
            ");
            $log_stmt->execute([
                $_SESSION['user_id'],
                "Changed courier $courier_name status to $new_status",
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $_SESSION['success_message'] = "Courier status updated successfully";
            
        } catch (Exception $e) {
            error_log("Toggle courier status error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error updating courier status: " . $e->getMessage();
        }
        
        header('Location: couriers.php');
        exit();
    }
}

// ============================================================================
// 7. GET COURIERS LIST WITH STATISTICS
// ============================================================================
$couriers = [];
$total_couriers = 0;
$active_couriers = 0;
$total_trucks_all = 0;
$total_drivers_all = 0;

if ($db_connected) {
    try {
        // Get summary stats
        $stats_stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
            FROM couriers
        ");
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch();
        $total_couriers = $stats['total'] ?? 0;
        $active_couriers = $stats['active'] ?? 0;
        
        // Get couriers with truck and driver counts
        $couriers_stmt = $db->prepare("
            SELECT 
                c.*,
                COUNT(DISTINCT t.id) as truck_count,
                COUNT(DISTINCT d.id) as driver_count,
                SUM(CASE WHEN t.status = 'available' THEN 1 ELSE 0 END) as available_trucks,
                SUM(CASE WHEN t.status IN ('assigned', 'on_delivery') THEN 1 ELSE 0 END) as active_trucks,
                SUM(CASE WHEN d.status = 'available' THEN 1 ELSE 0 END) as available_drivers,
                SUM(CASE WHEN d.status IN ('on_delivery', 'on_break') THEN 1 ELSE 0 END) as active_drivers
            FROM couriers c
            LEFT JOIN trucks t ON c.id = t.courier_id
            LEFT JOIN drivers d ON c.id = d.courier_id
            GROUP BY c.id
            ORDER BY c.name ASC
        ");
        $couriers_stmt->execute();
        $couriers = $couriers_stmt->fetchAll();
        
        // Calculate totals
        foreach ($couriers as $courier) {
            $total_trucks_all += $courier['truck_count'] ?? 0;
            $total_drivers_all += $courier['driver_count'] ?? 0;
        }
        
    } catch (Exception $e) {
        error_log("Couriers query error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error loading couriers data: " . $e->getMessage();
    }
} else {
    $_SESSION['error_message'] = "Database connection error: " . ($connection_error ?? 'Unknown error');
}

// ============================================================================
// 8. GET COURIER FOR EDIT (if ID is provided)
// ============================================================================
$edit_courier = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit']) && $db_connected) {
    try {
        $edit_id = intval($_GET['edit']);
        $edit_stmt = $db->prepare("SELECT * FROM couriers WHERE id = ?");
        $edit_stmt->execute([$edit_id]);
        $edit_courier = $edit_stmt->fetch();
    } catch (Exception $e) {
        error_log("Fetch courier for edit error: " . $e->getMessage());
    }
}

// ============================================================================
// 9. GET USER NAME (Same as working files)
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
     Couriers Management Content
     ============================================================================ -->
<div class="main-container" style="margin-left: 250px; padding: 20px; min-height: 100vh;">
    
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #306998;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #0D2B4E; font-weight: 700;">
                    <i class="fas fa-building me-2"></i>Couriers Management
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Manage courier companies, their trucks and drivers
                </p>
            </div>
            
            <!-- Summary Stats -->
            <div class="d-flex gap-3 mt-2 mt-md-0">
                <div class="text-center">
                    <span class="badge bg-primary p-2">
                        <i class="fas fa-building me-1"></i> Total: <?php echo $total_couriers; ?>
                    </span>
                </div>
                <div class="text-center">
                    <span class="badge bg-success p-2">
                        <i class="fas fa-check-circle me-1"></i> Active: <?php echo $active_couriers; ?>
                    </span>
                </div>
                <div class="text-center">
                    <span class="badge bg-info p-2">
                        <i class="fas fa-truck me-1"></i> Trucks: <?php echo $total_trucks_all; ?>
                    </span>
                </div>
                <div class="text-center">
                    <span class="badge bg-warning p-2">
                        <i class="fas fa-users me-1"></i> Drivers: <?php echo $total_drivers_all; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages -->
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
            <strong>Database Connection Issue:</strong> Unable to connect to the database.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Courier Form -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-<?php echo $edit_courier ? 'edit' : 'plus-circle'; ?> me-2"></i>
                <?php echo $edit_courier ? 'Edit Courier' : 'Add New Courier'; ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="action" value="<?php echo $edit_courier ? 'edit' : 'add'; ?>">
                <?php if ($edit_courier): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_courier['id']; ?>">
                <?php endif; ?>
                
                <div class="col-md-6">
                    <label class="form-label">Company Name *</label>
                    <input type="text" class="form-control" name="name" required 
                           value="<?php echo $edit_courier ? htmlspecialchars($edit_courier['name']) : ''; ?>">
                </div>
                
                <div class="col-md-6">
                    <label class="form-label">Contact Person</label>
                    <input type="text" class="form-control" name="contact_person"
                           value="<?php echo $edit_courier ? htmlspecialchars($edit_courier['contact_person'] ?? '') : ''; ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" name="phone"
                           value="<?php echo $edit_courier ? htmlspecialchars($edit_courier['phone'] ?? '') : ''; ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email"
                           value="<?php echo $edit_courier ? htmlspecialchars($edit_courier['email'] ?? '') : ''; ?>">
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Max Trucks Allowed</label>
                    <input type="number" class="form-control" name="max_trucks" min="1" value="10"
                           value="<?php echo $edit_courier ? htmlspecialchars($edit_courier['max_trucks'] ?? 10) : 10; ?>">
                </div>
                
                <div class="col-md-8">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"><?php echo $edit_courier ? htmlspecialchars($edit_courier['address'] ?? '') : ''; ?></textarea>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" <?php echo ($edit_courier && $edit_courier['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_courier && $edit_courier['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Company Logo</label>
                    <input type="file" class="form-control" name="logo" accept="image/*">
                    <?php if ($edit_courier && !empty($edit_courier['logo'])): ?>
                        <small class="text-muted d-block mt-1">
                            Current: <a href="<?php echo htmlspecialchars($edit_courier['logo']); ?>" target="_blank">View Logo</a>
                        </small>
                    <?php endif; ?>
                </div>
                
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i><?php echo $edit_courier ? 'Update Courier' : 'Save Courier'; ?>
                    </button>
                    <?php if ($edit_courier): ?>
                        <a href="couriers.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Couriers List -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0" style="color: #0D2B4E;">
                <i class="fas fa-list me-2"></i>Couriers List
            </h5>
            <div class="d-flex gap-2">
                <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search couriers..." style="width: 200px;">
                <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                    <option value="all">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($couriers)): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="couriersTable">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Company</th>
                                <th>Contact</th>
                                <th>Phone/Email</th>
                                <th>Trucks</th>
                                <th>Drivers</th>
                                <th>Max Trucks</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($couriers as $courier): ?>
                                <tr>
                                    <td>#<?php echo $courier['id']; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($courier['logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($courier['logo']); ?>" alt="logo" style="width: 30px; height: 30px; object-fit: cover; border-radius: 5px; margin-right: 10px;">
                                            <?php else: ?>
                                                <div style="width: 30px; height: 30px; background-color: #e9ecef; border-radius: 5px; margin-right: 10px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-building text-secondary"></i>
                                                </div>
                                            <?php endif; ?>
                                            <strong><?php echo htmlspecialchars($courier['name']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($courier['contact_person'] ?? '-'); ?></td>
                                    <td>
                                        <?php if (!empty($courier['phone'])): ?>
                                            <div><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($courier['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($courier['email'])): ?>
                                            <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($courier['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $courier['truck_count'] ?? 0; ?></span>
                                        <small class="text-muted d-block">
                                            (<?php echo ($courier['available_trucks'] ?? 0); ?> avail)
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning"><?php echo $courier['driver_count'] ?? 0; ?></span>
                                        <small class="text-muted d-block">
                                            (<?php echo ($courier['available_drivers'] ?? 0); ?> avail)
                                        </small>
                                    </td>
                                    <td><?php echo $courier['max_trucks'] ?? 10; ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;" class="status-form">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id" value="<?php echo $courier['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo $courier['status'] == 'active' ? 'inactive' : 'active'; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $courier['status'] == 'active' ? 'btn-success' : 'btn-secondary'; ?>" 
                                                    onclick="return confirm('Change status to <?php echo $courier['status'] == 'active' ? 'inactive' : 'active'; ?>?')">
                                                <?php echo ucfirst($courier['status']); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?edit=<?php echo $courier['id']; ?>" class="btn btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="trucks.php?courier_id=<?php echo $courier['id']; ?>" class="btn btn-outline-info" title="View Trucks">
                                                <i class="fas fa-truck"></i>
                                            </a>
                                            <a href="drivers.php?courier_id=<?php echo $courier['id']; ?>" class="btn btn-outline-warning" title="View Drivers">
                                                <i class="fas fa-users"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" title="Delete"
                                                    onclick="confirmDelete(<?php echo $courier['id']; ?>, '<?php echo htmlspecialchars($courier['name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Delete Form (Hidden) -->
                                        <form id="delete-form-<?php echo $courier['id']; ?>" method="POST" style="display: none;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $courier['id']; ?>">
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-building fa-3x text-muted mb-3"></i>
                    <h6 class="text-muted">No couriers found</h6>
                    <p class="text-muted small">Click "Add New Courier" to get started</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Table Footer with Pagination -->
        <div class="card-footer bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    Showing <?php echo count($couriers); ?> of <?php echo $total_couriers; ?> couriers
                </div>
                <div>
                    <!-- Pagination can be added here if needed -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteCourierName"></strong>?</p>
                <p class="text-danger small">This action cannot be undone. All associated data will be checked before deletion.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Auto-hide alerts and JavaScript -->
<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Delete confirmation
    let deleteId = null;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    
    function confirmDelete(id, name) {
        deleteId = id;
        document.getElementById('deleteCourierName').textContent = name;
        deleteModal.show();
    }
    
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (deleteId) {
            document.getElementById('delete-form-' + deleteId).submit();
        }
    });
    
    // Search functionality
    document.getElementById('searchInput').addEventListener('keyup', function() {
        let searchText = this.value.toLowerCase();
        let rows = document.querySelectorAll('#couriersTable tbody tr');
        
        rows.forEach(row => {
            let text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchText) ? '' : 'none';
        });
    });
    
    // Status filter
    document.getElementById('statusFilter').addEventListener('change', function() {
        let filterValue = this.value;
        let rows = document.querySelectorAll('#couriersTable tbody tr');
        
        rows.forEach(row => {
            if (filterValue === 'all') {
                row.style.display = '';
            } else {
                let statusCell = row.querySelector('td:nth-child(8) button');
                if (statusCell) {
                    let status = statusCell.textContent.trim().toLowerCase();
                    row.style.display = status === filterValue ? '' : 'none';
                }
            }
        });
    });
</script>

<?php
// ============================================================================
// 12. INCLUDE FOOTER
// ============================================================================
require_once ROOT_PATH . '/includes/footer.php';
?>