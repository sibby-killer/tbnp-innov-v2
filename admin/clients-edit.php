<?php
// clients-edit.php - Edit Client Details
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

// Check if client ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: clients.php');
    exit();
}

$client_id = intval($_GET['id']);
$pageTitle = "Edit Client - Admin Dashboard";

// Get client details
$client = null;
$error_message = '';
$success_message = '';

// Get client details for editing
try {
    $client_sql = "SELECT * FROM clients WHERE id = ?";
    $client_stmt = $db->prepare($client_sql);
    $client_stmt->execute([$client_id]);
    $client = $client_stmt->fetch();
    
    if (!$client) {
        header('Location: clients.php');
        exit();
    }
    
} catch (Exception $e) {
    error_log("Client edit error: " . $e->getMessage());
    $error_message = "Error loading client details: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and validate input
    $company_name = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $alternative_phone = trim($_POST['alternative_phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $billing_address = trim($_POST['billing_address'] ?? '');
    $shipping_address = trim($_POST['shipping_address'] ?? '');
    $tax_number = trim($_POST['tax_number'] ?? '');
    $account_balance = floatval($_POST['account_balance'] ?? 0);
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    $client_type = $_POST['client_type'] ?? 'individual';
    $notes = trim($_POST['notes'] ?? '');
    
    // Basic validation
    $errors = [];
    
    if (empty($company_name)) {
        $errors[] = "Company name is required";
    }
    
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($errors)) {
        try {
            // Update client in database
            $update_sql = "
                UPDATE clients 
                SET 
                    company_name = ?,
                    contact_person = ?,
                    email = ?,
                    phone = ?,
                    alternative_phone = ?,
                    address = ?,
                    city = ?,
                    country = ?,
                    billing_address = ?,
                    shipping_address = ?,
                    tax_number = ?,
                    account_balance = ?,
                    credit_limit = ?,
                    status = ?,
                    client_type = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $update_params = [
                $company_name,
                $contact_person,
                $email,
                $phone,
                $alternative_phone,
                $address,
                $city,
                $country,
                $billing_address,
                $shipping_address,
                $tax_number,
                $account_balance,
                $credit_limit,
                $status,
                $client_type,
                $notes,
                $client_id
            ];
            
            $update_stmt = $db->prepare($update_sql);
            $update_stmt->execute($update_params);
            
            $success_message = "Client updated successfully!";
            
            // Refresh client data
            $client_stmt->execute([$client_id]);
            $client = $client_stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Client update error: " . $e->getMessage());
            $error_message = "Error updating client: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<style>
/* Edit form specific styles */
.edit-client-card {
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

/* Status badges for preview */
.status-badge-preview {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-block;
}

.status-active-preview {
    background-color: #d1e7dd;
    color: #0f5132;
    border: 1px solid #badbcc;
}

.status-inactive-preview {
    background-color: #f8d7da;
    color: #842029;
    border: 1px solid #f5c2c7;
}

.status-pending-preview {
    background-color: #fff3cd;
    color: #664d03;
    border: 1px solid #ffecb5;
}

.type-badge-preview {
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    display: inline-block;
}

.type-corporate-preview {
    background-color: #cfe2ff;
    color: #052c65;
    border: 1px solid #9ec5fe;
}

.type-individual-preview {
    background-color: #e2e3e5;
    color: #2b2f32;
    border: 1px solid #c4c8cb;
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
                    <i class="fas fa-edit me-2"></i>Edit Client
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Update client information and details
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="clients-view.php?id=<?php echo $client_id; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-eye me-2"></i> View Client
                </a>
                <a href="clients.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to List
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
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column: Edit Form -->
        <div class="col-lg-8">
            <form method="POST" action="" class="needs-validation" novalidate>
                <div class="card edit-client-card mb-4">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-building me-2"></i>Client Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="form-section-title">Basic Information</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="company_name" class="form-label required-field">Company Name</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" 
                                       value="<?php echo htmlspecialchars($client['company_name'] ?? ''); ?>" 
                                       required>
                                <div class="invalid-feedback">
                                    Please enter company name.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                       value="<?php echo htmlspecialchars($client['contact_person'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="client_type" class="form-label">Client Type</label>
                                <select class="form-select" id="client_type" name="client_type">
                                    <option value="individual" <?php echo ($client['client_type'] ?? 'individual') == 'individual' ? 'selected' : ''; ?>>Individual</option>
                                    <option value="corporate" <?php echo ($client['client_type'] ?? 'individual') == 'corporate' ? 'selected' : ''; ?>>Corporate</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="tax_number" class="form-label">Tax Number (VAT)</label>
                                <input type="text" class="form-control" id="tax_number" name="tax_number" 
                                       value="<?php echo htmlspecialchars($client['tax_number'] ?? ''); ?>">
                                <div class="form-text">Required for corporate clients</div>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Contact Information</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($client['email'] ?? ''); ?>">
                                <div class="invalid-feedback">
                                    Please enter a valid email address.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="alternative_phone" class="form-label">Alternative Phone</label>
                                <input type="tel" class="form-control" id="alternative_phone" name="alternative_phone" 
                                       value="<?php echo htmlspecialchars($client['alternative_phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo ($client['status'] ?? 'active') == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($client['status'] ?? 'active') == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="pending" <?php echo ($client['status'] ?? 'active') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Address Information</div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($client['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="city" class="form-label">City</label>
                                <input type="text" class="form-control" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($client['city'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="country" name="country" 
                                       value="<?php echo htmlspecialchars($client['country'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="billing_address" class="form-label">Billing Address</label>
                                <textarea class="form-control" id="billing_address" name="billing_address" rows="3"><?php echo htmlspecialchars($client['billing_address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="shipping_address" class="form-label">Shipping Address</label>
                                <textarea class="form-control" id="shipping_address" name="shipping_address" rows="3"><?php echo htmlspecialchars($client['shipping_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Financial Information</div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="account_balance" class="form-label">Account Balance (KSh)</label>
                                <input type="number" step="0.01" class="form-control" id="account_balance" name="account_balance" 
                                       value="<?php echo htmlspecialchars($client['account_balance'] ?? '0.00'); ?>">
                                <div class="form-text">Current balance (positive for credit, negative for debit)</div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="credit_limit" class="form-label">Credit Limit (KSh)</label>
                                <input type="number" step="0.01" class="form-control" id="credit_limit" name="credit_limit" 
                                       value="<?php echo htmlspecialchars($client['credit_limit'] ?? '0.00'); ?>">
                                <div class="form-text">Maximum credit allowed for this client</div>
                            </div>
                        </div>
                        
                        <div class="form-section-title mt-4">Additional Information</div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="4"><?php echo htmlspecialchars($client['notes'] ?? ''); ?></textarea>
                                <div class="form-text">Add any additional notes or comments about this client</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="card edit-client-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <a href="clients-view.php?id=<?php echo $client_id; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </a>
                                <button type="button" class="btn btn-outline-danger ms-2" onclick="confirmDelete()">
                                    <i class="fas fa-trash me-2"></i> Delete Client
                                </button>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-submit text-white">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Right Column: Preview & Info -->
        <div class="col-lg-4">
            <!-- Client Preview -->
            <div class="card edit-client-card mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-eye me-2"></i>Client Preview
                    </h5>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="bg-primary rounded-circle p-4 d-inline-block">
                            <i class="fas fa-building text-white fa-3x"></i>
                        </div>
                        <h4 class="mt-3 mb-1" id="preview-company-name">
                            <?php echo htmlspecialchars($client['company_name'] ?? 'No Company Name'); ?>
                        </h4>
                        <div class="d-flex justify-content-center gap-2 mt-2">
                            <span id="preview-status" class="status-badge-preview status-<?php echo $client['status'] ?? 'active'; ?>-preview">
                                <?php echo ucfirst($client['status'] ?? 'Active'); ?>
                            </span>
                            <span id="preview-type" class="type-badge-preview type-<?php echo $client['client_type'] ?? 'individual'; ?>-preview">
                                <?php echo ucfirst($client['client_type'] ?? 'Individual'); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="text-muted mb-2">Contact Details</h6>
                        <div class="small">
                            <div class="mb-1">
                                <i class="fas fa-user me-2 text-primary"></i>
                                <span id="preview-contact-person"><?php echo htmlspecialchars($client['contact_person'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mb-1">
                                <i class="fas fa-envelope me-2 text-primary"></i>
                                <span id="preview-email"><?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="mb-1">
                                <i class="fas fa-phone me-2 text-primary"></i>
                                <span id="preview-phone"><?php echo htmlspecialchars($client['phone'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="text-muted mb-2">Address</h6>
                        <div class="small" id="preview-address">
                            <?php 
                            $address_parts = [];
                            if (!empty($client['address'])) $address_parts[] = htmlspecialchars($client['address']);
                            if (!empty($client['city'])) $address_parts[] = htmlspecialchars($client['city']);
                            if (!empty($client['country'])) $address_parts[] = htmlspecialchars($client['country']);
                            echo !empty($address_parts) ? implode(', ', $address_parts) : 'N/A';
                            ?>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="text-muted mb-2">Financial Info</h6>
                        <div class="row small">
                            <div class="col-6">
                                <div class="text-muted">Balance:</div>
                                <div class="fw-semibold" id="preview-balance">
                                    KSh <?php echo number_format($client['account_balance'] ?? 0, 2); ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted">Credit Limit:</div>
                                <div class="fw-semibold" id="preview-credit-limit">
                                    KSh <?php echo number_format($client['credit_limit'] ?? 0, 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <a href="clients-view.php?id=<?php echo $client_id; ?>" class="btn btn-outline-primary w-100">
                            <i class="fas fa-external-link-alt me-2"></i> View Full Profile
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="card edit-client-card">
                <div class="card-header bg-white border-bottom">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Quick Stats
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="bg-light rounded p-3">
                                <div class="h4 mb-1">0</div>
                                <div class="text-muted small">Total Orders</div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="bg-light rounded p-3">
                                <div class="h4 mb-1">KSh 0.00</div>
                                <div class="text-muted small">Total Spent</div>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Order statistics will appear after client places orders.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
    
    // Real-time preview updates
    const formInputs = document.querySelectorAll('input, select, textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', updatePreview);
        input.addEventListener('change', updatePreview);
    });
    
    // Initialize preview
    updatePreview();
});

function updatePreview() {
    // Update company name
    const companyName = document.getElementById('company_name').value || 'No Company Name';
    document.getElementById('preview-company-name').textContent = companyName;
    
    // Update contact person
    const contactPerson = document.getElementById('contact_person').value || 'N/A';
    document.getElementById('preview-contact-person').textContent = contactPerson;
    
    // Update email
    const email = document.getElementById('email').value || 'N/A';
    document.getElementById('preview-email').textContent = email;
    
    // Update phone
    const phone = document.getElementById('phone').value || 'N/A';
    document.getElementById('preview-phone').textContent = phone;
    
    // Update address
    const address = document.getElementById('address').value || '';
    const city = document.getElementById('city').value || '';
    const country = document.getElementById('country').value || '';
    
    const addressParts = [];
    if (address) addressParts.push(address);
    if (city) addressParts.push(city);
    if (country) addressParts.push(country);
    
    document.getElementById('preview-address').textContent = addressParts.length > 0 ? addressParts.join(', ') : 'N/A';
    
    // Update status badge
    const status = document.getElementById('status').value;
    const statusBadge = document.getElementById('preview-status');
    statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    statusBadge.className = `status-badge-preview status-${status}-preview`;
    
    // Update type badge
    const clientType = document.getElementById('client_type').value;
    const typeBadge = document.getElementById('preview-type');
    typeBadge.textContent = clientType.charAt(0).toUpperCase() + clientType.slice(1);
    typeBadge.className = `type-badge-preview type-${clientType}-preview`;
    
    // Update balance
    const balance = document.getElementById('account_balance').value || '0.00';
    document.getElementById('preview-balance').textContent = 'KSh ' + parseFloat(balance).toFixed(2);
    
    // Update credit limit
    const creditLimit = document.getElementById('credit_limit').value || '0.00';
    document.getElementById('preview-credit-limit').textContent = 'KSh ' + parseFloat(creditLimit).toFixed(2);
}

function confirmDelete() {
    if (confirm('Are you sure you want to delete this client?\n\nThis action cannot be undone and will delete all associated data.')) {
        window.location.href = 'clients-delete.php?id=<?php echo $client_id; ?>';
    }
}

// Auto-format phone numbers
document.getElementById('phone').addEventListener('input', function(e) {
    formatPhoneNumber(this);
});

document.getElementById('alternative_phone').addEventListener('input', function(e) {
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
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>