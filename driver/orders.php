<?php
// orders.php - Admin Orders Management
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

$pageTitle = "Orders Management - Admin Dashboard";

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get orders with filters
$orders = [];
$total_orders = 0;
$total_pages = 1;

// Get all order statuses for filter dropdown
$statuses = [];
try {
    $status_stmt = $db->prepare("SELECT id, name, color FROM order_status ORDER BY id");
    $status_stmt->execute();
    $statuses = $status_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Status fetch error: " . $e->getMessage());
}

try {
    // Build WHERE clause for filters
    $where_conditions = [];
    $params = [];
    
    if (!empty($status_filter) && $status_filter != 'all') {
        $where_conditions[] = "o.status_id = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = "DATE(o.created_at) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "DATE(o.created_at) <= ?";
        $params[] = $date_to;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(o.id LIKE ? OR c.company_name LIKE ? OR o.pickup_address LIKE ? OR o.delivery_address LIKE ? OR t.plate_number LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM orders o
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN trucks t ON o.truck_id = t.id
        $where_clause
    ";
    
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute($params);
    $count_result = $count_stmt->fetch();
    $total_orders = $count_result['total'];
    $total_pages = ceil($total_orders / $limit);
    
    // Get orders with pagination
    $order_sql = "
        SELECT 
            o.*,
            c.company_name,
            c.email as client_email,
            c.phone as client_phone,
            os.name as status_name,
            os.color as status_color,
            t.plate_number as truck_plate,
            d.name as driver_name,
            cou.name as courier_name,
            DATE_FORMAT(o.created_at, '%d %b %Y %H:%i') as created_formatted,
            DATE_FORMAT(o.pickup_time, '%d %b %Y %H:%i') as pickup_time_formatted,
            DATE_FORMAT(o.delivery_time, '%d %b %Y %H:%i') as delivery_time_formatted,
            DATEDIFF(o.delivery_time, NOW()) as days_remaining,
            o.weight,
            o.dimensions,
            o.priority
        FROM orders o
        LEFT JOIN clients c ON o.client_id = c.id
        LEFT JOIN order_status os ON o.status_id = os.id
        LEFT JOIN trucks t ON o.truck_id = t.id
        LEFT JOIN drivers dr ON t.driver_id = dr.id
        LEFT JOIN users d ON dr.user_id = d.id
        LEFT JOIN couriers cou ON t.courier_id = cou.id
        $where_clause
        ORDER BY o.priority DESC, o.delivery_time ASC, o.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $order_params = $params;
    $order_params[] = $limit;
    $order_params[] = $offset;
    
    $order_stmt = $db->prepare($order_sql);
    $order_stmt->execute($order_params);
    $orders = $order_stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Orders fetch error: " . $e->getMessage());
    $error_message = "Error loading orders: " . $e->getMessage();
}

// Get dashboard statistics
$stats = [];
try {
    // Total orders
    $total_stmt = $db->prepare("SELECT COUNT(*) as total FROM orders");
    $total_stmt->execute();
    $stats['total_orders'] = $total_stmt->fetch()['total'];
    
    // Today's orders
    $today_stmt = $db->prepare("SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()");
    $today_stmt->execute();
    $stats['today_orders'] = $today_stmt->fetch()['total'];
    
    // Pending orders
    $pending_stmt = $db->prepare("SELECT COUNT(*) as total FROM orders WHERE status_id IN (1,2,3)");
    $pending_stmt->execute();
    $stats['pending_orders'] = $pending_stmt->fetch()['total'];
    
    // Delivered orders
    $delivered_stmt = $db->prepare("SELECT COUNT(*) as total FROM orders WHERE status_id = 6");
    $delivered_stmt->execute();
    $stats['delivered_orders'] = $delivered_stmt->fetch()['total'];
    
    // Total revenue
    $revenue_stmt = $db->prepare("SELECT SUM(shipping_cost) as total FROM orders WHERE status_id = 6");
    $revenue_stmt->execute();
    $stats['total_revenue'] = $revenue_stmt->fetch()['total'] ?: 0;
    
} catch (Exception $e) {
    error_log("Stats fetch error: " . $e->getMessage());
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/admin/admin-sidebar.php';
?>

<style>
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

/* Order status colors */
.status-badge {
    padding: 0.35rem 0.65rem;
    font-size: 0.75rem;
    font-weight: 600;
}

/* Priority badges */
.priority-high {
    background-color: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.priority-medium {
    background-color: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}

.priority-low {
    background-color: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

/* Stats cards */
.stats-card {
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

/* Table styling */
.table-orders {
    font-size: 0.9rem;
}

.table-orders th {
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.table-orders tbody tr:hover {
    background-color: #f8f9fa;
}

/* Responsive table */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Filter form */
.filter-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
}

/* Action buttons */
.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Pagination */
.pagination .page-link {
    color: #3b82f6;
}

.pagination .page-item.active .page-link {
    background-color: #3b82f6;
    border-color: #3b82f6;
}

/* Order ID link */
.order-id-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
}

.order-id-link:hover {
    text-decoration: underline;
    color: #1d4ed8;
}
</style>

<!-- Admin Container -->
<div class="admin-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #3b82f6;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-boxes me-2"></i>Orders Management
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Manage and track all delivery orders in the system
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="orders-create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i> Create Order
                </a>
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i> Print
                </button>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card stats-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-2 text-muted">Total Orders</h6>
                            <h3 class="card-title mb-0"><?php echo number_format($stats['total_orders'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-primary rounded-circle p-3">
                            <i class="fas fa-box text-white fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card stats-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-2 text-muted">Today's Orders</h6>
                            <h3 class="card-title mb-0"><?php echo number_format($stats['today_orders'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-success rounded-circle p-3">
                            <i class="fas fa-calendar-day text-white fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card stats-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-2 text-muted">Pending Orders</h6>
                            <h3 class="card-title mb-0"><?php echo number_format($stats['pending_orders'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-warning rounded-circle p-3">
                            <i class="fas fa-clock text-white fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card stats-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-subtitle mb-2 text-muted">Total Revenue</h6>
                            <h3 class="card-title mb-0">KSh <?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></h3>
                        </div>
                        <div class="bg-info rounded-circle p-3">
                            <i class="fas fa-money-bill-wave text-white fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card filter-card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">
                <i class="fas fa-filter me-2"></i>Filter Orders
            </h5>
            
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo $status['id']; ?>" 
                                <?php echo $status_filter == $status['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" name="date_from" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" name="date_to" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Order ID, Client, Address, Truck...">
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <div class="d-grid gap-2 w-100">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
            
            <?php if ($status_filter || $date_from || $date_to || $search): ?>
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Showing filtered results: 
                        <?php echo $total_orders; ?> order<?php echo $total_orders != 1 ? 's' : ''; ?> found
                        <?php if ($status_filter && $status_filter != 'all'): ?>
                            | Status: <?php 
                                $filtered_status = array_filter($statuses, function($s) use ($status_filter) {
                                    return $s['id'] == $status_filter;
                                });
                                echo !empty($filtered_status) ? htmlspecialchars(reset($filtered_status)['name']) : '';
                            ?>
                        <?php endif; ?>
                        <?php if ($search): ?>
                            | Search: "<?php echo htmlspecialchars($search); ?>"
                        <?php endif; ?>
                    </small>
                    <a href="orders.php" class="btn btn-sm btn-outline-secondary ms-3">
                        <i class="fas fa-times me-1"></i> Clear Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list me-2"></i>Orders List
                </h5>
                <div class="text-muted small">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                    (<?php echo number_format($total_orders); ?> total orders)
                </div>
            </div>
            
            <?php if (empty($orders)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No orders found</h5>
                    <p class="text-muted mb-4">
                        <?php if ($status_filter || $date_from || $date_to || $search): ?>
                            Try adjusting your filters
                        <?php else: ?>
                            No orders have been created yet
                        <?php endif; ?>
                    </p>
                    <a href="orders-create.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i> Create First Order
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-orders table-hover">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Client</th>
                                <th>Pickup → Delivery</th>
                                <th>Status</th>
                                <th>Truck/Driver</th>
                                <th>Delivery Time</th>
                                <th>Priority</th>
                                <th>Cost</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>
                                        <a href="orders-view.php?id=<?php echo $order['id']; ?>" 
                                           class="order-id-link" title="View Order Details">
                                            #<?php echo $order['id']; ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?php echo htmlspecialchars($order['company_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($order['client_phone']); ?></div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div class="text-truncate" style="max-width: 200px;" 
                                                 title="<?php echo htmlspecialchars($order['pickup_address']); ?>">
                                                <i class="fas fa-arrow-up text-success me-1"></i>
                                                <?php echo htmlspecialchars($order['pickup_address']); ?>
                                            </div>
                                            <div class="text-truncate mt-1" style="max-width: 200px;" 
                                                 title="<?php echo htmlspecialchars($order['delivery_address']); ?>">
                                                <i class="fas fa-arrow-down text-danger me-1"></i>
                                                <?php echo htmlspecialchars($order['delivery_address']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge status-badge" style="background-color: <?php echo $order['status_color']; ?>;">
                                            <?php echo htmlspecialchars($order['status_name']); ?>
                                        </span>
                                        <?php if ($order['days_remaining'] < 0 && $order['status_id'] != 6): ?>
                                            <span class="badge bg-danger ms-1">Overdue</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($order['truck_plate']): ?>
                                            <div class="small">
                                                <i class="fas fa-truck me-1"></i><?php echo htmlspecialchars($order['truck_plate']); ?>
                                            </div>
                                            <?php if ($order['driver_name']): ?>
                                                <div class="small text-muted">
                                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($order['driver_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small"><?php echo $order['delivery_time_formatted']; ?></div>
                                        <?php if ($order['status_id'] != 6): ?>
                                            <?php if ($order['days_remaining'] >= 0): ?>
                                                <div class="text-success small"><?php echo $order['days_remaining']; ?> days left</div>
                                            <?php else: ?>
                                                <div class="text-danger small"><?php echo abs($order['days_remaining']); ?> days overdue</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($order['priority'] == 'high'): ?>
                                            <span class="badge priority-high">High</span>
                                        <?php elseif ($order['priority'] == 'medium'): ?>
                                            <span class="badge priority-medium">Medium</span>
                                        <?php else: ?>
                                            <span class="badge priority-low">Low</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold">KSh <?php echo number_format($order['shipping_cost'], 2); ?></div>
                                        <?php if ($order['weight']): ?>
                                            <div class="small text-muted"><?php echo $order['weight']; ?> kg</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small"><?php echo $order['created_formatted']; ?></div>
                                        <?php if ($order['courier_name']): ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars($order['courier_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="orders-view.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-outline-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="orders-edit.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-outline-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="orders-assign.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-outline-primary" title="Assign">
                                                <i class="fas fa-truck-loading"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $order['id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Orders pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <!-- Previous Page -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="orders.php?<?php 
                                        $query = $_GET;
                                        $query['page'] = $page - 1;
                                        echo http_build_query($query);
                                   ?>" 
                                   aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" 
                                       href="orders.php?<?php 
                                            $query = $_GET;
                                            $query['page'] = $i;
                                            echo http_build_query($query);
                                       ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="orders.php?<?php 
                                        $query = $_GET;
                                        $query['page'] = $page + 1;
                                        echo http_build_query($query);
                                   ?>" 
                                   aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h5>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <a href="orders-create.php" class="btn btn-outline-primary w-100">
                                <i class="fas fa-plus-circle me-2"></i> Create New Order
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="orders-import.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-file-import me-2"></i> Import Orders
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="orders-export.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-success w-100">
                                <i class="fas fa-file-export me-2"></i> Export Orders
                            </a>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-outline-info w-100" onclick="printOrderSummary()">
                                <i class="fas fa-print me-2"></i> Print Summary
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="fas fa-chart-pie me-2"></i>Order Status Overview
                    </h5>
                    <div class="small">
                        <?php 
                        $status_counts = [];
                        try {
                            $status_count_stmt = $db->prepare("
                                SELECT os.name, os.color, COUNT(o.id) as count
                                FROM orders o
                                JOIN order_status os ON o.status_id = os.id
                                GROUP BY os.id, os.name, os.color
                                ORDER BY os.id
                            ");
                            $status_count_stmt->execute();
                            $status_counts = $status_count_stmt->fetchAll();
                        } catch (Exception $e) {
                            error_log("Status count error: " . $e->getMessage());
                        }
                        
                        if (!empty($status_counts)): 
                            foreach ($status_counts as $status): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>
                                        <span class="badge" style="background-color: <?php echo $status['color']; ?>; width: 10px; height: 10px; display: inline-block;"></span>
                                        <?php echo htmlspecialchars($status['name']); ?>
                                    </span>
                                    <span class="fw-medium"><?php echo number_format($status['count']); ?></span>
                                </div>
                            <?php endforeach; 
                        else: ?>
                            <div class="text-muted">No status data available</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh page every 2 minutes
    setInterval(() => {
        location.reload();
    }, 120000);
    
    // Add tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Make table rows clickable
    document.querySelectorAll('.table-orders tbody tr').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on action buttons
            if (!e.target.closest('.btn-group')) {
                const viewLink = this.querySelector('a.order-id-link');
                if (viewLink) {
                    window.location.href = viewLink.href;
                }
            }
        });
    });
    
    // Initialize date pickers
    const today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('input[type="date"]').forEach(input => {
        if (!input.value) {
            if (input.name === 'date_to') {
                input.value = today;
            }
            if (input.name === 'date_from') {
                const weekAgo = new Date();
                weekAgo.setDate(weekAgo.getDate() - 7);
                input.value = weekAgo.toISOString().split('T')[0];
            }
        }
    });
});

function confirmDelete(orderId) {
    if (confirm('Are you sure you want to delete order #' + orderId + '? This action cannot be undone.')) {
        // In a real implementation, this would be an AJAX call or form submission
        window.location.href = 'orders-delete.php?id=' + orderId;
    }
}

function printOrderSummary() {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Order Summary - <?php echo date('Y-m-d'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                h1, h2, h3, h4, h5, h6 { color: #333; }
                .header { text-align: center; margin-bottom: 20px; }
                .summary { margin: 20px 0; }
                .table { width: 100%; border-collapse: collapse; }
                .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .table th { background-color: #f4f4f4; }
                .badge { padding: 2px 6px; border-radius: 3px; font-size: 10px; }
                .text-right { text-align: right; }
                .page-break { page-break-before: always; }
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Order Management System</h2>
                <h3>Order Summary Report</h3>
                <p>Generated on: ${new Date().toLocaleString()}</p>
                <p>Total Orders: <?php echo number_format($total_orders); ?></p>
            </div>
            
            <div class="summary">
                <h4>Statistics</h4>
                <p>Total Orders: <?php echo number_format($stats['total_orders'] ?? 0); ?></p>
                <p>Today's Orders: <?php echo number_format($stats['today_orders'] ?? 0); ?></p>
                <p>Pending Orders: <?php echo number_format($stats['pending_orders'] ?? 0); ?></p>
                <p>Total Revenue: KSh <?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></p>
            </div>
            
            <h4>Orders List (Page <?php echo $page; ?> of <?php echo $total_pages; ?>)</h4>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Delivery Time</th>
                        <th class="text-right">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>#<?php echo $order['id']; ?></td>
                            <td><?php echo htmlspecialchars($order['company_name']); ?></td>
                            <td>
                                <span class="badge" style="background-color: <?php echo $order['status_color']; ?>;">
                                    <?php echo htmlspecialchars($order['status_name']); ?>
                                </span>
                            </td>
                            <td><?php echo $order['delivery_time_formatted']; ?></td>
                            <td class="text-right">KSh <?php echo number_format($order['shipping_cost'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="no-print" style="margin-top: 20px; text-align: center;">
                <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                    Print Report
                </button>
                <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;">
                    Close Window
                </button>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
}

// Quick filter by status
function filterByStatus(statusId) {
    window.location.href = 'orders.php?status=' + statusId;
}

// Export filtered results
function exportOrders(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = 'orders-export.php?' + params.toString();
}
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>