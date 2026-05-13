<?php
// clients-view.php - View Individual Client Details
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
$pageTitle = "View Client - Admin Dashboard";

// Get client details
$client = null;
$error_message = '';

try {
    // Get client details
    $client_sql = "
        SELECT 
            c.*,
            DATE_FORMAT(c.created_at, '%d %b %Y') as created_formatted,
            DATE_FORMAT(c.updated_at, '%d %b %Y %H:%i') as updated_formatted,
            (SELECT COUNT(*) FROM orders WHERE client_id = c.id) as total_orders,
            (SELECT COUNT(*) FROM orders WHERE client_id = c.id AND status_id = 6) as completed_orders,
            (SELECT SUM(total_amount) FROM orders WHERE client_id = c.id AND status_id = 6) as total_spent
        FROM clients c
        WHERE c.id = ?
    ";
    
    $client_stmt = $db->prepare($client_sql);
    $client_stmt->execute([$client_id]);
    $client = $client_stmt->fetch();
    
    if (!$client) {
        header('Location: clients.php');
        exit();
    }
    
} catch (Exception $e) {
    error_log("Client view error: " . $e->getMessage());
    $error_message = "Error loading client details: " . $e->getMessage();
}

// Get recent orders for this client
$recent_orders = [];
try {
    $orders_sql = "
        SELECT 
            o.*,
            os.status_name,
            DATE_FORMAT(o.created_at, '%d %b %Y %H:%i') as created_formatted,
            DATE_FORMAT(o.delivery_date, '%d %b %Y') as delivery_formatted
        FROM orders o
        LEFT JOIN order_statuses os ON o.status_id = os.id
        WHERE o.client_id = ?
        ORDER BY o.created_at DESC
        LIMIT 10
    ";
    
    $orders_stmt = $db->prepare($orders_sql);
    $orders_stmt->execute([$client_id]);
    $recent_orders = $orders_stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Client orders error: " . $e->getMessage());
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<style>
/* Client view specific styles */
.client-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 2rem;
    margin-bottom: 2rem;
}

.client-avatar {
    width: 100px;
    height: 100px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin-right: 1.5rem;
}

.client-info-card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.client-info-card:hover {
    transform: translateY(-5px);
}

.info-item {
    padding: 0.75rem 0;
    border-bottom: 1px solid #eee;
}

.info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #666;
    min-width: 150px;
}

.info-value {
    color: #333;
}

