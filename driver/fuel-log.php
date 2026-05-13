<?php
// fuel-log.php - Log Fuel Usage for Truck
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

$pageTitle = "Log Fuel Usage - Driver Dashboard";
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
    // Verify the truck belongs to this driver
    $truck_stmt = $db->prepare("
        SELECT t.*, d.user_id as driver_user_id, 
               c.name as courier_name,
               u.name as driver_name,
               t.plate_number,
               t.brand,
               t.model,
               t.fuel_type,
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
        $error = "Truck not found or you don't have permission to log fuel for this truck.";
    }
} catch (Exception $e) {
    error_log("Fuel log error: " . $e->getMessage());
    $error = "Error loading truck information: " . $e->getMessage();
}

// Check if fuel_records table exists, if not create it
try {
    $table_check = $db->prepare("SHOW TABLES LIKE 'fuel_records'");
    $table_check->execute();
    $table_exists = $table_check->fetch();
    
    if (!$table_exists) {
        // Create fuel_records table
        $create_table = $db->prepare("
            CREATE TABLE IF NOT EXISTS fuel_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                truck_id INT NOT NULL,
                driver_id INT NOT NULL,
                fuel_date DATE NOT NULL,
                fuel_time TIME NOT NULL,
                fuel_amount DECIMAL(10,2) NOT NULL,
                fuel_price_per_liter DECIMAL(10,2) NOT NULL,
                cost DECIMAL(10,2) NOT NULL,
                odometer INT,
                fuel_station VARCHAR(255),
                location VARCHAR(255),
                receipt_number VARCHAR(100),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE,
                FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $create_table->execute();
    }
} catch (Exception $e) {
    error_log("Fuel records table check error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_fuel'])) {
    try {
        $fuel_date = isset($_POST['fuel_date']) ? trim($_POST['fuel_date']) : '';
        $fuel_time = isset($_POST['fuel_time']) ? trim($_POST['fuel_time']) : '';
        $fuel_amount = isset($_POST['fuel_amount']) ? trim($_POST['fuel_amount']) : '';
        $price_per_liter = isset($_POST['price_per_liter']) ? trim($_POST['price_per_liter']) : '';
        $odometer = isset($_POST['odometer']) ? trim($_POST['odometer']) : '';
        $fuel_station = isset($_POST['fuel_station']) ? trim($_POST['fuel_station']) : '';
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $receipt_number = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        // Validate inputs
        if (empty($fuel_date)) {
            throw new Exception("Please enter the fuel date.");
        }
        
        if (empty($fuel_time)) {
            throw new Exception("Please enter the fuel time.");
        }
        
        if (empty($fuel_amount) || $fuel_amount <= 0) {
            throw new Exception("Please enter a valid fuel amount (must be greater than 0).");
        }
        
        if (empty($price_per_liter) || $price_per_liter <= 0) {
            throw new Exception("Please enter a valid price per liter (must be greater than 0).");
        }
        
        // Calculate cost
        $cost = $fuel_amount * $price_per_liter;
        
        // Get driver ID from drivers table
        $driver_stmt = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
        $driver_stmt->execute([$driver_id]);
        $driver_record = $driver_stmt->fetch();
        
        if (!$driver_record) {
            throw new Exception("Driver record not found.");
        }
        
        $driver_db_id = $driver_record['id'];
        
        // Insert fuel record
        $insert_stmt = $db->prepare("
            INSERT INTO fuel_records 
            (truck_id, driver_id, fuel_date, fuel_time, fuel_amount, fuel_price_per_liter, 
             cost, odometer, fuel_station, location, receipt_number, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insert_stmt->execute([
            $truck_id,
            $driver_db_id,
            $fuel_date,
            $fuel_time,
            $fuel_amount,
            $price_per_liter,
            $cost,
            $odometer ?: null,
            $fuel_station ?: null,
            $location ?: null,
            $receipt_number ?: null,
            $notes ?: null
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
            
            $activity_msg = "Driver {$truck_info['driver_name']} logged fuel for truck {$truck_info['plate_number']}: {$fuel_amount}L at KSh {$price_per_liter}/L";
            $activity_stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent, created_at)
                VALUES (?, 'fuel_log', ?, ?, ?, NOW())
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
        
        $success = "Fuel usage logged successfully! Total cost: KSh " . number_format($cost, 2);
        
        // Clear form fields on success
        if ($success) {
            $_POST = array();
        }
        
    } catch (Exception $e) {
        $error = "Error logging fuel: " . $e->getMessage();
    }
}

// Get recent fuel logs for this truck
$recent_fuel_logs = [];
try {
    $logs_stmt = $db->prepare("
        SELECT 
            fr.*,
            DATE_FORMAT(fr.fuel_date, '%d %b %Y') as fuel_date_formatted,
            DATE_FORMAT(fr.fuel_time, '%H:%i') as fuel_time_formatted,
            FORMAT(fr.fuel_amount, 2) as fuel_amount_formatted,
            FORMAT(fr.fuel_price_per_liter, 2) as price_formatted,
            FORMAT(fr.cost, 2) as cost_formatted
        FROM fuel_records fr
        WHERE fr.truck_id = ?
        ORDER BY fr.fuel_date DESC, fr.fuel_time DESC
        LIMIT 10
    ");
    $logs_stmt->execute([$truck_id]);
    $recent_fuel_logs = $logs_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Recent fuel logs error: " . $e->getMessage());
}

// Get fuel statistics
$fuel_stats = [];
try {
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_logs,
            SUM(fuel_amount) as total_fuel,
            AVG(fuel_price_per_liter) as avg_price,
            SUM(cost) as total_cost,
            MIN(fuel_date) as first_log,
            MAX(fuel_date) as last_log
        FROM fuel_records 
        WHERE truck_id = ?
    ");
    $stats_stmt->execute([$truck_id]);
    $fuel_stats = $stats_stmt->fetch();
} catch (Exception $e) {
    error_log("Fuel stats error: " . $e->getMessage());
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

/* Fuel log specific styles */
.fuel-stats-card {
    border-left: 4px solid #3b82f6;
}

.fuel-form-card {
    border-left: 4px solid #10b981;
}

.fuel-history-card {
    border-left: 4px solid #f59e0b;
}

/* Fuel price indicator */
.price-indicator {
    font-size: 0.9rem;
    padding: 0.3rem 0.6rem;
    border-radius: 4px;
    font-weight: 600;
}

.price-low {
    background-color: #d1fae5;
    color: #065f46;
}

.price-medium {
    background-color: #fef3c7;
    color: #92400e;
}

.price-high {
    background-color: #fee2e2;
    color: #991b1b;
}

/* Cost display */
.cost-display {
    font-size: 1.5rem;
    font-weight: bold;
    color: #059669;
}

/* Responsive table */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Form input groups */
.input-group-text {
    background-color: #f8f9fa;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-group {
        flex-wrap: wrap;
    }
    
    .btn-group .btn {
        margin-bottom: 5px;
    }
}
</style>

<!-- Driver Container -->
<div class="driver-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #10b981;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-gas-pump me-2"></i>Log Fuel Usage
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    <?php if (!empty($truck_info)): ?>
                        Logging fuel for: <strong><?php echo htmlspecialchars($truck_info['plate_number']); ?></strong>
                        (<?php echo htmlspecialchars($truck_info['brand'] ?? ''); ?> <?php echo htmlspecialchars($truck_info['model'] ?? ''); ?>)
                    <?php else: ?>
                        Fuel Usage Logging
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
            <!-- Left Column: Fuel Log Form -->
            <div class="col-lg-8">
                <div class="card fuel-form-card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <form method="POST" action="" id="fuelLogForm">
                            <!-- Truck Information -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-truck me-2 text-primary"></i>Truck Information
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Plate Number</label>
                                        <div class="form-control bg-light"><?php echo htmlspecialchars($truck_info['plate_number']); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Fuel Type</label>
                                        <div class="form-control bg-light">
                                            <?php echo !empty($truck_info['fuel_type']) ? ucfirst($truck_info['fuel_type']) : 'Not specified'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Fuel Details -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-gas-pump me-2 text-success"></i>Fuel Details
                                </h5>
                                
                                <div class="row">
                                    <!-- Date and Time -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required">
                                            <i class="fas fa-calendar me-1"></i>Fuel Date
                                        </label>
                                        <input type="date" class="form-control" name="fuel_date" 
                                               value="<?php echo date('Y-m-d'); ?>"
                                               max="<?php echo date('Y-m-d'); ?>"
                                               required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required">
                                            <i class="fas fa-clock me-1"></i>Fuel Time
                                        </label>
                                        <input type="time" class="form-control" name="fuel_time" 
                                               value="<?php echo date('H:i'); ?>"
                                               required>
                                    </div>
                                    
                                    <!-- Fuel Amount -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required">
                                            <i class="fas fa-oil-can me-1"></i>Fuel Amount (Liters)
                                        </label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="fuel_amount" 
                                                   step="0.01" min="0.01" max="1000"
                                                   value="<?php echo isset($_POST['fuel_amount']) ? htmlspecialchars($_POST['fuel_amount']) : ''; ?>"
                                                   placeholder="e.g., 45.50"
                                                   required>
                                            <span class="input-group-text">L</span>
                                        </div>
                                        <small class="text-muted">
                                            Enter the amount of fuel added in liters
                                        </small>
                                    </div>
                                    
                                    <!-- Price per Liter -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label required">
                                            <i class="fas fa-money-bill-wave me-1"></i>Price per Liter (KSh)
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">KSh</span>
                                            <input type="number" class="form-control" name="price_per_liter" 
                                                   step="0.01" min="0.01" max="500"
                                                   value="<?php echo isset($_POST['price_per_liter']) ? htmlspecialchars($_POST['price_per_liter']) : ''; ?>"
                                                   placeholder="e.g., 180.50"
                                                   required>
                                            <span class="input-group-text">/L</span>
                                        </div>
                                        <div class="mt-2">
                                            <small class="text-muted">Current fuel prices in Kenya:</small>
                                            <div class="btn-group btn-group-sm mt-1" role="group">
                                                <button type="button" class="btn btn-outline-primary" onclick="setPrice(175.50)">Super: 175.50</button>
                                                <button type="button" class="btn btn-outline-success" onclick="setPrice(162.00)">Diesel: 162.00</button>
                                                <button type="button" class="btn btn-outline-warning" onclick="setPrice(160.50)">Kerosene: 160.50</button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Cost Calculation -->
                                    <div class="col-12 mb-3">
                                        <div class="alert alert-info">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong>Estimated Cost:</strong>
                                                    <span id="estimatedCost" class="cost-display ms-2">KSh 0.00</span>
                                                </div>
                                                <div>
                                                    <small class="text-muted">Calculated automatically</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Information -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-info-circle me-2 text-info"></i>Additional Information
                                </h5>
                                
                                <div class="row">
                                    <!-- Odometer Reading -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-tachometer-alt me-1"></i>Odometer Reading (km)
                                        </label>
                                        <input type="number" class="form-control" name="odometer" 
                                               value="<?php echo isset($_POST['odometer']) ? htmlspecialchars($_POST['odometer']) : ''; ?>"
                                               placeholder="Current odometer reading">
                                        <small class="text-muted">
                                            Optional: For calculating fuel efficiency
                                        </small>
                                    </div>
                                    
                                    <!-- Fuel Station -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-store me-1"></i>Fuel Station
                                        </label>
                                        <input type="text" class="form-control" name="fuel_station" 
                                               value="<?php echo isset($_POST['fuel_station']) ? htmlspecialchars($_POST['fuel_station']) : ''; ?>"
                                               placeholder="e.g., Shell, Total, KenolKobil">
                                        <small class="text-muted">
                                            Name of the fuel station
                                        </small>
                                    </div>
                                    
                                    <!-- Location -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-map-marker-alt me-1"></i>Location
                                        </label>
                                        <input type="text" class="form-control" name="location" 
                                               value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : $truck_info['current_location']; ?>"
                                               placeholder="Location of fuel station">
                                        <small class="text-muted">
                                            Town or area where you fueled
                                        </small>
                                    </div>
                                    
                                    <!-- Receipt Number -->
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-receipt me-1"></i>Receipt Number
                                        </label>
                                        <input type="text" class="form-control" name="receipt_number" 
                                               value="<?php echo isset($_POST['receipt_number']) ? htmlspecialchars($_POST['receipt_number']) : ''; ?>"
                                               placeholder="Receipt number (optional)">
                                        <small class="text-muted">
                                            For record keeping and reimbursement
                                        </small>
                                    </div>
                                    
                                    <!-- Notes -->
                                    <div class="col-12 mb-3">
                                        <label class="form-label">
                                            <i class="fas fa-sticky-note me-1"></i>Notes
                                        </label>
                                        <textarea class="form-control" name="notes" rows="3" 
                                                  placeholder="Any additional notes about this fuel purchase..."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                                        <small class="text-muted">
                                            Optional: Payment method, special conditions, etc.
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
                                    <button type="submit" name="log_fuel" class="btn btn-success px-4">
                                        <i class="fas fa-save me-2"></i> Log Fuel Usage
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Stats & History -->
            <div class="col-lg-4">
                <!-- Fuel Statistics Card -->
                <div class="card fuel-stats-card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-chart-bar me-2"></i>Fuel Statistics
                        </h5>
                        
                        <?php if (!empty($fuel_stats) && $fuel_stats['total_logs'] > 0): ?>
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="display-6 text-primary mb-1"><?php echo $fuel_stats['total_logs']; ?></div>
                                        <small class="text-muted">Total Logs</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="display-6 text-success mb-1"><?php echo number_format($fuel_stats['total_fuel'] ?? 0, 1); ?></div>
                                        <small class="text-muted">Total Fuel (L)</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="display-6 text-warning mb-1">KSh <?php echo number_format($fuel_stats['avg_price'] ?? 0, 2); ?></div>
                                        <small class="text-muted">Avg Price/L</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="text-center">
                                        <div class="display-6 text-danger mb-1">KSh <?php echo number_format($fuel_stats['total_cost'] ?? 0, 2); ?></div>
                                        <small class="text-muted">Total Cost</small>
                                    </div>
                                </div>
                            </div>
                            <div class="small text-muted">
                                <div class="d-flex justify-content-between">
                                    <span>First Log:</span>
                                    <span><?php echo date('d M Y', strtotime($fuel_stats['first_log'])); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mt-1">
                                    <span>Last Log:</span>
                                    <span><?php echo date('d M Y', strtotime($fuel_stats['last_log'])); ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No fuel logs yet</p>
                                <small class="text-muted">Statistics will appear after first log</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Fuel History -->
                <div class="card fuel-history-card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-history me-2"></i>Recent Fuel History
                        </h5>
                        
                        <div class="small">
                            <?php if (!empty($recent_fuel_logs)): ?>
                                <?php foreach ($recent_fuel_logs as $log): ?>
                                    <div class="mb-3 pb-3 border-bottom">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <strong><?php echo $log['fuel_date_formatted']; ?></strong>
                                            <span class="text-success"><?php echo $log['fuel_amount_formatted']; ?> L</span>
                                        </div>
                                        <div class="d-flex justify-content-between text-muted">
                                            <span><?php echo $log['fuel_time_formatted']; ?></span>
                                            <span>KSh <?php echo $log['cost_formatted']; ?></span>
                                        </div>
                                        <?php if ($log['fuel_station']): ?>
                                            <div class="text-truncate mt-1" style="max-width: 200px;" 
                                                 title="<?php echo htmlspecialchars($log['fuel_station']); ?>">
                                                <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($log['fuel_station']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="text-center mt-3">
                                    <a href="fuel-history.php?truck_id=<?php echo $truck_id; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-list me-1"></i> View Full History
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-gas-pump fa-2x text-muted mb-2"></i>
                                    <p class="text-muted mb-0">No recent fuel logs</p>
                                    <small class="text-muted">Log your first fuel entry above</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Fuel Tips Card -->
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-lightbulb me-2 text-warning"></i>Fuel Logging Tips
                        </h5>
                        
                        <div class="small">
                            <div class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Always get a receipt</strong> for reimbursement
                            </div>
                            <div class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Log fuel immediately</strong> after refueling
                            </div>
                            <div class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Record odometer</strong> for fuel efficiency tracking
                            </div>
                            <div class="mb-2">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <strong>Note special conditions</strong> (night fuel, discounts, etc.)
                            </div>
                        </div>
                        
                        <div class="alert alert-info small mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Tip:</strong> Regular fuel logging helps track vehicle performance and costs.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Fuel History Table (for larger screens) -->
        <?php if (!empty($recent_fuel_logs)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-table me-2"></i>Detailed Fuel History
                        </h5>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Fuel (L)</th>
                                        <th>Price/L</th>
                                        <th>Cost</th>
                                        <th>Odometer</th>
                                        <th>Station</th>
                                        <th>Location</th>
                                        <th>Receipt #</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_fuel_logs as $log): ?>
                                        <tr>
                                            <td><?php echo $log['fuel_date_formatted']; ?></td>
                                            <td><?php echo $log['fuel_time_formatted']; ?></td>
                                            <td class="text-end"><?php echo $log['fuel_amount_formatted']; ?></td>
                                            <td class="text-end">KSh <?php echo $log['price_formatted']; ?></td>
                                            <td class="text-end fw-bold text-success">KSh <?php echo $log['cost_formatted']; ?></td>
                                            <td class="text-end"><?php echo $log['odometer'] ? number_format($log['odometer']) : '-'; ?></td>
                                            <td><?php echo $log['fuel_station'] ? htmlspecialchars($log['fuel_station']) : '-'; ?></td>
                                            <td><?php echo $log['location'] ? htmlspecialchars($log['location']) : '-'; ?></td>
                                            <td><?php echo $log['receipt_number'] ? htmlspecialchars($log['receipt_number']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
    <?php endif; ?>
</div>

<!-- JavaScript for Form Handling -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Calculate estimated cost in real-time
    const fuelAmountInput = document.querySelector('input[name="fuel_amount"]');
    const pricePerLiterInput = document.querySelector('input[name="price_per_liter"]');
    const estimatedCostElement = document.getElementById('estimatedCost');
    
    function calculateCost() {
        const fuelAmount = parseFloat(fuelAmountInput.value) || 0;
        const pricePerLiter = parseFloat(pricePerLiterInput.value) || 0;
        const cost = fuelAmount * pricePerLiter;
        
        if (cost > 0) {
            estimatedCostElement.textContent = 'KSh ' + cost.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        } else {
            estimatedCostElement.textContent = 'KSh 0.00';
        }
    }
    
    // Add event listeners for real-time calculation
    if (fuelAmountInput && pricePerLiterInput) {
        fuelAmountInput.addEventListener('input', calculateCost);
        pricePerLiterInput.addEventListener('input', calculateCost);
        
        // Initial calculation
        calculateCost();
    }
    
    // Form validation
    const form = document.getElementById('fuelLogForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const fuelAmount = document.querySelector('input[name="fuel_amount"]');
            const pricePerLiter = document.querySelector('input[name="price_per_liter"]');
            
            let errors = [];
            
            if (!fuelAmount.value || parseFloat(fuelAmount.value) <= 0) {
                errors.push('Please enter a valid fuel amount (greater than 0)');
                fuelAmount.classList.add('is-invalid');
            } else {
                fuelAmount.classList.remove('is-invalid');
            }
            
            if (!pricePerLiter.value || parseFloat(pricePerLiter.value) <= 0) {
                errors.push('Please enter a valid price per liter (greater than 0)');
                pricePerLiter.classList.add('is-invalid');
            } else {
                pricePerLiter.classList.remove('is-invalid');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Show loading indicator
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Logging...';
            submitBtn.disabled = true;
            
            // Re-enable button after 3 seconds if form doesn't submit
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });
    }
    
    // Set default location based on truck's current location
    const locationInput = document.querySelector('input[name="location"]');
    if (locationInput && !locationInput.value) {
        locationInput.value = '<?php echo htmlspecialchars($truck_info["current_location"] ?? ""); ?>';
    }
});

// Function to set price from button clicks
function setPrice(price) {
    const priceInput = document.querySelector('input[name="price_per_liter"]');
    if (priceInput) {
        priceInput.value = price;
        
        // Trigger cost calculation
        const event = new Event('input');
        priceInput.dispatchEvent(event);
        
        // Show feedback
        const originalValue = priceInput.value;
        priceInput.style.backgroundColor = '#d1fae5';
        setTimeout(() => {
            priceInput.style.backgroundColor = '';
        }, 1000);
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('fuelLogForm').reset();
        
        // Reset cost display
        const estimatedCostElement = document.getElementById('estimatedCost');
        if (estimatedCostElement) {
            estimatedCostElement.textContent = 'KSh 0.00';
        }
        
        // Set default date and time
        const dateInput = document.querySelector('input[name="fuel_date"]');
        const timeInput = document.querySelector('input[name="fuel_time"]');
        if (dateInput) dateInput.value = '<?php echo date("Y-m-d"); ?>';
        if (timeInput) timeInput.value = '<?php echo date("H:i"); ?>';
        
        // Reset location to truck's current location
        const locationInput = document.querySelector('input[name="location"]');
        if (locationInput) {
            locationInput.value = '<?php echo htmlspecialchars($truck_info["current_location"] ?? ""); ?>';
        }
    }
}

// Auto-format currency inputs
document.querySelectorAll('input[type="number"]').forEach(input => {
    input.addEventListener('blur', function() {
        if (this.value && !isNaN(this.value)) {
            this.value = parseFloat(this.value).toFixed(2);
        }
    });
});

// Quick fill buttons for common fuel amounts
const quickFillButtons = document.createElement('div');
quickFillButtons.className = 'btn-group btn-group-sm mt-2';
quickFillButtons.innerHTML = `
    <button type="button" class="btn btn-outline-secondary" onclick="setFuelAmount(20)">20L</button>
    <button type="button" class="btn btn-outline-secondary" onclick="setFuelAmount(40)">40L</button>
    <button type="button" class="btn btn-outline-secondary" onclick="setFuelAmount(60)">60L</button>
    <button type="button" class="btn btn-outline-secondary" onclick="setFuelAmount(80)">80L</button>
    <button type="button" class="btn btn-outline-secondary" onclick="setFuelAmount(100)">100L</button>
`;

const fuelAmountGroup = document.querySelector('input[name="fuel_amount"]').parentNode;
fuelAmountGroup.appendChild(quickFillButtons);

function setFuelAmount(amount) {
    const fuelInput = document.querySelector('input[name="fuel_amount"]');
    if (fuelInput) {
        fuelInput.value = amount;
        
        // Trigger cost calculation
        const event = new Event('input');
        fuelInput.dispatchEvent(event);
        
        // Show feedback
        fuelInput.style.backgroundColor = '#dbeafe';
        setTimeout(() => {
            fuelInput.style.backgroundColor = '';
        }, 1000);
    }
}
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>