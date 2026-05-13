<?php
// trucks-edit.php
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

// Get truck ID from URL
$truck_id = $_GET['id'] ?? 0;
if (!$truck_id) {
    header('Location: trucks.php');
    exit();
}

// Initialize variables
$truck = null;
$error = '';
$success = '';

// Get truck details
try {
    $query = "SELECT 
                t.*, 
                c.name as courier_name,
                u.name as driver_name,
                d.license_number
              FROM trucks t
              LEFT JOIN couriers c ON t.courier_id = c.id
              LEFT JOIN drivers d ON t.driver_id = d.id
              LEFT JOIN users u ON d.user_id = u.id
              WHERE t.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$truck_id]);
    $truck = $stmt->fetch();
    
    if (!$truck) {
        header('Location: trucks.php?error=truck_not_found');
        exit();
    }
    
} catch (Exception $e) {
    $error = "Error loading truck details: " . $e->getMessage();
    error_log("Truck edit error: " . $e->getMessage());
}

// Get available drivers for dropdown
$drivers = [];
try {
    $driver_query = "SELECT d.id, u.name, d.license_number, d.status 
                     FROM drivers d 
                     INNER JOIN users u ON d.user_id = u.id 
                     WHERE d.status IN ('available', 'on_delivery')
                     ORDER BY u.name";
    $driver_stmt = $db->query($driver_query);
    $drivers = $driver_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching drivers: " . $e->getMessage());
}