/* Status badges (same as clients.php) */
.status-active {
    background-color: #d1e7dd;
    color: #0f5132;
    border: 1px solid #badbcc;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.status-inactive {
    background-color: #f8d7da;
    color: #842029;
    border: 1px solid #f5c2c7;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.status-pending {
    background-color: #fff3cd;
    color: #664d03;
    border: 1px solid #ffecb5;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

/* Type badges (same as clients.php) */
.type-corporate {
    background-color: #cfe2ff;
    color: #052c65;
    border: 1px solid #9ec5fe;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.type-individual {
    background-color: #e2e3e5;
    color: #2b2f32;
    border: 1px solid #c4c8cb;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.stat-card {
    border-radius: 10px;
    border: none;
    text-align: center;
    padding: 1.5rem;
}

.stat-icon {
    font-size: 2rem;
    margin-bottom: 1rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.stat-label {
    color: #666;
    font-size: 0.875rem;
}

.order-status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.amount-positive {
    color: #059669;
    font-weight: 600;
}

.order-row:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}

.action-buttons .btn {
    min-width: 120px;
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
    <!-- Client Header -->
    <div class="client-header">
        <div class="d-flex align-items-center">
            <div class="client-avatar">
                <i class="fas fa-building"></i>
            </div>
            <div class="flex-grow-1">
                <h1 class="h3 mb-2"><?php echo htmlspecialchars($client['company_name'] ?? 'No Company Name'); ?></h1>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="status-<?php echo $client['status'] ?? 'active'; ?>">
                        <?php echo ucfirst($client['status'] ?? 'Active'); ?>
                    </span>
                    <span class="type-<?php echo $client['client_type'] ?? 'individual'; ?>">
                        <?php echo ucfirst($client['client_type'] ?? 'Individual'); ?> Client
                    </span>
                    <span class="text-white-50">
                        <i class="fas fa-hashtag me-1"></i>ID: <?php echo $client['id']; ?>
                    </span>
                    <span class="text-white-50">
                        <i class="fas fa-calendar-alt me-1"></i>
                        Member since: <?php echo $client['created_formatted']; ?>
                    </span>
                </div>
            </div>
            <div class="action-buttons">
                <a href="clients-edit.php?id=<?php echo $client_id; ?>" class="btn btn-light me-2">
                    <i class="fas fa-edit me-2"></i> Edit
                </a>
                <a href="orders-create.php?client_id=<?php echo $client_id; ?>" class="btn btn-success">
                    <i class="fas fa-plus me-2"></i> New Order
                </a>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column: Client Information -->
        <div class="col-md-8">
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card stat-card bg-primary text-white">
                        <div class="card-body">
                            <div class="stat-icon">
                                <i class="fas fa-boxes"></i>
                            </div>
                            <div class="stat-value"><?php echo $client['total_orders'] ?? 0; ?></div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card stat-card bg-success text-white">
                        <div class="card-body">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-value"><?php echo $client['completed_orders'] ?? 0; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card stat-card bg-info text-white">
                        <div class="card-body">
                            <div class="stat-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-value">KSh <?php echo number_format($client['total_spent'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Spent</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card stat-card bg-warning text-white">
                        <div class="card-body">
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <?php 
                            $avg_order = ($client['total_orders'] ?? 0) > 0 
                                ? $client['total_spent'] / $client['total_orders'] 
                                : 0;
                            ?>
                            <div class="stat-value">KSh <?php echo number_format($avg_order, 2); ?></div>
                            <div class="stat-label">Avg. Order</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client Details Card -->
            <div class="card client-info-card mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>Client Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-item d-flex">
                                <span class="info-label">Company Name:</span>
                                <span class="info-value"><?php echo htmlspecialchars($client['company_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Contact Person:</span>
                                <span class="info-value"><?php echo htmlspecialchars($client['contact_person'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Client Type:</span>
                                <span class="info-value">
                                    <span class="type-<?php echo $client['client_type'] ?? 'individual'; ?>">
                                        <?php echo ucfirst($client['client_type'] ?? 'Individual'); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Tax Number:</span>
                                <span class="info-value"><?php echo htmlspecialchars($client['tax_number'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item d-flex">
                                <span class="info-label">Account Balance:</span>
                                <span class="info-value">KSh <?php echo number_format($client['account_balance'] ?? 0, 2); ?></span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Credit Limit:</span>
                                <span class="info-value">KSh <?php echo number_format($client['credit_limit'] ?? 0, 2); ?></span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Status:</span>
                                <span class="info-value">
                                    <span class="status-<?php echo $client['status'] ?? 'active'; ?>">
                                        <?php echo ucfirst($client['status'] ?? 'Active'); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Client Since:</span>
                                <span class="info-value"><?php echo $client['created_formatted'] ?? 'N/A'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Information Card -->
            <div class="card client-info-card mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-address-book me-2"></i>Contact Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-item d-flex">
                                <span class="info-label">Email:</span>
                                <span class="info-value">
                                    <?php if (!empty($client['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($client['email']); ?>">
                                            <?php echo htmlspecialchars($client['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">
                                    <?php if (!empty($client['phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($client['phone']); ?>">
                                            <?php echo htmlspecialchars($client['phone']); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Alt Phone:</span>
                                <span class="info-value">
                                    <?php if (!empty($client['alternative_phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($client['alternative_phone']); ?>">
                                            <?php echo htmlspecialchars($client['alternative_phone']); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item d-flex">
                                <span class="info-label">Address:</span>
                                <span class="info-value"><?php echo htmlspecialchars($client['address'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">City:</span>
                                <span class="info-value"><?php echo htmlspecialchars($client['city'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-item d-flex">
                                <span class="info-label">Country:</span>
                                <span class="info-value"><?php echo htmlspecialchars($client['country'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Billing/Shipping Address -->
                    <div class="row mt-4">
                        <?php if (!empty($client['billing_address'])): ?>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Billing Address</h6>
                            <div class="bg-light p-3 rounded">
                                <?php echo nl2br(htmlspecialchars($client['billing_address'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($client['shipping_address'])): ?>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Shipping Address</h6>
                            <div class="bg-light p-3 rounded">
                                <?php echo nl2br(htmlspecialchars($client['shipping_address'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="card client-info-card">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-shopping-cart me-2"></i>Recent Orders
                    </h5>
                    <a href="orders.php?client_id=<?php echo $client_id; ?>" class="btn btn-sm btn-outline-primary">
                        View All Orders
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_orders)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Orders Yet</h5>
                            <p class="text-muted mb-4">This client hasn't placed any orders yet.</p>
                            <a href="orders-create.php?client_id=<?php echo $client_id; ?>" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i> Create First Order
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th>Created</th>
                                        <th>Delivery</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $order): ?>
                                        <tr class="order-row" onclick="window.location='orders-view.php?id=<?php echo $order['id']; ?>'">
                                            <td>
                                                <strong>#<?php echo $order['id']; ?></strong>
                                            </td>
                                            <td class="text-truncate" style="max-width: 200px;">
                                                <?php echo htmlspecialchars($order['description'] ?? 'No description'); ?>
                                            </td>
                                            <td>
                                                <span class="order-status-badge">
                                                    <?php echo $order['status_name'] ?? 'Unknown'; ?>
                                                </span>
                                            </td>
                                            <td class="amount-positive">
                                                KSh <?php echo number_format($order['total_amount'] ?? 0, 2); ?>
                                            </td>
                                            <td><?php echo $order['created_formatted'] ?? 'N/A'; ?></td>
                                            <td><?php echo $order['delivery_formatted'] ?? 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Quick Actions & Notes -->
        <div class="col-md-4">
            <!-- Quick Actions -->
            <div class="card client-info-card mb-4">
                <div class="card-header bg-white border-bottom">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="orders-create.php?client_id=<?php echo $client_id; ?>" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i> Create New Order
                        </a>
                        <a href="clients-edit.php?id=<?php echo $client_id; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-edit me-2"></i> Edit Client
                        </a>
                        <a href="invoices-create.php?client_id=<?php echo $client_id; ?>" class="btn btn-outline-success">
                            <i class="fas fa-file-invoice-dollar me-2"></i> Create Invoice
                        </a>
                        <a href="payments-create.php?client_id=<?php echo $client_id; ?>" class="btn btn-outline-info">
                            <i class="fas fa-credit-card me-2"></i> Record Payment
                        </a>
                        <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteClient()">
                            <i class="fas fa-trash me-2"></i> Delete Client
                        </button>
                    </div>
                </div>
            </div>

            <!-- Client Notes -->
            <div class="card client-info-card">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-sticky-note me-2"></i>Notes
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($client['notes'])): ?>
                        <?php echo nl2br(htmlspecialchars($client['notes'])); ?>
                    <?php else: ?>
                        <p class="text-muted text-center mb-0">No notes added yet</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Back to Clients -->
            <div class="mt-4">
                <a href="clients.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-arrow-left me-2"></i> Back to Clients List
                </a>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
function confirmDeleteClient() {
    if (confirm('Are you sure you want to delete this client?\n\nThis will also delete all associated orders, invoices, and payments.\n\nThis action cannot be undone.')) {
        window.location.href = 'clients-delete.php?id=<?php echo $client_id; ?>';
    }
}

// Make order rows clickable
document.addEventListener('DOMContentLoaded', function() {
    // Add tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>