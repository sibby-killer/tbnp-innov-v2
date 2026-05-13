<?php
// trucks-add.php
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

// Initialize variables
$error = '';
$success = '';
$truck_data = [
    'plate_number' => '',
    'model' => '',
    'capacity' => '',
    'status' => 'available',
    'driver_id' => '',
    'courier_id' => '',
    'insurance_expiry' => '',
    'last_maintenance' => ''
];

// Get available drivers for dropdown
$drivers = [];
try {
    $driver_query = "SELECT d.id, u.name, d.license_number 
                     FROM drivers d 
                     INNER JOIN users u ON d.user_id = u.id 
                     WHERE d.status = 'available' 
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
    $capacity = trim($_POST['capacity'] ?? '');
    $status = $_POST['status'] ?? 'available';
    $driver_id = $_POST['driver_id'] ?? null;
    $courier_id = $_POST['courier_id'] ?? null;
    $insurance_expiry = $_POST['insurance_expiry'] ?? null;
    $last_maintenance = $_POST['last_maintenance'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Basic validation
    if (empty($plate_number) || empty($model) || empty($capacity)) {
        $error = "Plate number, model, and capacity are required fields.";
    } else {
        try {
            // Check if plate number already exists
            $check_query = "SELECT id FROM trucks WHERE plate_number = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$plate_number]);
            
            if ($check_stmt->fetch()) {
                $error = "A truck with this plate number already exists.";
            } else {
                // Prepare truck data for insertion
                $truck_data = [
                    'plate_number' => $plate_number,
                    'model' => $model,
                    'capacity' => $capacity,
                    'status' => $status,
                    'driver_id' => $driver_id ?: null,
                    'courier_id' => $courier_id ?: null,
                    'insurance_expiry' => $insurance_expiry ? date('Y-m-d', strtotime($insurance_expiry)) : null,
                    'last_maintenance' => $last_maintenance ? date('Y-m-d', strtotime($last_maintenance)) : null,
                    'notes' => $notes
                ];
                
                // Insert into database
                $insert_query = "INSERT INTO trucks 
                                (plate_number, model, capacity, status, driver_id, courier_id, 
                                 insurance_expiry, last_maintenance, notes, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->execute([
                    $truck_data['plate_number'],
                    $truck_data['model'],
                    $truck_data['capacity'],
                    $truck_data['status'],
                    $truck_data['driver_id'],
                    $truck_data['courier_id'],
                    $truck_data['insurance_expiry'],
                    $truck_data['last_maintenance'],
                    $truck_data['notes']
                ]);
                
                $truck_id = $db->lastInsertId();
                
                // If driver assigned, update driver status
                if ($driver_id) {
                    $update_driver_query = "UPDATE drivers SET status = 'on_delivery' WHERE id = ?";
                    $update_driver_stmt = $db->prepare($update_driver_query);
                    $update_driver_stmt->execute([$driver_id]);
                }
                
                $success = "Truck added successfully! Truck ID: " . $truck_id;
                
                // Clear form data after successful submission
                $truck_data = [
                    'plate_number' => '',
                    'model' => '',
                    'capacity' => '',
                    'status' => 'available',
                    'driver_id' => '',
                    'courier_id' => '',
                    'insurance_expiry' => '',
                    'last_maintenance' => ''
                ];
            }
            
        } catch (Exception $e) {
            $error = "Error adding truck: " . $e->getMessage();
            error_log("Truck add error: " . $e->getMessage());
        }
    }
}

$pageTitle = "Add New Truck";

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
                    <i class="fas fa-truck me-2"></i>Add New Truck
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Register a new truck in the system
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="trucks.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Trucks
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
            <div class="mt-2">
                <a href="trucks.php" class="btn btn-sm btn-outline-success me-2">
                    <i class="fas fa-list me-1"></i> View All Trucks
                </a>
                <a href="trucks-add.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus me-1"></i> Add Another Truck
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- Add Truck Form -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="POST" action="" id="truckAddForm">
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-truck-loading me-2"></i>Truck Information
                        </h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="plate_number" class="form-label">Plate Number *</label>
                                <input type="text" class="form-control" id="plate_number" name="plate_number" 
                                       value="<?php echo htmlspecialchars($truck_data['plate_number']); ?>" 
                                       required 
                                       placeholder="e.g., ABC-1234">
                                <div class="form-text">Enter the official license plate number</div>
                            </div>
                            <div class="col-md-6">
                                <label for="model" class="form-label">Truck Model *</label>
                                <input type="text" class="form-control" id="model" name="model" 
                                       value="<?php echo htmlspecialchars($truck_data['model']); ?>" 
                                       required 
                                       placeholder="e.g., Volvo FH16, Mercedes Actros">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="capacity" class="form-label">Capacity *</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="capacity" name="capacity" 
                                           value="<?php echo htmlspecialchars($truck_data['capacity']); ?>" 
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
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="available" <?php echo $truck_data['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="on_delivery" <?php echo $truck_data['status'] == 'on_delivery' ? 'selected' : ''; ?>>On Delivery</option>
                                    <option value="maintenance" <?php echo $truck_data['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="inactive" <?php echo $truck_data['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
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
                                                <?php echo $truck_data['driver_id'] == $driver['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($driver['name']); ?> (<?php echo htmlspecialchars($driver['license_number']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Assign an available driver to this truck</div>
                            </div>
                            <div class="col-md-6">
                                <label for="courier_id" class="form-label">Assign to Courier</label>
                                <select class="form-select" id="courier_id" name="courier_id">
                                    <option value="">-- No Courier Assigned --</option>
                                    <?php foreach ($couriers as $courier): ?>
                                        <option value="<?php echo $courier['id']; ?>" 
                                                <?php echo $truck_data['courier_id'] == $courier['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($courier['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Assign this truck to a courier company</div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="card-title mb-4" style="color: #0D2B4E; font-weight: 600;">
                            <i class="fas fa-calendar-alt me-2"></i>Maintenance & Insurance
                        </h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="insurance_expiry" class="form-label">Insurance Expiry Date</label>
                                <input type="date" class="form-control" id="insurance_expiry" name="insurance_expiry" 
                                       value="<?php echo htmlspecialchars($truck_data['insurance_expiry']); ?>">
                                <div class="form-text">Date when insurance coverage expires</div>
                            </div>
                            <div class="col-md-6">
                                <label for="last_maintenance" class="form-label">Last Maintenance Date</label>
                                <input type="date" class="form-control" id="last_maintenance" name="last_maintenance" 
                                       value="<?php echo htmlspecialchars($truck_data['last_maintenance']); ?>">
                                <div class="form-text">Date of last maintenance service</div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Any additional information about this truck..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex justify-content-between">
                            <a href="trucks.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Add Truck
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <!-- Information Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                        <i class="fas fa-info-circle me-2"></i>Quick Information
                    </h5>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tips for adding trucks:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                            <li>Ensure plate number is unique</li>
                            <li>Assign available drivers only</li>
                            <li>Update maintenance records regularly</li>
                            <li>Set proper insurance expiry dates</li>
                        </ul>
                    </div>
                    
                    <div class="list-group list-group-flush">
                        <div class="list-group-item">
                            <h6 class="mb-1">Required Fields</h6>
                            <small class="text-muted">Plate Number, Model, and Capacity are mandatory</small>
                        </div>
                        <div class="list-group-item">
                            <h6 class="mb-1">Driver Assignment</h6>
                            <small class="text-muted">Assigning a driver will change their status to "On Delivery"</small>
                        </div>
                        <div class="list-group-item">
                            <h6 class="mb-1">Status Types</h6>
                            <small class="text-muted">Available, On Delivery, Maintenance, Inactive</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="card-title mb-3" style="color: #0D2B4E; font-weight: 600;">
                        <i class="fas fa-chart-bar me-2"></i>System Stats
                    </h5>
                    
                    <?php
                    try {
                        $stats_query = "SELECT 
                            COUNT(*) as total_trucks,
                            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_trucks,
                            SUM(CASE WHEN driver_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_trucks
                            FROM trucks";
                        $stats_stmt = $db->query($stats_query);
                        $stats = $stats_stmt->fetch();
                    } catch (Exception $e) {
                        $stats = ['total_trucks' => 0, 'available_trucks' => 0, 'assigned_trucks' => 0];
                    }
                    ?>
                    
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Total Trucks:</span>
                            <span class="badge bg-primary"><?php echo $stats['total_trucks']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Available Trucks:</span>
                            <span class="badge bg-success"><?php echo $stats['available_trucks']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Assigned Trucks:</span>
                            <span class="badge bg-info"><?php echo $stats['assigned_trucks']; ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Available Drivers:</span>
                            <span class="badge bg-secondary"><?php echo count($drivers); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            if (!alert.classList.contains('alert-info')) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        });
    }, 5000);

    // Form validation
    document.getElementById('truckAddForm').addEventListener('submit', function(e) {
        const plateNumber = document.getElementById('plate_number').value.trim();
        const model = document.getElementById('model').value.trim();
        const capacity = document.getElementById('capacity').value;
        
        if (!plateNumber || !model || !capacity) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }
        
        if (capacity <= 0) {
            e.preventDefault();
            alert('Capacity must be greater than 0.');
            return false;
        }
    });

    // Set minimum date for date inputs to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('insurance_expiry').min = today;
    document.getElementById('last_maintenance').max = today;
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>