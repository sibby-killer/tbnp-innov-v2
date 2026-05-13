<?php
// orders-create.php - Create New Order
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

// Check if user is admin
if ($_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: ../index.php');
    exit();
}

$pageTitle = "Create New Order - Admin Dashboard";
$error_message = '';
$success_message = '';
$clients = [];
$trucks = [];
$drivers = [];
$couriers = [];

// Get couriers for dropdown (required for orders table)
try {
    $couriers_stmt = $db->prepare("
        SELECT id, company_name 
        FROM couriers 
        WHERE status = 'active' 
        ORDER BY company_name
    ");
    $couriers_stmt->execute();
    $couriers = $couriers_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Couriers fetch error: " . $e->getMessage());
}

// Get clients for dropdown
try {
    $clients_stmt = $db->prepare("
        SELECT id, company_name, contact_person, email, phone 
        FROM clients 
        WHERE status = 'active' 
        ORDER BY company_name
    ");
    $clients_stmt->execute();
    $clients = $clients_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Clients fetch error: " . $e->getMessage());
}

// Get trucks for dropdown
try {
    $trucks_stmt = $db->prepare("
        SELECT id, plate_number, model, capacity, status 
        FROM trucks 
        WHERE status = 'available' 
        ORDER BY plate_number
    ");
    $trucks_stmt->execute();
    $trucks = $trucks_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Trucks fetch error: " . $e->getMessage());
}

// Get drivers for dropdown
try {
    $drivers_stmt = $db->prepare("
        SELECT id, full_name, license_number, phone, status 
        FROM drivers 
        WHERE status = 'available' 
        ORDER BY full_name
    ");
    $drivers_stmt->execute();
    $drivers = $drivers_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Drivers fetch error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get and validate form data
    $client_id = intval($_POST['client_id'] ?? 0);
    $courier_id = intval($_POST['courier_id'] ?? 0);
    $truck_id = intval($_POST['truck_id'] ?? 0);
    $driver_id = intval($_POST['driver_id'] ?? 0);
    
    // Generate unique tracking number
    $tracking_number = 'TRK' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $pickup_location = trim($_POST['pickup_location'] ?? '');
    $delivery_location = trim($_POST['delivery_location'] ?? '');
    $sender_name = trim($_POST['sender_name'] ?? '');
    $sender_phone = trim($_POST['sender_phone'] ?? '');
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');
    $package_description = trim($_POST['package_description'] ?? '');
    $weight = floatval($_POST['weight'] ?? 0);
    $dimensions = trim($_POST['dimensions'] ?? '');
    $declared_value = floatval($_POST['declared_value'] ?? 0);
    $service_type = $_POST['service_type'] ?? 'standard';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $amount = floatval($_POST['amount'] ?? 0);
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $estimated_pickup = $_POST['estimated_pickup'] ?? '';
    $estimated_delivery = $_POST['estimated_delivery'] ?? '';
    $special_instructions = trim($_POST['special_instructions'] ?? '');
    
    // Validation
    $errors = [];
    
    if ($client_id <= 0) {
        $errors[] = "Please select a client";
    }
    
    if ($courier_id <= 0) {
        $errors[] = "Please select a courier";
    }
    
    if (empty($pickup_location)) {
        $errors[] = "Pickup location is required";
    }
    
    if (empty($delivery_location)) {
        $errors[] = "Delivery location is required";
    }
    
    if (empty($sender_name)) {
        $errors[] = "Sender name is required";
    }
    
    if (empty($sender_phone)) {
        $errors[] = "Sender phone is required";
    }
    
    if (empty($recipient_name)) {
        $errors[] = "Recipient name is required";
    }
    
    if (empty($recipient_phone)) {
        $errors[] = "Recipient phone is required";
    }
    
    if ($weight <= 0) {
        $errors[] = "Weight must be greater than 0";
    }
    
    if ($total_amount <= 0) {
        $errors[] = "Total amount must be greater than 0";
    }
    
    if (empty($estimated_pickup)) {
        $errors[] = "Estimated pickup date is required";
    }
    
    if (empty($estimated_delivery)) {
        $errors[] = "Estimated delivery date is required";
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Insert order
            $order_sql = "
                INSERT INTO orders (
                    client_id, courier_id, truck_id, driver_id, tracking_number,
                    pickup_location, delivery_location, sender_name, sender_phone,
                    recipient_name, recipient_phone, package_description, weight,
                    dimensions, declared_value, service_type, payment_method,
                    amount, total_amount, status_id, estimated_pickup,
                    estimated_delivery, special_instructions, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $order_stmt = $db->prepare($order_sql);
            
            // Default to "Pending" status (ID 1)
            $status_id = 1;
            
            $order_params = [
                $client_id,
                $courier_id,
                $truck_id > 0 ? $truck_id : null,
                $driver_id > 0 ? $driver_id : null,
                $tracking_number,
                $pickup_location,
                $delivery_location,
                $sender_name,
                $sender_phone,
                $recipient_name,
                $recipient_phone,
                $package_description,
                $weight,
                $dimensions,
                $declared_value,
                $service_type,
                $payment_method,
                $amount,
                $total_amount,
                $status_id,
                $estimated_pickup,
                $estimated_delivery,
                $special_instructions,
                $_SESSION['user_id']
            ];
            
            $order_stmt->execute($order_params);
            $order_id = $db->lastInsertId();
            
            // Update truck status if assigned
            if ($truck_id > 0) {
                $truck_update_sql = "UPDATE trucks SET status = 'assigned' WHERE id = ?";
                $truck_update_stmt = $db->prepare($truck_update_sql);
                $truck_update_stmt->execute([$truck_id]);
            }
            
            // Update driver status if assigned
            if ($driver_id > 0) {
                $driver_update_sql = "UPDATE drivers SET status = 'assigned' WHERE id = ?";
                $driver_update_stmt = $db->prepare($driver_update_sql);
                $driver_update_stmt->execute([$driver_id]);
            }
            
            // Commit transaction
            $db->commit();
            
            $success_message = "Order #$order_id created successfully! Tracking Number: $tracking_number";
            
            // Redirect to view order after 2 seconds
            header("refresh:2;url=orders-view.php?id=$order_id");
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Order creation error: " . $e->getMessage());
            $error_message = "Error creating order: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Check if client_id is passed via GET (for quick order creation from client view)
$pre_selected_client = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<style>
/* Order create specific styles */
.order-create-card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.form-section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #3b82f6;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0.5rem;
    margin-bottom: 1.5rem;
}

.form-label {
    font-weight: 600;
    color: #4b5563;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0.75rem;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.required-field::after {
    content: " *";
    color: #dc2626;
}

.form-text {
    font-size: 0.875rem;
    color: #6b7280;
}

.btn-submit {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 0.75rem 2rem;
    font-weight: 600;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.calculation-card {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1.5rem;
}

.calculation-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px dashed #e2e8f0;
}

.calculation-row:last-child {
    border-bottom: none;
    font-weight: 700;
    font-size: 1.1rem;
    color: #059669;
}

.calculation-label {
    color: #64748b;
}

.calculation-value {
    font-weight: 600;
}

.preview-box {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.client-info-box {
    background-color: #eff6ff;
    border: 1px solid #dbeafe;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
}

/* Prevent content from spilling over sidebar */
.admin-container {
    margin-left: 250px;
    padding: 20px;
    max-width: calc(100% - 250px);
    overflow-x: hidden;
    box-sizing: border-box;
}

@media (max-width: 992px) {
    .admin-container {
        margin-left: 0;
        max-width: 100%;
        padding: 15px;
    }
}
</style>

<!-- Admin Container -->
<div class="admin-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #059669;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-plus-circle me-2"></i>Create New Order
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Create a new delivery order
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="orders.php" class="btn btn-outline-primary">
                    <i class="fas fa-list me-2"></i> View All Orders
                </a>
                <a href="clients.php" class="btn btn-outline-secondary">
                    <i class="fas fa-users me-2"></i> View Clients
                </a>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $success_message; ?>
            <p class="mb-0 mt-2">Redirecting to order view page...</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="" class="needs-validation" novalidate id="orderForm">
        <div class="row">
            <!-- Left Column: Order Details -->
            <div class="col-lg-8">
                <div class="card order-create-card mb-4">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-info-circle me-2"></i>Order Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="form-section-title">Client & Courier Information</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="client_id" class="form-label required-field">Select Client</label>
                                <select class="form-select" id="client_id" name="client_id" required>
                                    <option value="">-- Select Client --</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>" 
                                                <?php echo ($pre_selected_client == $client['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['company_name']); ?>
                                            <?php if ($client['contact_person']): ?>
                                                - <?php echo htmlspecialchars($client['contact_person']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a client.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="courier_id" class="form-label required-field">Select Courier</label>
                                <select class="form-select" id="courier_id" name="courier_id" required>
                                    <option value="">-- Select Courier --</option>
                                    <?php foreach ($couriers as $courier): ?>
                                        <option value="<?php echo $courier['id']; ?>">
                                            <?php echo htmlspecialchars($courier['company_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a courier.
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Sender & Recipient Information</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sender_name" class="form-label required-field">Sender Name</label>
                                <input type="text" class="form-control" id="sender_name" name="sender_name" 
                                       placeholder="Full name of sender" required>
                                <div class="invalid-feedback">
                                    Please enter sender name.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="sender_phone" class="form-label required-field">Sender Phone</label>
                                <input type="tel" class="form-control" id="sender_phone" name="sender_phone" 
                                       placeholder="e.g., +254712345678" required>
                                <div class="invalid-feedback">
                                    Please enter sender phone number.
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="recipient_name" class="form-label required-field">Recipient Name</label>
                                <input type="text" class="form-control" id="recipient_name" name="recipient_name" 
                                       placeholder="Full name of recipient" required>
                                <div class="invalid-feedback">
                                    Please enter recipient name.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="recipient_phone" class="form-label required-field">Recipient Phone</label>
                                <input type="tel" class="form-control" id="recipient_phone" name="recipient_phone" 
                                       placeholder="e.g., +254712345678" required>
                                <div class="invalid-feedback">
                                    Please enter recipient phone number.
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Location Information</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="pickup_location" class="form-label required-field">Pickup Location</label>
                                <input type="text" class="form-control" id="pickup_location" name="pickup_location" 
                                       placeholder="Street, City, Country" required>
                                <div class="invalid-feedback">
                                    Please enter pickup location.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="delivery_location" class="form-label required-field">Delivery Location</label>
                                <input type="text" class="form-control" id="delivery_location" name="delivery_location" 
                                       placeholder="Street, City, Country" required>
                                <div class="invalid-feedback">
                                    Please enter delivery location.
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="estimated_pickup" class="form-label required-field">Estimated Pickup Date & Time</label>
                                <input type="datetime-local" class="form-control" id="estimated_pickup" name="estimated_pickup" required>
                                <div class="invalid-feedback">
                                    Please select estimated pickup date and time.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="estimated_delivery" class="form-label required-field">Estimated Delivery Date & Time</label>
                                <input type="datetime-local" class="form-control" id="estimated_delivery" name="estimated_delivery" required>
                                <div class="invalid-feedback">
                                    Please select estimated delivery date and time.
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Package Details</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="package_description" class="form-label">Package Description</label>
                                <textarea class="form-control" id="package_description" name="package_description" rows="3" 
                                          placeholder="Describe the items being shipped..."></textarea>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="special_instructions" class="form-label">Special Instructions</label>
                                <textarea class="form-control" id="special_instructions" name="special_instructions" 
                                          rows="3" placeholder="Any special handling instructions..."></textarea>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="weight" class="form-label required-field">Weight (kg)</label>
                                <input type="number" class="form-control" id="weight" name="weight" 
                                       step="0.01" min="0.1" value="1" required>
                                <div class="invalid-feedback">
                                    Please enter weight (minimum 0.1 kg).
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="dimensions" class="form-label">Dimensions (L x W x H cm)</label>
                                <input type="text" class="form-control" id="dimensions" name="dimensions" 
                                       placeholder="e.g., 30x20x15">
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="declared_value" class="form-label">Declared Value (KSh)</label>
                                <input type="number" class="form-control" id="declared_value" name="declared_value" 
                                       step="0.01" min="0" value="0">
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Assign Resources (Optional)</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="truck_id" class="form-label">Assign Truck</label>
                                <select class="form-select" id="truck_id" name="truck_id">
                                    <option value="">-- No Truck Assigned --</option>
                                    <?php foreach ($trucks as $truck): ?>
                                        <option value="<?php echo $truck['id']; ?>">
                                            <?php echo htmlspecialchars($truck['plate_number']); ?> 
                                            (<?php echo htmlspecialchars($truck['model']); ?> - 
                                            <?php echo $truck['capacity']; ?>kg)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="driver_id" class="form-label">Assign Driver</label>
                                <select class="form-select" id="driver_id" name="driver_id">
                                    <option value="">-- No Driver Assigned --</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo $driver['id']; ?>">
                                            <?php echo htmlspecialchars($driver['full_name']); ?> 
                                            (<?php echo htmlspecialchars($driver['license_number']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Pricing & Summary -->
            <div class="col-lg-4">
                <!-- Service & Pricing Card -->
                <div class="card order-create-card mb-4">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calculator me-2"></i>Service & Pricing
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-12">
                                <label for="service_type" class="form-label">Service Type</label>
                                <select class="form-select" id="service_type" name="service_type">
                                    <option value="standard">Standard (3-5 days)</option>
                                    <option value="express">Express (1-2 days)</option>
                                    <option value="overnight">Overnight (Next day)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="calculation-card">
                            <div class="calculation-row">
                                <span class="calculation-label">Base Fare:</span>
                                <span class="calculation-value">
                                    KSh <span id="display_base_fare">500.00</span>
                                </span>
                            </div>
                            
                            <div class="calculation-row">
                                <span class="calculation-label">Weight Charges:</span>
                                <span class="calculation-value">
                                    KSh <span id="display_weight_charges">50.00</span>
                                </span>
                            </div>
                            
                            <div class="calculation-row">
                                <span class="calculation-label">Distance Charges:</span>
                                <span class="calculation-value">
                                    KSh <span id="display_distance_charges">0.00</span>
                                </span>
                            </div>
                            
                            <div class="calculation-row">
                                <span class="calculation-label">Service Fee:</span>
                                <span class="calculation-value">
                                    KSh <span id="display_service_fee">0.00</span>
                                </span>
                            </div>
                            
                            <div class="calculation-row">
                                <span class="calculation-label">Amount:</span>
                                <span class="calculation-value">
                                    <input type="number" class="form-control form-control-sm" id="amount" 
                                           name="amount" step="0.01" min="0" value="0" readonly
                                           style="width: 120px; display: inline-block;">
                                </span>
                            </div>
                            
                            <div class="calculation-row">
                                <span class="calculation-label">Total Amount:</span>
                                <span class="calculation-value">
                                    <input type="number" class="form-control form-control-sm" id="total_amount" 
                                           name="total_amount" step="0.01" min="0" value="0" readonly
                                           style="width: 120px; display: inline-block;">
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mt-4">
                            <div class="col-12 mb-3">
                                <label for="payment_method" class="form-label">Payment Method</label>
                                <select class="form-select" id="payment_method" name="payment_method">
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cod">Cash on Delivery</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Order Summary -->
                <div class="card order-create-card mb-4">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-clipboard-check me-2"></i>Order Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="preview-box">
                            <h6 class="text-muted mb-2">Tracking Number</h6>
                            <div class="h6 text-primary" id="preview-tracking">
                                <?php echo 'TRK' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT); ?>
                            </div>
                        </div>
                        
                        <div class="preview-box">
                            <h6 class="text-muted mb-2">Route</h6>
                            <div class="small">
                                <div class="mb-1">
                                    <i class="fas fa-map-marker-alt text-success me-2"></i>
                                    <span id="preview-pickup">Not specified</span>
                                </div>
                                <div class="mb-1">
                                    <i class="fas fa-arrow-down text-primary mx-2 ms-3"></i>
                                </div>
                                <div>
                                    <i class="fas fa-flag-checkered text-danger me-2"></i>
                                    <span id="preview-delivery">Not specified</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="preview-box">
                            <h6 class="text-muted mb-2">Schedule</h6>
                            <div class="small">
                                <div class="mb-1">
                                    <i class="fas fa-calendar-alt text-primary me-2"></i>
                                    Pickup: <span id="preview-pickup-date">Not set</span>
                                </div>
                                <div>
                                    <i class="fas fa-calendar-check text-success me-2"></i>
                                    Delivery: <span id="preview-delivery-date">Not set</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="preview-box">
                            <h6 class="text-muted mb-2">Package</h6>
                            <div class="small">
                                <div class="mb-1">
                                    <i class="fas fa-weight text-warning me-2"></i>
                                    Weight: <span id="preview-weight">0 kg</span>
                                </div>
                                <div>
                                    <i class="fas fa-ruler-combined text-info me-2"></i>
                                    Dimensions: <span id="preview-dimensions">Not specified</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="card order-create-card">
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-submit text-white">
                                <i class="fas fa-save me-2"></i> Create Order
                            </button>
                            <a href="orders.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i> Cancel
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set default dates
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    
    // Format for datetime-local input (YYYY-MM-DDTHH:MM)
    const formatDate = (date) => {
        return date.toISOString().slice(0, 16);
    };
    
    document.getElementById('estimated_pickup').value = formatDate(now);
    document.getElementById('estimated_delivery').value = formatDate(tomorrow);
    
    // Form validation
    const form = document.getElementById('orderForm');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    }, false);
    
    // Real-time preview updates
    const previewFields = ['pickup_location', 'delivery_location', 'estimated_pickup', 
                          'estimated_delivery', 'weight', 'dimensions', 'sender_name',
                          'recipient_name', 'service_type'];
    
    previewFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', updatePreview);
            field.addEventListener('change', updatePreview);
        }
    });
    
    // Pricing calculation fields
    const calcFields = ['weight', 'service_type'];
    calcFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', calculatePricing);
            field.addEventListener('change', calculatePricing);
        }
    });
    
    // Initialize
    updatePreview();
    calculatePricing();
});

function updatePreview() {
    // Update pickup location
    const pickup = document.getElementById('pickup_location').value || 'Not specified';
    document.getElementById('preview-pickup').textContent = pickup;
    
    // Update delivery location
    const delivery = document.getElementById('delivery_location').value || 'Not specified';
    document.getElementById('preview-delivery').textContent = delivery;
    
    // Update pickup date
    const pickupDate = document.getElementById('estimated_pickup').value;
    if (pickupDate) {
        const dateObj = new Date(pickupDate);
        document.getElementById('preview-pickup-date').textContent = 
            dateObj.toLocaleDateString() + ' ' + dateObj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    } else {
        document.getElementById('preview-pickup-date').textContent = 'Not set';
    }
    
    // Update delivery date
    const deliveryDate = document.getElementById('estimated_delivery').value;
    if (deliveryDate) {
        const dateObj = new Date(deliveryDate);
        document.getElementById('preview-delivery-date').textContent = 
            dateObj.toLocaleDateString() + ' ' + dateObj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    } else {
        document.getElementById('preview-delivery-date').textContent = 'Not set';
    }
    
    // Update weight
    const weight = document.getElementById('weight').value || '0';
    document.getElementById('preview-weight').textContent = weight + ' kg';
    
    // Update dimensions
    const dimensions = document.getElementById('dimensions').value || 'Not specified';
    document.getElementById('preview-dimensions').textContent = dimensions;
}

function calculatePricing() {
    // Get values
    const weight = parseFloat(document.getElementById('weight').value) || 0;
    const serviceType = document.getElementById('service_type').value;
    
    // Calculate charges
    let baseFare = 500; // Base fare in KSh
    const weightRate = 50; // KSh per kg
    let serviceRate = 0; // Service fee
    
    // Adjust rates based on service type
    switch(serviceType) {
        case 'express':
            serviceRate = 200;
            break;
        case 'overnight':
            serviceRate = 400;
            break;
        default: // standard
            serviceRate = 0;
    }
    
    const weightCharges = weight * weightRate;
    const distanceCharges = 0; // You can implement distance calculation later
    
    // Calculate totals
    const amount = baseFare + weightCharges + distanceCharges;
    const totalAmount = amount + serviceRate;
    
    // Update display
    document.getElementById('display_base_fare').textContent = baseFare.toFixed(2);
    document.getElementById('display_weight_charges').textContent = weightCharges.toFixed(2);
    document.getElementById('display_distance_charges').textContent = distanceCharges.toFixed(2);
    document.getElementById('display_service_fee').textContent = serviceRate.toFixed(2);
    
    // Update form fields
    document.getElementById('amount').value = amount.toFixed(2);
    document.getElementById('total_amount').value = totalAmount.toFixed(2);
}

// Auto-format phone numbers
document.getElementById('sender_phone').addEventListener('input', function(e) {
    formatPhoneNumber(this);
});

document.getElementById('recipient_phone').addEventListener('input', function(e) {
    formatPhoneNumber(this);
});

function formatPhoneNumber(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length > 0) {
        if (value.startsWith('0')) {
            value = '+254' + value.substring(1);
        } else if (value.startsWith('254')) {
            value = '+' + value;
        } else if (value.startsWith('7') || value.startsWith('1')) {
            value = '+254' + value;
        }
    }
    
    input.value = value;
}

// Auto-suggest for locations
const locationSuggestions = [
    "Nairobi CBD", "Mombasa", "Kisumu", "Nakuru", "Eldoret",
    "Thika", "Kitengela", "Ruiru", "Kiambu", "Kikuyu"
];

function setupAutocomplete(inputId) {
    const input = document.getElementById(inputId);
    const datalist = document.createElement('datalist');
    datalist.id = inputId + '-suggestions';
    
    locationSuggestions.forEach(location => {
        const option = document.createElement('option');
        option.value = location;
        datalist.appendChild(option);
    });
    
    input.setAttribute('list', datalist.id);
    input.parentNode.appendChild(datalist);
}

// Setup autocomplete for location fields
setupAutocomplete('pickup_location');
setupAutocomplete('delivery_location');

// Auto-fill sender info when client is selected
document.getElementById('client_id').addEventListener('change', function() {
    const clientId = this.value;
    if (clientId) {
        // In a real app, you would fetch client details via AJAX
        // For now, we'll use the selected option text
        const selectedOption = this.options[this.selectedIndex];
        const clientName = selectedOption.text.split(' - ')[0].trim();
        
        // Auto-fill sender name with client company name
        document.getElementById('sender_name').value = clientName;
    }
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>