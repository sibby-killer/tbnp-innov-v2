<?php
// update-truck-status.php - Update Truck Status
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

$pageTitle = "Update Truck Status - Driver Dashboard";
$driver_id = $_SESSION['user_id'];

// Get truck ID from URL
$truck_id = isset($_GET['truck_id']) ? intval($_GET['truck_id']) : 0;

if (!$truck_id) {
    header('Location: trucks.php');
    exit();
}

// Get truck information and verify ownership
$truck_info = [];
$error = '';
$success = '';

try {
    // Verify the truck belongs to this driver - using only existing columns
    $truck_stmt = $db->prepare("
        SELECT t.*, d.user_id as driver_user_id, 
               c.name as courier_name,
               u.name as driver_name,
               t.status as current_status,
               t.plate_number,
               t.brand,
               t.model,
               t.current_location
        FROM trucks t
        LEFT JOIN drivers d ON t.driver_id = d.id
        LEFT JOIN couriers c ON t.courier_id = c.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE t.id = ? AND d.user_id = ?
    ");
    $truck_stmt->execute([$truck_id, $driver_id]);
    $truck_info = $truck_stmt->fetch();
    
    if (!$truck_info) {
        $error = "Truck not found or you don't have permission to update this truck's status.";
    }
} catch (Exception $e) {
    error_log("Update truck status error: " . $e->getMessage());
    $error = "Error loading truck information: " . $e->getMessage();
}

// Check if odometer column exists, if not add it
try {
    $column_check = $db->prepare("
        SELECT COUNT(*) as column_exists 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'trucks' 
        AND COLUMN_NAME = 'odometer'
    ");
    $column_check->execute();
    $result = $column_check->fetch();
    
    if ($result['column_exists'] == 0) {
        // Add odometer column
        $alter_stmt = $db->prepare("
            ALTER TABLE trucks 
            ADD COLUMN odometer INT NULL DEFAULT NULL AFTER current_location
        ");
        $alter_stmt->execute();
    }
} catch (Exception $e) {
    error_log("Column check error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $transactionStarted = false;
    
    try {
        $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $fuel_level = isset($_POST['fuel_level']) ? trim($_POST['fuel_level']) : '';
        $odometer = isset($_POST['odometer']) ? trim($_POST['odometer']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        // Validate inputs
        if (empty($new_status)) {
            throw new Exception("Please select a status.");
        }
        
        if (empty($location)) {
            throw new Exception("Please provide your current location.");
        }
        
        // Validate fuel level if provided
        if (!empty($fuel_level) && ($fuel_level < 0 || $fuel_level > 100)) {
            throw new Exception("Fuel level must be between 0 and 100.");
        }
        
        // Get driver ID from drivers table
        $driver_stmt = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
        $driver_stmt->execute([$driver_id]);
        $driver_record = $driver_stmt->fetch();
        
        if (!$driver_record) {
            throw new Exception("Driver record not found.");
        }
        
        $driver_db_id = $driver_record['id'];
        
        // Start transaction
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $transactionStarted = true;
        }
        
        // Build update query based on available columns
        $update_fields = [
            'status = ?',
            'current_location = ?',
            'updated_at = NOW()',
            'notes = CONCAT(IFNULL(notes, \'\'), \'\n[\', DATE_FORMAT(NOW(), \'%Y-%m-%d %H:%i\'), \'] Status changed to \', ?, \': \', ?)'
        ];
        
        $update_params = [
            $new_status,
            $location,
            $new_status,
            $notes
        ];
        
        // Check if odometer column exists before adding it to update
        $column_check = $db->prepare("
            SELECT COUNT(*) as column_exists 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'trucks' 
            AND COLUMN_NAME = 'odometer'
        ");
        $column_check->execute();
        $odometer_exists = $column_check->fetch();
        
        if ($odometer_exists['column_exists'] == 1 && !empty($odometer)) {
            $update_fields[] = 'odometer = ?';
            $update_params[] = $odometer;
        }
        
        // Create the update query
        $update_sql = "UPDATE trucks SET " . implode(', ', $update_fields) . " WHERE id = ? AND driver_id = ?";
        $update_params[] = $truck_id;
        $update_params[] = $driver_db_id;
        
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->execute($update_params);
        
        // Check if truck_status_logs table exists, if not create it
        $table_check = $db->prepare("SHOW TABLES LIKE 'truck_status_logs'");
        $table_check->execute();
        $table_exists = $table_check->fetch();
        
        if (!$table_exists) {
            // Create truck_status_logs table
            $create_table = $db->prepare("
                CREATE TABLE IF NOT EXISTS truck_status_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    truck_id INT NOT NULL,
                    driver_id INT NOT NULL,
                    old_status VARCHAR(50),
                    new_status VARCHAR(50) NOT NULL,
                    location VARCHAR(255),
                    fuel_level INT,
                    odometer INT,
                    notes TEXT,
                    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE,
                    FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $create_table->execute();
        }
        
        // Log the status change
        $log_stmt = $db->prepare("
            INSERT INTO truck_status_logs 
            (truck_id, driver_id, old_status, new_status, location, fuel_level, odometer, notes, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $log_stmt->execute([
            $truck_id,
            $driver_db_id,
            $truck_info['current_status'],
            $new_status,
            $location,
            $fuel_level ?: null,
            $odometer ?: null,
            $notes
        ]);
        
        // Create activity log
        try {
            $activity_check = $db->prepare("SHOW TABLES LIKE 'activity_logs'");
            $activity_check->execute();
            $activity_exists = $activity_check->fetch();
            
            if (!$activity_exists) {
                // Create activity_logs table if it doesn't exist
                $create_activity = $db->prepare("
                    CREATE TABLE IF NOT EXISTS activity_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        action VARCHAR(100),
                        description TEXT,
                        ip_address VARCHAR(45),
                        user_agent TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                $create_activity->execute();
            }
            
            $activity_msg = "Driver {$truck_info['driver_name']} updated truck {$truck_info['plate_number']} status from {$truck_info['current_status']} to {$new_status}";
            $activity_stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at)
                VALUES (?, 'update_truck_status', ?, ?, ?, NOW())
            ");
            $activity_stmt->execute([
                $driver_id,
                $activity_msg,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
            // Continue even if activity log fails
        }
        
        // Send notification for certain status changes
        if (in_array($new_status, ['maintenance', 'out_of_service'])) {
            try {
                $notif_check = $db->prepare("SHOW TABLES LIKE 'notifications'");
                $notif_check->execute();
                $notif_exists = $notif_check->fetch();
                
                if (!$notif_exists) {
                    // Create notifications table if it doesn't exist
                    $create_notif = $db->prepare("
                        CREATE TABLE IF NOT EXISTS notifications (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            title VARCHAR(255),
                            message TEXT,
                            type VARCHAR(50),
                            priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                            is_read BOOLEAN DEFAULT FALSE,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            read_at TIMESTAMP NULL,
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    ");
                    $create_notif->execute();
                }
                
                $notification_msg = "Truck {$truck_info['plate_number']} status changed to: {$new_status} at {$location}";
                $notif_stmt = $db->prepare("
                    INSERT INTO notifications (user_id, title, message, type, priority, created_at)
                    SELECT id, 'Truck Status Update', ?, 'warning', 'medium', NOW()
                    FROM users 
                    WHERE role_id IN (?, ?)  -- Admin and Dispatcher roles
                ");
                $notif_stmt->execute([$notification_msg, ROLE_ADMIN, ROLE_DISPATCHER]);
            } catch (Exception $e) {
                error_log("Notification error: " . $e->getMessage());
                // Continue even if notification fails
            }
        }
        
        // Commit transaction if we started it
        if ($transactionStarted) {
            $db->commit();
        }
        
        // Update local truck info
        $truck_info['current_status'] = $new_status;
        $truck_info['current_location'] = $location;
        if ($odometer) $truck_info['odometer'] = $odometer;
        
        $success = "Truck status updated successfully!";
        
    } catch (Exception $e) {
        // Rollback only if we started a transaction
        if ($transactionStarted && $db->inTransaction()) {
            try {
                $db->rollBack();
            } catch (Exception $rollbackError) {
                error_log("Rollback error: " . $rollbackError->getMessage());
            }
        }
        $error = "Error updating status: " . $e->getMessage();
    }
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

/* Status badges */
.status-badge {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
    border-width: 2px;
}

.status-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* Status colors - Updated to match your table's status values */
.status-available { 
    background-color: #d1e7dd; 
    color: #0f5132;
    border-color: #badbcc;
}
.status-on_delivery { 
    background-color: #fff3cd; 
    color: #664d03;
    border-color: #ffecb5;
}
.status-maintenance { 
    background-color: #f8d7da; 
    color: #842029;
    border-color: #f5c2c7;
}
.status-out_of_service { 
    background-color: #dc3545; 
    color: white;
    border-color: #dc3545;
}
.status-assigned { 
    background-color: #0dcaf0; 
    color: white;
    border-color: #0dcaf0;
}
.status-fueling { 
    background-color: #0dcaf0; 
    color: white;
    border-color: #0dcaf0;
}
.status-loading { 
    background-color: #fd7e14; 
    color: white;
    border-color: #fd7e14;
}
.status-unloading { 
    background-color: #20c997; 
    color: white;
    border-color: #20c997;
}

/* Card styling */
.info-card {
    border-left: 4px solid #3b82f6;
}

.status-card {
    border-left: 4px solid #f59e0b;
}

/* Fuel gauge */
.fuel-gauge {
    width: 100%;
    height: 20px;
    background: #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.fuel-level {
    height: 100%;
    border-radius: 10px;
    transition: width 0.5s ease;
}

.fuel-text {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.8rem;
    color: #1f2937;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .status-badge {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }
    
    .form-section {
        margin-bottom: 20px;
    }
}
</style>

<!-- Driver Container -->
<div class="driver-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #f59e0b;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-edit me-2"></i>Update Truck Status
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    <?php if (!empty($truck_info)): ?>
                        Update status for: <strong><?php echo htmlspecialchars($truck_info['plate_number']); ?></strong>
                    <?php else: ?>
                        Truck Status Update
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <a href="trucks.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Truck Details
                </a>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <?php if (!empty($truck_info)): ?>
        <div class="row">
            <!-- Left Column: Form -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <form method="POST" action="" id="statusUpdateForm">
                            <!-- Current Status Display -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-info-circle me-2 text-primary"></i>Current Status
                                </h5>
                                <div class="alert alert-info">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>Current Status:</strong>
                                            <span class="badge bg-<?php 
                                                $status = $truck_info['current_status'] ?? 'available';
                                                echo $status == 'available' ? 'success' : 
                                                       ($status == 'on_delivery' ? 'warning' : 
                                                       ($status == 'maintenance' ? 'danger' : 
                                                       ($status == 'out_of_service' ? 'danger' : 
                                                       ($status == 'assigned' ? 'primary' : 'secondary')))); ?> ms-2">
                                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                                            </span>
                                        </div>
                                        <div>
                                            <strong>Last Updated:</strong>
                                            <span class="ms-2"><?php echo date('M d, H:i', strtotime($truck_info['updated_at'] ?? 'now')); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- New Status Selection -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-sync-alt me-2 text-warning"></i>Select New Status
                                </h5>
                                
                                <div class="row g-2 mb-3">
                                    <!-- Available -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="available" id="status_available">
                                        <label class="btn status-badge status-available w-100" for="status_available">
                                            <i class="fas fa-check-circle me-1"></i> Available
                                        </label>
                                    </div>
                                    
                                    <!-- On Delivery -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="on_delivery" id="status_on_delivery">
                                        <label class="btn status-badge status-on_delivery w-100" for="status_on_delivery">
                                            <i class="fas fa-truck-loading me-1"></i> On Delivery
                                        </label>
                                    </div>
                                    
                                    <!-- Assigned -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="assigned" id="status_assigned">
                                        <label class="btn status-badge status-assigned w-100" for="status_assigned">
                                            <i class="fas fa-user-check me-1"></i> Assigned
                                        </label>
                                    </div>
                                    
                                    <!-- Fueling -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="fueling" id="status_fueling">
                                        <label class="btn status-badge status-fueling w-100" for="status_fueling">
                                            <i class="fas fa-gas-pump me-1"></i> Fueling
                                        </label>
                                    </div>
                                    
                                    <!-- Loading -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="loading" id="status_loading">
                                        <label class="btn status-badge status-loading w-100" for="status_loading">
                                            <i class="fas fa-box-open me-1"></i> Loading
                                        </label>
                                    </div>
                                    
                                    <!-- Unloading -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="unloading" id="status_unloading">
                                        <label class="btn status-badge status-unloading w-100" for="status_unloading">
                                            <i class="fas fa-dolly me-1"></i> Unloading
                                        </label>
                                    </div>
                                    
                                    <!-- Maintenance -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="maintenance" id="status_maintenance">
                                        <label class="btn status-badge status-maintenance w-100" for="status_maintenance">
                                            <i class="fas fa-tools me-1"></i> Maintenance
                                        </label>
                                    </div>
                                    
                                    <!-- Out of Service -->
                                    <div class="col-6 col-md-4 col-lg-3">
                                        <input type="radio" class="btn-check" name="status" value="out_of_service" id="status_out_of_service">
                                        <label class="btn status-badge status-out_of_service w-100" for="status_out_of_service">
                                            <i class="fas fa-ban me-1"></i> Out of Service
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning small">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <strong>Important:</strong> Select the appropriate status for your current situation. 
                                    Emergency statuses (Maintenance, Out of Service) will notify management immediately.
                                </div>
                            </div>
                            
                            <!-- Location & Details -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-map-marker-alt me-2 text-success"></i>Location & Details
                                </h5>
                                
                                <div class="row">
                                    <!-- Current Location -->
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label required">
                                            <i class="fas fa-map-pin me-1"></i>Current Location
                                        </label>
                                        <input type="text" class="form-control" name="location" 
                                               value="<?php echo htmlspecialchars($truck_info['current_location'] ?? ''); ?>"
                                               placeholder="Enter your current location (address, landmark, or GPS coordinates)"
                                               required>
                                        <small class="text-muted">
                                            This helps dispatchers track your position
                                        </small>
                                    </div>
                                    
                                    <!-- Fuel Level (Optional - for logging purposes only) -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-gas-pump me-1"></i>Fuel Level (%)
                                        </label>
                                        <div class="d-flex align-items-center">
                                            <input type="range" class="form-range me-3" id="fuelSlider" 
                                                   min="0" max="100" step="5" 
                                                   value="50">
                                            <input type="number" class="form-control" name="fuel_level" 
                                                   id="fuelInput" style="width: 80px;"
                                                   min="0" max="100"
                                                   value=""
                                                   placeholder="0-100">
                                        </div>
                                        <div class="fuel-gauge mt-2">
                                            <div class="fuel-level" id="fuelGauge" 
                                                 style="width: 50%; background: #198754;">
                                            </div>
                                            <div class="fuel-text" id="fuelText">
                                                50%
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            Optional: For status logging only (not stored in truck record)
                                        </small>
                                    </div>
                                    
                                    <!-- Odometer -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-tachometer-alt me-1"></i>Odometer (km)
                                        </label>
                                        <input type="number" class="form-control" name="odometer" 
                                               value="<?php echo $truck_info['odometer'] ?? ''; ?>"
                                               placeholder="Current odometer reading">
                                        <small class="text-muted">
                                            Optional: For maintenance tracking
                                        </small>
                                    </div>
                                    
                                    <!-- Additional Notes -->
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-sticky-note me-1"></i>Additional Notes
                                        </label>
                                        <textarea class="form-control" name="notes" rows="3" 
                                                  placeholder="Any additional information about the status change..."></textarea>
                                        <small class="text-muted">
                                            Optional: ETA, traffic conditions, special instructions, etc.
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                        <i class="fas fa-redo me-2"></i> Reset Form
                                    </button>
                                    <button type="submit" name="update_status" class="btn btn-warning px-4">
                                        <i class="fas fa-save me-2"></i> Update Status
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Truck Info & History -->
            <div class="col-lg-4">
                <!-- Truck Information Card -->
                <div class="card info-card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-truck me-2"></i>Truck Information
                        </h5>
                        
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <div class="bg-primary rounded-circle p-2 me-3">
                                    <i class="fas fa-truck text-white"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0"><?php echo htmlspecialchars($truck_info['plate_number']); ?></h6>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars(($truck_info['brand'] ?? '') . ' ' . ($truck_info['model'] ?? '')); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Driver:</span>
                                <span><?php echo htmlspecialchars($truck_info['driver_name'] ?? 'Not assigned'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Courier:</span>
                                <span><?php echo htmlspecialchars($truck_info['courier_name'] ?? 'Not assigned'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Current Location:</span>
                                <span class="text-truncate" style="max-width: 150px;" 
                                      title="<?php echo htmlspecialchars($truck_info['current_location'] ?? 'Unknown'); ?>">
                                    <?php echo htmlspecialchars($truck_info['current_location'] ?? 'Unknown'); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Last Update:</span>
                                <span><?php echo date('H:i', strtotime($truck_info['updated_at'] ?? 'now')); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Status History -->
                <div class="card status-card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-history me-2"></i>Recent Status History
                        </h5>
                        
                        <div class="small">
                            <?php
                            try {
                                // Check if status log table exists
                                $table_check = $db->prepare("SHOW TABLES LIKE 'truck_status_logs'");
                                $table_check->execute();
                                $table_exists = $table_check->fetch();
                                
                                if ($table_exists) {
                                    // Get recent status changes
                                    $history_stmt = $db->prepare("
                                        SELECT new_status, location, changed_at
                                        FROM truck_status_logs 
                                        WHERE truck_id = ? 
                                        ORDER BY changed_at DESC 
                                        LIMIT 5
                                    ");
                                    $history_stmt->execute([$truck_id]);
                                    $status_history = $history_stmt->fetchAll();
                                    
                                    if (!empty($status_history)) {
                                        foreach ($status_history as $history) {
                                            $status_color = '';
                                            $status = $history['new_status'];
                                            if ($status == 'available') $status_color = 'success';
                                            elseif ($status == 'on_delivery') $status_color = 'warning';
                                            elseif ($status == 'maintenance' || $status == 'out_of_service') $status_color = 'danger';
                                            elseif ($status == 'assigned') $status_color = 'primary';
                                            elseif (in_array($status, ['fueling', 'loading', 'unloading'])) $status_color = 'info';
                                            else $status_color = 'secondary';
                                            
                                            echo '<div class="mb-2 pb-2 border-bottom">';
                                            echo '<div class="d-flex justify-content-between">';
                                            echo '<span class="badge bg-' . $status_color . '">' . 
                                                 ucfirst(str_replace('_', ' ', $status)) . '</span>';
                                            echo '<span class="text-muted">' . date('H:i', strtotime($history['changed_at'])) . '</span>';
                                            echo '</div>';
                                            if ($history['location']) {
                                                echo '<div class="text-truncate text-muted mt-1" style="max-width: 200px;" 
                                                      title="' . htmlspecialchars($history['location']) . '">';
                                                echo '<i class="fas fa-map-marker-alt me-1"></i>' . 
                                                     htmlspecialchars($history['location']);
                                                echo '</div>';
                                            }
                                            echo '</div>';
                                        }
                                    } else {
                                        echo '<div class="text-center text-muted py-3">';
                                        echo '<i class="fas fa-clock fa-2x mb-2"></i><br>';
                                        echo 'No status history found';
                                        echo '</div>';
                                    }
                                } else {
                                    echo '<div class="alert alert-info small">';
                                    echo '<i class="fas fa-info-circle me-2"></i>';
                                    echo 'Status history tracking will begin after first update';
                                    echo '</div>';
                                }
                            } catch (Exception $e) {
                                echo '<div class="text-muted small">Unable to load status history</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Status Tips -->
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-lightbulb me-2 text-info"></i>Status Guide
                        </h5>
                        
                        <div class="small">
                            <div class="mb-2">
                                <span class="badge bg-success me-2">Available</span>
                                <span>Truck is ready for new assignments</span>
                            </div>
                            <div class="mb-2">
                                <span class="badge bg-warning me-2">On Delivery</span>
                                <span>Currently delivering goods</span>
                            </div>
                            <div class="mb-2">
                                <span class="badge bg-primary me-2">Assigned</span>
                                <span>Assigned to driver but not yet on delivery</span>
                            </div>
                            <div class="mb-2">
                                <span class="badge bg-info me-2">Fueling/Loading/Unloading</span>
                                <span>Temporary operational statuses</span>
                            </div>
                            <div class="mb-2">
                                <span class="badge bg-danger me-2">Maintenance/Out of Service</span>
                                <span>Emergency status - notifies management</span>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning small mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Update your status regularly for accurate tracking and dispatching.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript for Form Handling -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Fuel level slider and input synchronization
    const fuelSlider = document.getElementById('fuelSlider');
    const fuelInput = document.getElementById('fuelInput');
    const fuelGauge = document.getElementById('fuelGauge');
    const fuelText = document.getElementById('fuelText');
    
    function updateFuelDisplay(value) {
        // Update slider and input
        if (fuelSlider) fuelSlider.value = value;
        if (fuelInput) fuelInput.value = value;
        
        // Update gauge
        if (fuelGauge) {
            fuelGauge.style.width = value + '%';
            
            // Update color based on fuel level
            if (value < 20) {
                fuelGauge.style.background = '#dc3545';
            } else if (value < 40) {
                fuelGauge.style.background = '#fd7e14';
            } else if (value < 60) {
                fuelGauge.style.background = '#ffc107';
            } else {
                fuelGauge.style.background = '#198754';
            }
        }
        
        // Update text
        if (fuelText) {
            fuelText.textContent = value + '%';
        }
    }
    
    // Initialize fuel display
    if (fuelSlider && fuelInput) {
        updateFuelDisplay(50); // Default to 50%
        
        // Slider event
        fuelSlider.addEventListener('input', function() {
            updateFuelDisplay(this.value);
        });
        
        // Input event
        fuelInput.addEventListener('input', function() {
            let value = parseInt(this.value);
            if (isNaN(value) || value < 0) value = 0;
            if (value > 100) value = 100;
            updateFuelDisplay(value);
        });
    }
    
    // Form validation
    const form = document.getElementById('statusUpdateForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const status = document.querySelector('input[name="status"]:checked');
            const location = document.querySelector('input[name="location"]');
            
            let errors = [];
            
            if (!status) {
                errors.push('Please select a new status');
            }
            
            if (!location.value.trim()) {
                errors.push('Please provide your current location');
                location.classList.add('is-invalid');
            } else {
                location.classList.remove('is-invalid');
            }
            
            // Validate fuel level if provided
            const fuelLevel = document.querySelector('input[name="fuel_level"]');
            if (fuelLevel.value) {
                const fuelValue = parseInt(fuelLevel.value);
                if (isNaN(fuelValue) || fuelValue < 0 || fuelValue > 100) {
                    errors.push('Fuel level must be between 0 and 100');
                    fuelLevel.classList.add('is-invalid');
                } else {
                    fuelLevel.classList.remove('is-invalid');
                }
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Confirm emergency status changes
            if (status.value === 'maintenance' || status.value === 'out_of_service') {
                if (!confirm('WARNING: You are setting an EMERGENCY status. This will notify management immediately.\n\nAre you sure you want to proceed?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Show loading indicator
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Updating...';
            submitBtn.disabled = true;
            
            // Re-enable button after 3 seconds if form doesn't submit
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });
    }
    
    // Status badge selection styling
    document.querySelectorAll('.status-badge').forEach(badge => {
        badge.addEventListener('click', function() {
            document.querySelectorAll('.status-badge').forEach(b => {
                b.classList.remove('active');
                b.style.opacity = '0.7';
            });
            this.classList.add('active');
            this.style.opacity = '1';
            
            // Highlight emergency statuses
            const status = this.querySelector('input').value;
            if (status === 'maintenance' || status === 'out_of_service') {
                this.classList.add('pulse-animation');
            }
        });
    });
    
    // Auto-detect location using geolocation
    const locationInput = document.querySelector('input[name="location"]');
    const detectLocationBtn = document.createElement('button');
    detectLocationBtn.type = 'button';
    detectLocationBtn.className = 'btn btn-sm btn-outline-primary mt-1';
    detectLocationBtn.innerHTML = '<i class="fas fa-location-crosshairs me-1"></i> Detect Current Location';
    
    if (locationInput) {
        locationInput.parentNode.appendChild(detectLocationBtn);
        
        detectLocationBtn.addEventListener('click', function() {
            if (navigator.geolocation) {
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Detecting...';
                this.disabled = true;
                
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        // Use reverse geocoding API (using OpenStreetMap Nominatim)
                        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                            .then(response => response.json())
                            .then(data => {
                                let locationText = '';
                                if (data.display_name) {
                                    locationText = data.display_name.split(',').slice(0, 3).join(', ');
                                } else {
                                    locationText = `GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                                }
                                
                                locationInput.value = locationText;
                                detectLocationBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Location Detected';
                                
                                setTimeout(() => {
                                    detectLocationBtn.innerHTML = '<i class="fas fa-location-crosshairs me-1"></i> Detect Current Location';
                                    detectLocationBtn.disabled = false;
                                }, 2000);
                            })
                            .catch(error => {
                                console.error('Geocoding error:', error);
                                locationInput.value = `GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                                detectLocationBtn.innerHTML = '<i class="fas fa-location-crosshairs me-1"></i> Detect Current Location';
                                detectLocationBtn.disabled = false;
                            });
                    },
                    function(error) {
                        console.log('Geolocation error:', error);
                        alert('Unable to detect location. Please enter manually.');
                        detectLocationBtn.innerHTML = '<i class="fas fa-location-crosshairs me-1"></i> Detect Current Location';
                        detectLocationBtn.disabled = false;
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        });
    }
});

function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('statusUpdateForm').reset();
        
        // Reset fuel display to default
        const fuelGauge = document.getElementById('fuelGauge');
        const fuelText = document.getElementById('fuelText');
        
        if (fuelGauge) {
            fuelGauge.style.width = '50%';
            fuelGauge.style.background = '#198754';
        }
        
        if (fuelText) {
            fuelText.textContent = '50%';
        }
        
        // Reset status badges
        document.querySelectorAll('.status-badge').forEach(badge => {
            badge.classList.remove('active');
            badge.style.opacity = '1';
            badge.classList.remove('pulse-animation');
        });
    }
}

// Add CSS for pulse animation
const style = document.createElement('style');
style.textContent = `
@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}
.pulse-animation {
    animation: pulse 1s infinite;
}
`;
document.head.appendChild(style);
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>