// Get available couriers for dropdown
$couriers = [];
try {
    $courier_query = "SELECT id, name FROM couriers WHERE status = 'active' ORDER BY name";
    $courier_stmt = $db->query($courier_query);
    $couriers = $courier_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching couriers: " . $e->getMessage());
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $plate_number = trim($_POST['plate_number'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $year = $_POST['year'] ?? null;
    $capacity = trim($_POST['capacity'] ?? '');
    $fuel_type = $_POST['fuel_type'] ?? 'diesel';
    $insurance_number = trim($_POST['insurance_number'] ?? '');
    $insurance_expiry = $_POST['insurance_expiry'] ?? null;
    $last_maintenance = $_POST['last_maintenance'] ?? null;
    $status = $_POST['status'] ?? 'available';
    $current_location = trim($_POST['current_location'] ?? '');
    $courier_id = $_POST['courier_id'] ?? null;
    $driver_id = $_POST['driver_id'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Basic validation
    if (empty($plate_number) || empty($model) || empty($capacity) || empty($courier_id)) {
        $error = "Plate number, model, capacity, and courier are required fields.";
    } else {
        try {
            // Check if plate number already exists (excluding current truck)
            $check_query = "SELECT id FROM trucks WHERE plate_number = ? AND id != ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$plate_number, $truck_id]);
            
            if ($check_stmt->fetch()) {
                $error = "A truck with this plate number already exists.";
            } else {
                // Get current driver_id before update
                $current_driver_query = "SELECT driver_id FROM trucks WHERE id = ?";
                $current_driver_stmt = $db->prepare($current_driver_query);
                $current_driver_stmt->execute([$truck_id]);
                $current_driver = $current_driver_stmt->fetch()['driver_id'];
                
                // Start transaction
                $db->beginTransaction();
                
                // Update truck information
                $update_query = "UPDATE trucks SET 
                                plate_number = ?, 
                                model = ?, 
                                brand = ?, 
                                year = ?, 
                                capacity = ?, 
                                fuel_type = ?, 
                                insurance_number = ?, 
                                insurance_expiry = ?, 
                                last_maintenance = ?, 
                                status = ?, 
                                current_location = ?, 
                                courier_id = ?, 
                                driver_id = ?, 
                                notes = ?, 
                                updated_at = NOW()
                                WHERE id = ?";
                
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([
                    $plate_number,
                    $model,
                    $brand,
                    $year ?: null,
                    $capacity,
                    $fuel_type,
                    $insurance_number,
                    $insurance_expiry ? date('Y-m-d', strtotime($insurance_expiry)) : null,
                    $last_maintenance ? date('Y-m-d', strtotime($last_maintenance)) : null,
                    $status,
                    $current_location,
                    $courier_id,
                    $driver_id ?: null,
                    $notes,
                    $truck_id
                ]);
                
                // Handle driver status updates
                if ($current_driver != $driver_id) {
                    // Update old driver status if unassigned
                    if ($current_driver) {
                        $old_driver_query = "UPDATE drivers SET status = 'available' WHERE id = ?";
                        $old_driver_stmt = $db->prepare($old_driver_query);
                        $old_driver_stmt->execute([$current_driver]);
                    }
                    
                    // Update new driver status if assigned
                    if ($driver_id) {
                        $new_driver_query = "UPDATE drivers SET status = 'on_delivery' WHERE id = ?";
                        $new_driver_stmt = $db->prepare($new_driver_query);
                        $new_driver_stmt->execute([$driver_id]);
                    }
                } else {
                    // Same driver, update status based on truck status
                    if ($driver_id) {
                        $driver_status = ($status == 'on_delivery') ? 'on_delivery' : 'available';
                        $same_driver_query = "UPDATE drivers SET status = ? WHERE id = ?";
                        $same_driver_stmt = $db->prepare($same_driver_query);
                        $same_driver_stmt->execute([$driver_status, $driver_id]);
                    }
                }
                
                // Commit transaction
                $db->commit();
                
                $success = "Truck information updated successfully!";
                
                // Refresh truck data
                $stmt->execute([$truck_id]);
                $truck = $stmt->fetch();
                
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating truck: " . $e->getMessage();
            error_log("Truck update error: " . $e->getMessage());
        }
    }
}

$pageTitle = "Edit Truck - " . ($truck['plate_number'] ?? 'Unknown Truck');

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
                    <i class="fas fa-truck me-2"></i>Edit Truck
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Update truck information and settings
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="trucks.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Trucks
                </a>
                <a href="trucks-view.php?id=<?php echo $truck_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye me-2"></i> View Truck
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($truck): ?>
        <div class="row">
            <div class="col-md-8">
                <!-- Edit Form -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <form method="POST" action="" id="truckEditForm">
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-truck-loading me-2"></i>Truck Information
                            </h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="plate_number" class="form-label">Plate Number *</label>
                                    <input type="text" class="form-control" id="plate_number" name="plate_number" 
                                           value="<?php echo htmlspecialchars($truck['plate_number']); ?>" 
                                           required 
                                           placeholder="e.g., ABC-1234">
                                    <div class="form-text">Enter the official license plate number</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="model" class="form-label">Truck Model *</label>
                                    <input type="text" class="form-control" id="model" name="model" 
                                           value="<?php echo htmlspecialchars($truck['model']); ?>" 
                                           required 
                                           placeholder="e.g., FH16, Actros">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="brand" class="form-label">Brand</label>
                                    <input type="text" class="form-control" id="brand" name="brand" 
                                           value="<?php echo htmlspecialchars($truck['brand'] ?? ''); ?>" 
                                           placeholder="e.g., Volvo, Mercedes">
                                </div>
                                <div class="col-md-6">
                                    <label for="year" class="form-label">Manufacturing Year</label>
                                    <input type="number" class="form-control" id="year" name="year" 
                                           value="<?php echo htmlspecialchars($truck['year'] ?? ''); ?>" 
                                           min="1900" 
                                           max="<?php echo date('Y'); ?>"
                                           placeholder="e.g., 2023">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="capacity" class="form-label">Capacity (tons) *</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="capacity" name="capacity" 
                                               value="<?php echo htmlspecialchars($truck['capacity']); ?>" 
                                               required 
                                               min="1" 
                                               max="100"
                                               step="0.01"
                                               placeholder="e.g., 15.5">
                                        <span class="input-group-text">tons</span>
                                    </div>
                                    <div class="form-text">Maximum load capacity in tons</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="fuel_type" class="form-label">Fuel Type</label>
                                    <select class="form-select" id="fuel_type" name="fuel_type">
                                        <option value="diesel" <?php echo $truck['fuel_type'] == 'diesel' ? 'selected' : ''; ?>>Diesel</option>
                                        <option value="petrol" <?php echo $truck['fuel_type'] == 'petrol' ? 'selected' : ''; ?>>Petrol</option>
                                        <option value="electric" <?php echo $truck['fuel_type'] == 'electric' ? 'selected' : ''; ?>>Electric</option>
                                        <option value="hybrid" <?php echo $truck['fuel_type'] == 'hybrid' ? 'selected' : ''; ?>>Hybrid</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="current_location" class="form-label">Current Location</label>
                                <input type="text" class="form-control" id="current_location" name="current_location" 
                                       value="<?php echo htmlspecialchars($truck['current_location'] ?? ''); ?>"
                                       placeholder="e.g., Main Depot, Warehouse A">
                            </div>
                            
                            <hr class="my-4">
                            
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-shield-alt me-2"></i>Insurance Information
                            </h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="insurance_number" class="form-label">Insurance Number</label>
                                    <input type="text" class="form-control" id="insurance_number" name="insurance_number" 
                                           value="<?php echo htmlspecialchars($truck['insurance_number'] ?? ''); ?>" 
                                           placeholder="e.g., INS-2024-001">
                                </div>
                                <div class="col-md-6">
                                    <label for="insurance_expiry" class="form-label">Insurance Expiry Date</label>
                                    <input type="date" class="form-control" id="insurance_expiry" name="insurance_expiry" 
                                           value="<?php echo $truck['insurance_expiry'] ? date('Y-m-d', strtotime($truck['insurance_expiry'])) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="last_maintenance" class="form-label">Last Maintenance Date</label>
                                    <input type="date" class="form-control" id="last_maintenance" name="last_maintenance" 
                                           value="<?php echo $truck['last_maintenance'] ? date('Y-m-d', strtotime($truck['last_maintenance'])) : ''; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="status" class="form-label">Truck Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="available" <?php echo $truck['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                        <option value="assigned" <?php echo $truck['status'] == 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                        <option value="on_delivery" <?php echo $truck['status'] == 'on_delivery' ? 'selected' : ''; ?>>On Delivery</option>
                                        <option value="maintenance" <?php echo $truck['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                        <option value="out_of_service" <?php echo $truck['status'] == 'out_of_service' ? 'selected' : ''; ?>>Out of Service</option>
                                    </select>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-user-tie me-2"></i>Assignment Information
                            </h5>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="driver_id" class="form-label">Assign Driver</label>
                                    <select class="form-select" id="driver_id" name="driver_id">
                                        <option value="">-- No Driver Assigned --</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?php echo $driver['id']; ?>" 
                                                    <?php echo $truck['driver_id'] == $driver['id'] ? 'selected' : ''; ?>
                                                    data-status="<?php echo $driver['status']; ?>">
                                                <?php echo htmlspecialchars($driver['name']); ?> 
                                                (<?php echo htmlspecialchars($driver['license_number']); ?>)
                                                - <?php echo ucfirst($driver['status']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Assign a driver to this truck</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="courier_id" class="form-label">Assign to Courier *</label>
                                    <select class="form-select" id="courier_id" name="courier_id" required>
                                        <option value="">-- Select Courier --</option>
                                        <?php foreach ($couriers as $courier): ?>
                                            <option value="<?php echo $courier['id']; ?>" 
                                                    <?php echo $truck['courier_id'] == $courier['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($courier['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Courier company this truck belongs to</div>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                                <i class="fas fa-sticky-note me-2"></i>Additional Information
                            </h5>
                            
                            <div class="mb-4">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4" 
                                          placeholder="Any additional information about this truck..."><?php echo htmlspecialchars($truck['notes'] ?? ''); ?></textarea>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="d-flex justify-content-between">
                                <a href="trucks-view.php?id=<?php echo $truck_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Truck Summary -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-info-circle me-2"></i>Truck Summary
                        </h5>
                        
                        <div class="text-center mb-4">
                            <div style="width: 100px; height: 100px; background: linear-gradient(135deg, #306998, #4B8BBE); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 2.5rem; font-weight: 700; margin: 0 auto;">
                                <?php 
                                    $plate_short = substr(preg_replace('/[^A-Z]/', '', strtoupper($truck['plate_number'])), 0, 3);
                                    echo $plate_short ?: 'TRK';
                                ?>
                            </div>
                            <h6 class="mt-3 mb-1"><?php echo htmlspecialchars($truck['plate_number']); ?></h6>
                            <p class="text-muted small">Truck ID: <?php echo $truck['id']; ?></p>
                        </div>
                        
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Current Status:</span>
                                <?php
                                $status_class = '';
                                switch ($truck['status']) {
                                    case 'available':
                                        $status_class = 'badge bg-success';
                                        break;
                                    case 'assigned':
                                        $status_class = 'badge bg-primary';
                                        break;
                                    case 'on_delivery':
                                        $status_class = 'badge bg-warning';
                                        break;
                                    case 'maintenance':
                                        $status_class = 'badge bg-danger';
                                        break;
                                    case 'out_of_service':
                                        $status_class = 'badge bg-secondary';
                                        break;
                                    default:
                                        $status_class = 'badge bg-light text-dark';
                                }
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $truck['status'])); ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Assigned Driver:</span>
                                <span>
                                    <?php if ($truck['driver_name']): ?>
                                        <strong><?php echo htmlspecialchars($truck['driver_name']); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Courier:</span>
                                <span>
                                    <?php if ($truck['courier_name']): ?>
                                        <?php echo htmlspecialchars($truck['courier_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Capacity:</span>
                                <strong><?php echo $truck['capacity']; ?> tons</strong>
                            </div>
                            <div class="list-group-item">
                                <small class="text-muted">Created: <?php echo date('M j, Y', strtotime($truck['created_at'])); ?></small><br>
                                <small class="text-muted">Last Updated: <?php echo date('M j, Y', strtotime($truck['updated_at'])); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Insurance Status -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-shield-alt me-2"></i>Insurance Status
                        </h5>
                        
                        <?php if ($truck['insurance_number'] && $truck['insurance_expiry']): ?>
                            <?php
                            $expiry_date = new DateTime($truck['insurance_expiry']);
                            $today = new DateTime();
                            $days_diff = $today->diff($expiry_date)->days;
                            
                            if ($days_diff <= 0) {
                                $alert_class = 'alert-danger';
                                $message = 'Expired!';
                            } elseif ($days_diff <= 30) {
                                $alert_class = 'alert-danger';
                                $message = 'Expires soon!';
                            } elseif ($days_diff <= 60) {
                                $alert_class = 'alert-warning';
                                $message = 'Expiring soon';
                            } else {
                                $alert_class = 'alert-success';
                                $message = 'Active';
                            }
                            ?>
                            <div class="alert <?php echo $alert_class; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-shield-alt me-2"></i>
                                        <strong><?php echo $message; ?></strong>
                                    </div>
                                    <span class="badge bg-<?php echo str_replace('alert-', '', $alert_class); ?>">
                                        <?php echo $days_diff; ?> days
                                    </span>
                                </div>
                                <div class="mt-2">
                                    <small>Number: <?php echo htmlspecialchars($truck['insurance_number']); ?></small><br>
                                    <small>Expiry: <?php echo date('M d, Y', strtotime($truck['insurance_expiry'])); ?></small>
                                </div>
                            </div>
                        <?php elseif ($truck['insurance_number']): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Insured</strong><br>
                                <small>Number: <?php echo htmlspecialchars($truck['insurance_number']); ?></small><br>
                                <small class="text-danger">No expiry date set</small>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>No Insurance</strong><br>
                                <small>Please add insurance information</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-link me-2"></i>Quick Actions
                        </h5>
                        <div class="d-grid gap-2">
                            <a href="trucks-view.php?id=<?php echo $truck_id; ?>" class="btn btn-outline-info">
                                <i class="fas fa-eye me-2"></i> View Truck Details
                            </a>
                            <?php if ($truck['driver_id']): ?>
                                <a href="assign-truck.php?action=reassign&truck_id=<?php echo $truck_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-exchange-alt me-2"></i> Reassign Driver
                                </a>
                            <?php else: ?>
                                <a href="assign-truck.php?truck_id=<?php echo $truck_id; ?>" class="btn btn-outline-success">
                                    <i class="fas fa-user-plus me-2"></i> Assign Driver
                                </a>
                            <?php endif; ?>
                            <a href="trucks-maintenance.php?truck_id=<?php echo $truck_id; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-tools me-2"></i> Schedule Maintenance
                            </a>
                            <a href="trucks.php?action=delete&id=<?php echo $truck_id; ?>" 
                               class="btn btn-outline-danger"
                               onclick="return confirm('Are you sure you want to delete this truck? This action cannot be undone.');">
                                <i class="fas fa-trash me-2"></i> Delete Truck
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Truck not found. Please check the truck ID and try again.
        </div>
        <div class="text-center mt-4">
            <a href="trucks.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-2"></i> Back to Trucks List
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Form validation
    document.getElementById('truckEditForm').addEventListener('submit', function(e) {
        const plateNumber = document.getElementById('plate_number').value.trim();
        const model = document.getElementById('model').value.trim();
        const capacity = document.getElementById('capacity').value;
        const courier = document.getElementById('courier_id').value;
        
        if (!plateNumber || !model || !capacity || !courier) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }
        
        if (capacity <= 0) {
            e.preventDefault();
            alert('Capacity must be greater than 0.');
            return false;
        }
        
        const year = document.getElementById('year').value;
        const currentYear = new Date().getFullYear();
        if (year && (year < 1900 || year > currentYear)) {
            e.preventDefault();
            alert('Please enter a valid year between 1900 and ' + currentYear + '.');
            return false;
        }
        
        // Warn if changing driver assignment
        const driverSelect = document.getElementById('driver_id');
        const selectedDriver = driverSelect.value;
        const selectedOption = driverSelect.options[driverSelect.selectedIndex];
        const driverStatus = selectedOption.getAttribute('data-status');
        
        if (selectedDriver && driverStatus !== 'available') {
            if (!confirm('The selected driver is currently ' + driverStatus + '. Are you sure you want to assign them to this truck?')) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Set date limits
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('insurance_expiry').min = today;
    document.getElementById('last_maintenance').max = today;

    // Calculate insurance days and update warning
    function updateInsuranceWarning() {
        const expiryDateInput = document.getElementById('insurance_expiry');
        const expiryDate = expiryDateInput.value;
        
        if (expiryDate) {
            const today = new Date();
            const expiry = new Date(expiryDate);
            const timeDiff = expiry.getTime() - today.getTime();
            const daysLeft = Math.ceil(timeDiff / (1000 * 3600 * 24));
            
            if (daysLeft <= 30) {
                alert('Warning: Insurance expires in ' + daysLeft + ' days!');
            }
        }
    }

    // Add change listener to insurance expiry
    document.getElementById('insurance_expiry').addEventListener('change', updateInsuranceWarning);
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>