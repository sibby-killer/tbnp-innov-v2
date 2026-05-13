<?php
// trucks.php
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
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$courier_filter = $_GET['courier'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get couriers for filter dropdown
$couriers = [];
try {
    $courier_query = "SELECT id, name FROM couriers ORDER BY name";
    $courier_stmt = $db->query($courier_query);
    $couriers = $courier_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching couriers: " . $e->getMessage());
}

// Build query with filters
$query = "SELECT 
            t.*, 
            c.name as courier_name,
            u.name as driver_name,
            d.license_number
          FROM trucks t
          LEFT JOIN couriers c ON t.courier_id = c.id
          LEFT JOIN drivers d ON t.driver_id = d.id
          LEFT JOIN users u ON d.user_id = u.id
          WHERE 1=1";

$params = [];

// Apply search filter
if (!empty($search)) {
    $query .= " AND (t.plate_number LIKE ? OR t.model LIKE ? OR t.brand LIKE ? OR t.insurance_number LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

// Apply status filter
if (!empty($status_filter)) {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

// Apply courier filter
if (!empty($courier_filter)) {
    $query .= " AND t.courier_id = ?";
    $params[] = $courier_filter;
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM ($query) as filtered";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($params);
$total_count = $count_stmt->fetch()['total'];
$total_pages = ceil($total_count / $limit);

// Add sorting and pagination
$query .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

// Fetch trucks
$trucks = [];
try {
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $trucks = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error loading trucks: " . $e->getMessage();
    error_log("Trucks list error: " . $e->getMessage());
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $truck_id = $_GET['id'];
    
    try {
        $delete_query = "DELETE FROM trucks WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$truck_id]);
        
        $_SESSION['success_message'] = "Truck deleted successfully!";
        header('Location: trucks.php');
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error deleting truck: " . $e->getMessage();
        header('Location: trucks.php');
        exit();
    }
}

$pageTitle = "Trucks Management";

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
                    <i class="fas fa-truck me-2"></i>Trucks Management
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Manage all trucks in the system (<?php echo $total_count; ?> trucks)
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="trucks-add.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i> Add New Truck
                </a>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo $_SESSION['error_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Filters Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Plate number, model, brand, insurance...">
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="assigned" <?php echo $status_filter == 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                        <option value="on_delivery" <?php echo $status_filter == 'on_delivery' ? 'selected' : ''; ?>>On Delivery</option>
                        <option value="maintenance" <?php echo $status_filter == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="out_of_service" <?php echo $status_filter == 'out_of_service' ? 'selected' : ''; ?>>Out of Service</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="courier" class="form-label">Courier</label>
                    <select class="form-select" id="courier" name="courier">
                        <option value="">All Couriers</option>
                        <?php foreach ($couriers as $courier): ?>
                            <option value="<?php echo $courier['id']; ?>" 
                                    <?php echo $courier_filter == $courier['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($courier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i> Filter
                    </button>
                </div>
                <?php if ($search || $status_filter || $courier_filter): ?>
                    <div class="col-12">
                        <a href="trucks.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times me-2"></i> Clear Filters
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Trucks List Card -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <?php if (empty($trucks)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-truck fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No trucks found</h5>
                    <p class="text-muted mb-4">Try adjusting your filters or add a new truck</p>
                    <a href="trucks-add.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i> Add New Truck
                    </a>
                </div>
            <?php else: ?>
                <!-- Stats Overview -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-0">Total Trucks</h6>
                                        <h3 class="mb-0"><?php echo $total_count; ?></h3>
                                    </div>
                                    <div class="bg-primary rounded-circle p-3">
                                        <i class="fas fa-truck fa-lg text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-0">Available</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                                $available_count = array_filter($trucks, function($t) { 
                                                    return $t['status'] == 'available'; 
                                                });
                                                echo count($available_count);
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="bg-success rounded-circle p-3">
                                        <i class="fas fa-check fa-lg text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-0">On Delivery</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                                $delivery_count = array_filter($trucks, function($t) { 
                                                    return $t['status'] == 'on_delivery'; 
                                                });
                                                echo count($delivery_count);
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="bg-warning rounded-circle p-3">
                                        <i class="fas fa-shipping-fast fa-lg text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="text-muted mb-0">Maintenance</h6>
                                        <h3 class="mb-0">
                                            <?php 
                                                $maintenance_count = array_filter($trucks, function($t) { 
                                                    return $t['status'] == 'maintenance'; 
                                                });
                                                echo count($maintenance_count);
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="bg-danger rounded-circle p-3">
                                        <i class="fas fa-tools fa-lg text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Trucks Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Plate Number</th>
                                <th>Model & Brand</th>
                                <th>Capacity</th>
                                <th>Driver</th>
                                <th>Courier</th>
                                <th>Status</th>
                                <th>Insurance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trucks as $truck): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($truck['plate_number']); ?></strong>
                                        <div class="text-muted small">ID: <?php echo $truck['id']; ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($truck['model']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($truck['brand'] ?? 'N/A'); ?> (<?php echo $truck['year'] ?? 'N/A'; ?>)</div>
                                    </td>
                                    <td>
                                        <div class="badge bg-info text-dark">
                                            <?php echo $truck['capacity']; ?> tons
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <?php echo ucfirst($truck['fuel_type'] ?? 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($truck['driver_name']): ?>
                                            <div><?php echo htmlspecialchars($truck['driver_name']); ?></div>
                                            <div class="text-muted small"><?php echo $truck['license_number']; ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($truck['courier_name'] ?? 'N/A'); ?></div>
                                        <div class="text-muted small">ID: <?php echo $truck['courier_id']; ?></div>
                                    </td>
                                    <td>
                                        <?php
                                        $status_badge = '';
                                        switch ($truck['status']) {
                                            case 'available':
                                                $status_badge = 'badge bg-success';
                                                break;
                                            case 'assigned':
                                                $status_badge = 'badge bg-primary';
                                                break;
                                            case 'on_delivery':
                                                $status_badge = 'badge bg-warning';
                                                break;
                                            case 'maintenance':
                                                $status_badge = 'badge bg-danger';
                                                break;
                                            case 'out_of_service':
                                                $status_badge = 'badge bg-secondary';
                                                break;
                                            default:
                                                $status_badge = 'badge bg-light text-dark';
                                        }
                                        ?>
                                        <span class="<?php echo $status_badge; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $truck['status'])); ?>
                                        </span>
                                        <?php if ($truck['current_location']): ?>
                                            <div class="text-muted small mt-1">
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <?php echo htmlspecialchars($truck['current_location']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($truck['insurance_number']): ?>
                                            <div class="small"><?php echo htmlspecialchars($truck['insurance_number']); ?></div>
                                            <?php if ($truck['insurance_expiry']): ?>
                                                <?php
                                                $expiry_date = new DateTime($truck['insurance_expiry']);
                                                $today = new DateTime();
                                                $days_diff = $today->diff($expiry_date)->days;
                                                $insurance_class = ($days_diff <= 30) ? 'text-danger' : (($days_diff <= 60) ? 'text-warning' : 'text-success');
                                                ?>
                                                <div class="small <?php echo $insurance_class; ?>">
                                                    Exp: <?php echo date('M d, Y', strtotime($truck['insurance_expiry'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">No insurance</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="trucks-view.php?id=<?php echo $truck['id']; ?>" 
                                               class="btn btn-outline-info" 
                                               title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="trucks-edit.php?id=<?php echo $truck['id']; ?>" 
                                               class="btn btn-outline-warning" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="trucks.php?action=delete&id=<?php echo $truck['id']; ?>" 
                                               class="btn btn-outline-danger" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this truck? This action cannot be undone.');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

                <!-- Export Options -->
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div class="text-muted small">
                        Showing <?php echo count($trucks); ?> of <?php echo $total_count; ?> trucks
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-success">
                            <i class="fas fa-file-excel me-2"></i> Export to Excel
                        </button>
                        <button class="btn btn-sm btn-outline-primary ms-2">
                            <i class="fas fa-print me-2"></i> Print List
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Confirm delete action
    document.querySelectorAll('a.btn-outline-danger').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this truck? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // Quick status update
    document.querySelectorAll('.status-badge').forEach(badge => {
        badge.addEventListener('click', function() {
            const truckId = this.dataset.truckId;
            const currentStatus = this.dataset.currentStatus;
            
            // Show a modal or dropdown to change status
            const newStatus = prompt('Enter new status (available, assigned, on_delivery, maintenance, out_of_service):', currentStatus);
            
            if (newStatus && newStatus !== currentStatus) {
                // Make AJAX request to update status
                fetch(`update-truck-status.php?id=${truckId}&status=${newStatus}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Error updating status: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error updating status');
                    });
            }
        });
    });
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>