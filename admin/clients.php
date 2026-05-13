<?php
// clients.php - Admin Clients Management
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

$pageTitle = "Clients Management - Admin Dashboard";

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get clients with filters
$clients = [];
$total_clients = 0;
$total_pages = 1;

try {
    // Build WHERE clause for filters
    $where_conditions = [];
    $params = [];
    
    if (!empty($status_filter) && $status_filter != 'all') {
        $where_conditions[] = "c.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($type_filter) && $type_filter != 'all') {
        $where_conditions[] = "c.client_type = ?";
        $params[] = $type_filter;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(c.company_name LIKE ? OR c.contact_person LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
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
        FROM clients c
        $where_clause
    ";
    
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute($params);
    $count_result = $count_stmt->fetch();
    $total_clients = $count_result['total'];
    $total_pages = ceil($total_clients / $limit);
    
    // Get clients with pagination
    $client_sql = "
        SELECT 
            c.*,
            DATE_FORMAT(c.created_at, '%d %b %Y') as created_formatted,
            DATE_FORMAT(c.updated_at, '%d %b %Y %H:%i') as updated_formatted,
            (SELECT COUNT(*) FROM orders WHERE client_id = c.id) as total_orders,
            (SELECT COUNT(*) FROM orders WHERE client_id = c.id AND status_id = 6) as completed_orders,
            (SELECT SUM(total_amount) FROM orders WHERE client_id = c.id AND status_id = 6) as total_spent
        FROM clients c
        $where_clause
        ORDER BY c.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $client_params = $params;
    $client_params[] = $limit;
    $client_params[] = $offset;
    
    $client_stmt = $db->prepare($client_sql);
    $client_stmt->execute($client_params);
    $clients = $client_stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Clients fetch error: " . $e->getMessage());
    $error_message = "Error loading clients: " . $e->getMessage();
}

// Get dashboard statistics
$stats = [];
try {
    // Total clients
    $total_stmt = $db->prepare("SELECT COUNT(*) as total FROM clients");
    $total_stmt->execute();
    $stats['total_clients'] = $total_stmt->fetch()['total'];
    
    // Active clients
    $active_stmt = $db->prepare("SELECT COUNT(*) as total FROM clients WHERE status = 'active'");
    $active_stmt->execute();
    $stats['active_clients'] = $active_stmt->fetch()['total'];
    
    // Corporate clients
    $corporate_stmt = $db->prepare("SELECT COUNT(*) as total FROM clients WHERE client_type = 'corporate'");
    $corporate_stmt->execute();
    $stats['corporate_clients'] = $corporate_stmt->fetch()['total'];
    
    // Individual clients
    $individual_stmt = $db->prepare("SELECT COUNT(*) as total FROM clients WHERE client_type = 'individual'");
    $individual_stmt->execute();
    $stats['individual_clients'] = $individual_stmt->fetch()['total'];
    
    // Total revenue from clients
    $revenue_stmt = $db->prepare("
        SELECT SUM(o.total_amount) as total 
        FROM orders o 
        WHERE o.status_id = 6
    ");
    $revenue_stmt->execute();
    $stats['total_revenue'] = $revenue_stmt->fetch()['total'] ?: 0;
    
} catch (Exception $e) {
    error_log("Stats fetch error: " . $e->getMessage());
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
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

/* Client status colors */
.status-active {
    background-color: #d1e7dd;
    color: #0f5132;
    border: 1px solid #badbcc;
}

.status-inactive {
    background-color: #f8d7da;
    color: #842029;
    border: 1px solid #f5c2c7;
}

.status-pending {
    background-color: #fff3cd;
    color: #664d03;
    border: 1px solid #ffecb5;
}

/* Client type badges */
.type-corporate {
    background-color: #cfe2ff;
    color: #052c65;
    border: 1px solid #9ec5fe;
}

.type-individual {
    background-color: #e2e3e5;
    color: #2b2f32;
    border: 1px solid #c4c8cb;
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
.table-clients {
    font-size: 0.9rem;
}

.table-clients th {
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.table-clients tbody tr:hover {
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

/* Client name link */
.client-name-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
}

.client-name-link:hover {
    text-decoration: underline;
    color: #1d4ed8;
}

/* Order stats */
.order-stats {
    font-size: 0.8rem;
}

.order-count {
    font-weight: bold;
    color: #3b82f6;
}

.revenue-amount {
    font-weight: bold;
    color: #059669;
}
</style>

<!-- Admin Container -->
<div class="admin-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #059669;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-users me-2"></i>Clients Management
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Manage and track all clients in the system
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="clients-create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i> Add Client
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
                            <h6 class="card-subtitle mb-2 text-muted">Total Clients</h6>
                            <h3 class="card-title mb-0"><?php echo number_format($stats['total_clients'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-primary rounded-circle p-3">
                            <i class="fas fa-users text-white fa-2x"></i>
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
                            <h6 class="card-subtitle mb-2 text-muted">Active Clients</h6>
                            <h3 class="card-title mb-0"><?php echo number_format($stats['active_clients'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-success rounded-circle p-3">
                            <i class="fas fa-user-check text-white fa-2x"></i>
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
                            <h6 class="card-subtitle mb-2 text-muted">Corporate Clients</h6>
                            <h3 class="card-title mb-0"><?php echo number_format($stats['corporate_clients'] ?? 0); ?></h3>
                        </div>
                        <div class="bg-info rounded-circle p-3">
                            <i class="fas fa-building text-white fa-2x"></i>
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
                        <div class="bg-warning rounded-circle p-3">
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
                <i class="fas fa-filter me-2"></i>Filter Clients
            </h5>
            
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Client Type</label>
                    <select class="form-select" name="type">
                        <option value="all" <?php echo $type_filter == 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="corporate" <?php echo $type_filter == 'corporate' ? 'selected' : ''; ?>>Corporate</option>
                        <option value="individual" <?php echo $type_filter == 'individual' ? 'selected' : ''; ?>>Individual</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Company name, contact person, email, phone...">
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <div class="d-grid gap-2 w-100">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
            
            <?php if ($status_filter || $type_filter || $search): ?>
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Showing filtered results: 
                        <?php echo $total_clients; ?> client<?php echo $total_clients != 1 ? 's' : ''; ?> found
                        <?php if ($status_filter && $status_filter != 'all'): ?>
                            | Status: <?php echo ucfirst($status_filter); ?>
                        <?php endif; ?>
                        <?php if ($type_filter && $type_filter != 'all'): ?>
                            | Type: <?php echo ucfirst($type_filter); ?>
                        <?php endif; ?>
                        <?php if ($search): ?>
                            | Search: "<?php echo htmlspecialchars($search); ?>"
                        <?php endif; ?>
                    </small>
                    <a href="clients.php" class="btn btn-sm btn-outline-secondary ms-3">
                        <i class="fas fa-times me-1"></i> Clear Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Clients Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list me-2"></i>Clients List
                </h5>
                <div class="text-muted small">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                    (<?php echo number_format($total_clients); ?> total clients)
                </div>
            </div>
            
            <?php if (empty($clients)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No clients found</h5>
                    <p class="text-muted mb-4">
                        <?php if ($status_filter || $type_filter || $search): ?>
                            Try adjusting your filters
                        <?php else: ?>
                            No clients have been added yet
                        <?php endif; ?>
                    </p>
                    <a href="clients-create.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i> Add First Client
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clients table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Client Information</th>
                                <th>Contact Details</th>
                                <th>Address</th>
                                <th>Status & Type</th>
                                <th>Order Stats</th>
                                <th>Total Spent</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo $client['id']; ?></strong>
                                    </td>
                                    <td>
                                        <div class="fw-medium">
                                            <a href="clients-view.php?id=<?php echo $client['id']; ?>" 
                                               class="client-name-link" title="View Client Details">
                                                <?php echo htmlspecialchars($client['company_name']); ?>
                                            </a>
                                        </div>
                                        <?php if ($client['contact_person']): ?>
                                            <div class="small text-muted">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo htmlspecialchars($client['contact_person']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($client['client_type'] == 'corporate'): ?>
                                            <div class="small text-muted">
                                                <i class="fas fa-id-card me-1"></i>
                                                <?php echo htmlspecialchars($client['tax_number'] ?? 'N/A'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($client['email']): ?>
                                            <div class="small">
                                                <i class="fas fa-envelope me-1"></i>
                                                <?php echo htmlspecialchars($client['email']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($client['phone']): ?>
                                            <div class="small">
                                                <i class="fas fa-phone me-1"></i>
                                                <?php echo htmlspecialchars($client['phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($client['alternative_phone']): ?>
                                            <div class="small text-muted">
                                                <i class="fas fa-phone-alt me-1"></i>
                                                <?php echo htmlspecialchars($client['alternative_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-truncate" style="max-width: 200px;" 
                                             title="<?php echo htmlspecialchars($client['address']); ?>">
                                            <i class="fas fa-map-marker-alt me-1"></i>
                                            <?php echo htmlspecialchars($client['address']); ?>
                                        </div>
                                        <?php if ($client['city']): ?>
                                            <div class="small text-muted">
                                                <?php echo htmlspecialchars($client['city']); ?>
                                                <?php if ($client['country']): ?>
                                                    , <?php echo htmlspecialchars($client['country']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge status-<?php echo $client['status']; ?>">
                                            <?php echo ucfirst($client['status']); ?>
                                        </span>
                                        <br>
                                        <span class="badge type-<?php echo $client['client_type']; ?> mt-1">
                                            <?php echo ucfirst($client['client_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="order-stats">
                                            <div>
                                                <span class="order-count"><?php echo $client['total_orders'] ?? 0; ?></span> total orders
                                            </div>
                                            <div class="text-success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                <?php echo $client['completed_orders'] ?? 0; ?> completed
                                            </div>
                                            <?php 
                                            $completion_rate = ($client['total_orders'] > 0) 
                                                ? round(($client['completed_orders'] / $client['total_orders']) * 100, 1)
                                                : 0;
                                            ?>
                                            <div class="small text-muted">
                                                <?php echo $completion_rate; ?>% completion rate
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="revenue-amount">
                                            KSh <?php echo number_format($client['total_spent'] ?? 0, 2); ?>
                                        </div>
                                        <?php if ($client['total_orders'] > 0): 
                                            $avg_order = $client['total_spent'] / $client['total_orders'];
                                        ?>
                                            <div class="small text-muted">
                                                Avg: KSh <?php echo number_format($avg_order, 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small"><?php echo $client['created_formatted']; ?></div>
                                        <div class="small text-muted">
                                            Updated: <?php echo $client['updated_formatted']; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="clients-view.php?id=<?php echo $client['id']; ?>" 
                                               class="btn btn-outline-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="clients-edit.php?id=<?php echo $client['id']; ?>" 
                                               class="btn btn-outline-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="orders.php?client_id=<?php echo $client['id']; ?>" 
                                               class="btn btn-outline-primary" title="View Orders">
                                                <i class="fas fa-boxes"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $client['id']; ?>)" title="Delete">
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
                    <nav aria-label="Clients pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <!-- Previous Page -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="clients.php?<?php 
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
                                       href="clients.php?<?php 
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
                                   href="clients.php?<?php 
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
                            <a href="clients-create.php" class="btn btn-outline-primary w-100">
                                <i class="fas fa-plus-circle me-2"></i> Add New Client
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="clients-import.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-file-import me-2"></i> Import Clients
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="clients-export.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-success w-100">
                                <i class="fas fa-file-export me-2"></i> Export Clients
                            </a>
                        </div>
                        <div class="col-md-6">
                            <button class="btn btn-outline-info w-100" onclick="printClientSummary()">
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
                        <i class="fas fa-chart-pie me-2"></i>Client Overview
                    </h5>
                    <div class="small">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Active Clients:</span>
                            <span class="fw-medium"><?php echo number_format($stats['active_clients'] ?? 0); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Corporate Clients:</span>
                            <span class="fw-medium"><?php echo number_format($stats['corporate_clients'] ?? 0); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Individual Clients:</span>
                            <span class="fw-medium"><?php echo number_format($stats['individual_clients'] ?? 0); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Inactive Clients:</span>
                            <?php $inactive = ($stats['total_clients'] ?? 0) - ($stats['active_clients'] ?? 0); ?>
                            <span class="fw-medium"><?php echo number_format($inactive); ?></span>
                        </div>
                    </div>
                    
                    <div class="alert alert-info small mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Tip:</strong> Corporate clients generate 60% more revenue on average than individual clients.
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
    document.querySelectorAll('.table-clients tbody tr').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function(e) {
            // Don't trigger if clicking on action buttons
            if (!e.target.closest('.btn-group')) {
                const viewLink = this.querySelector('a.client-name-link');
                if (viewLink) {
                    window.location.href = viewLink.href;
                }
            }
        });
    });
});

function confirmDelete(clientId) {
    if (confirm('Are you sure you want to delete this client? All associated orders will also be deleted. This action cannot be undone.')) {
        window.location.href = 'clients-delete.php?id=' + clientId;
    }
}

function printClientSummary() {
    const printWindow = window.open('', '_blank');
    let htmlContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Client Summary - ${new Date().toLocaleDateString()}</title>
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
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Client Management System</h2>
                <h3>Client Summary Report</h3>
                <p>Generated on: ${new Date().toLocaleString()}</p>
                <p>Total Clients: ${<?php echo $total_clients; ?>}</p>
            </div>
            
            <div class="summary">
                <h4>Statistics</h4>
                <p>Total Clients: ${<?php echo $stats['total_clients'] ?? 0; ?>}</p>
                <p>Active Clients: ${<?php echo $stats['active_clients'] ?? 0; ?>}</p>
                <p>Corporate Clients: ${<?php echo $stats['corporate_clients'] ?? 0; ?>}</p>
                <p>Individual Clients: ${<?php echo $stats['individual_clients'] ?? 0; ?>}</p>
                <p>Total Revenue: KSh ${<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?>}</p>
            </div>
            
            <h4>Clients List (Page ${<?php echo $page; ?>} of ${<?php echo $total_pages; ?>})</h4>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company Name</th>
                        <th>Contact Person</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Total Orders</th>
                        <th class="text-right">Total Spent</th>
                    </tr>
                </thead>
                <tbody>`;
    
    // Add client rows
    <?php foreach ($clients as $client): ?>
        htmlContent += `
                    <tr>
                        <td>#${<?php echo $client['id']; ?>}</td>
                        <td>${<?php echo json_encode(htmlspecialchars($client['company_name'])); ?>}</td>
                        <td>${<?php echo json_encode(htmlspecialchars($client['contact_person'] ?? 'N/A')); ?>}</td>
                        <td>${<?php echo json_encode(ucfirst($client['client_type'])); ?>}</td>
                        <td>
                            <span class="badge" style="background-color: ${<?php 
                                echo $client['status'] == 'active' ? "'#d1e7dd'" : 
                                       ($client['status'] == 'inactive' ? "'#f8d7da'" : "'#fff3cd'"); ?>}">
                                ${<?php echo json_encode(ucfirst($client['status'])); ?>}
                            </span>
                        </td>
                        <td>${<?php echo $client['total_orders'] ?? 0; ?>}</td>
                        <td class="text-right">KSh ${<?php echo number_format($client['total_spent'] ?? 0, 2); ?>}</td>
                    </tr>`;
    <?php endforeach; ?>
    
    htmlContent += `
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
        </html>`;
    
    printWindow.document.write(htmlContent);
    printWindow.document.close();
}

// Quick filter functions
function filterActiveClients() {
    window.location.href = 'clients.php?status=active';
}

function filterCorporateClients() {
    window.location.href = 'clients.php?type=corporate';
}

function filterHighValueClients() {
    alert('This feature would filter clients with high total spending');
    // In a real implementation, this would redirect with appropriate filters
}
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>