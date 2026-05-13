<?php
// notifications.php
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
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit();
}

// Initialize variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Initialize messages
$message = '';
$error = '';

// Handle mark as read action
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    try {
        $update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $update_stmt->execute([$_GET['mark_read']]);
        $message = 'Notification marked as read';
    } catch (Exception $e) {
        $error = 'Failed to update notification: ' . $e->getMessage();
    }
}

// Handle mark all as read action
if (isset($_GET['mark_all_read'])) {
    try {
        $update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        $update_stmt->execute();
        $message = 'All notifications marked as read';
    } catch (Exception $e) {
        $error = 'Failed to mark all as read: ' . $e->getMessage();
    }
}

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $delete_stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
        $delete_stmt->execute([$_GET['delete']]);
        $message = 'Notification deleted successfully';
    } catch (Exception $e) {
        $error = 'Failed to delete notification: ' . $e->getMessage();
    }
}

// Handle clear all action
if (isset($_GET['clear_all'])) {
    try {
        $delete_stmt = $db->prepare("DELETE FROM notifications");
        $delete_stmt->execute();
        $message = 'All notifications cleared';
    } catch (Exception $e) {
        $error = 'Failed to clear notifications: ' . $e->getMessage();
    }
}

// Build query for notifications
$query_params = [];
$query_conditions = [];

// Build search conditions
if (!empty($search)) {
    $query_conditions[] = "(title LIKE ? OR message LIKE ? OR related_to LIKE ?)";
    $search_term = "%$search%";
    $query_params[] = $search_term;
    $query_params[] = $search_term;
    $query_params[] = $search_term;
}

// Build status filter
if (!empty($status_filter)) {
    if ($status_filter === 'read') {
        $query_conditions[] = "is_read = 1";
    } elseif ($status_filter === 'unread') {
        $query_conditions[] = "is_read = 0";
    }
}

// Build type filter
if (!empty($type_filter)) {
    $query_conditions[] = "type = ?";
    $query_params[] = $type_filter;
}

// Build date filter
if (!empty($date_from)) {
    $query_conditions[] = "created_at >= ?";
    $query_params[] = $date_from . ' 00:00:00';
}
if (!empty($date_to)) {
    $query_conditions[] = "created_at <= ?";
    $query_params[] = $date_to . ' 23:59:59';
}

// Build WHERE clause
$where_clause = '';
if (!empty($query_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $query_conditions);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM notifications $where_clause";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($query_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $limit);

// Get notifications
$query = "SELECT n.*, 
                 u.name as user_name,
                 u.role_id as user_role
          FROM notifications n
          LEFT JOIN users u ON n.user_id = u.id
          $where_clause
          ORDER BY n.created_at DESC
          LIMIT ? OFFSET ?";

// Add limit and offset to params
$query_params[] = $limit;
$query_params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($query_params);
$notifications = $stmt->fetchAll();

// Get notification statistics - FIXED: wrapped 'system' in backticks
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
    SUM(CASE WHEN type = 'system' THEN 1 ELSE 0 END) as `system`,
    SUM(CASE WHEN type = 'order' THEN 1 ELSE 0 END) as order_notif,
    SUM(CASE WHEN type = 'driver' THEN 1 ELSE 0 END) as driver,
    SUM(CASE WHEN type = 'user' THEN 1 ELSE 0 END) as user_notif
    FROM notifications";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch();

// Set page title
$pageTitle = "Notifications";

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
                    <i class="fas fa-bell me-2"></i>Notifications
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Manage and view system notifications
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="?mark_all_read" class="btn btn-outline-success" onclick="return confirm('Mark all notifications as read?')">
                    <i class="fas fa-check-double me-2"></i> Mark All as Read
                </a>
                <a href="?clear_all" class="btn btn-outline-danger" onclick="return confirm('Clear all notifications? This action cannot be undone.')">
                    <i class="fas fa-trash me-2"></i> Clear All
                </a>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 col-6">
            <div class="card bg-primary text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-0">Total</h6>
                            <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-bell fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-warning text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-0">Unread</h6>
                            <h3 class="mb-0"><?php echo $stats['unread']; ?></h3>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-envelope fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-info text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-0">System</h6>
                            <h3 class="mb-0"><?php echo $stats['system']; ?></h3>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-cogs fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title mb-0">Orders</h6>
                            <h3 class="mb-0"><?php echo $stats['order_notif']; ?></h3>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-shopping-cart fa-2x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
            <form method="GET" action="" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search notifications...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="unread" <?php echo $status_filter == 'unread' ? 'selected' : ''; ?>>Unread</option>
                        <option value="read" <?php echo $status_filter == 'read' ? 'selected' : ''; ?>>Read</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select class="form-select" name="type">
                        <option value="">All Types</option>
                        <option value="system" <?php echo $type_filter == 'system' ? 'selected' : ''; ?>>System</option>
                        <option value="order" <?php echo $type_filter == 'order' ? 'selected' : ''; ?>>Order</option>
                        <option value="driver" <?php echo $type_filter == 'driver' ? 'selected' : ''; ?>>Driver</option>
                        <option value="user" <?php echo $type_filter == 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="payment" <?php echo $type_filter == 'payment' ? 'selected' : ''; ?>>Payment</option>
                        <option value="alert" <?php echo $type_filter == 'alert' ? 'selected' : ''; ?>>Alert</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-12">
                    <div class="d-flex justify-content-between">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i> Apply Filters
                        </button>
                        <a href="notifications.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i> Clear Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Notifications List -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No notifications found</h5>
                    <p class="text-muted">Try adjusting your filters or check back later.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="list-group-item list-group-item-action <?php echo $notification['is_read'] == 0 ? 'bg-light' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1 me-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <span class="badge bg-<?php 
                                                switch($notification['type']) {
                                                    case 'system': echo 'primary'; break;
                                                    case 'order': echo 'success'; break;
                                                    case 'driver': echo 'warning'; break;
                                                    case 'user': echo 'info'; break;
                                                    case 'payment': echo 'danger'; break;
                                                    case 'alert': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?> me-2">
                                                <?php echo ucfirst($notification['type']); ?>
                                            </span>
                                            <?php if ($notification['is_read'] == 0): ?>
                                                <span class="badge bg-danger">New</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?>
                                        </small>
                                    </div>
                                    <h6 class="mb-1 <?php echo $notification['is_read'] == 0 ? 'fw-bold' : ''; ?>">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                    </h6>
                                    <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <?php if (!empty($notification['related_to'])): ?>
                                        <small class="text-muted">
                                            <i class="fas fa-link me-1"></i>
                                            Related to: <?php echo htmlspecialchars($notification['related_to']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if (!empty($notification['user_name'])): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-user me-1"></i>
                                                User: <?php echo htmlspecialchars($notification['user_name']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php if ($notification['is_read'] == 0): ?>
                                            <li>
                                                <a class="dropdown-item" href="?mark_read=<?php echo $notification['id']; ?>">
                                                    <i class="fas fa-check me-2"></i> Mark as Read
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        <li>
                                            <a class="dropdown-item text-danger" href="?delete=<?php echo $notification['id']; ?>" onclick="return confirm('Delete this notification?')">
                                                <i class="fas fa-trash me-2"></i> Delete
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Notifications pagination" class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($_GET); ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
            
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query($_GET); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php endfor; ?>
            
            <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($_GET); ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
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

// Auto-refresh notifications every 30 seconds
setInterval(() => {
    // Only refresh if there's no active modal or dropdown
    if (!document.querySelector('.modal.show') && !document.querySelector('.dropdown-menu.show')) {
        window.location.reload();
    }
}, 30000);

// Mark notification as read when clicked (optional enhancement)
document.querySelectorAll('.list-group-item').forEach(item => {
    item.addEventListener('click', function(e) {
        // Don't trigger if clicking dropdown or links
        if (!e.target.closest('.dropdown') && !e.target.closest('a')) {
            const markReadLink = this.querySelector('a[href*="mark_read"]');
            if (markReadLink) {
                window.location.href = markReadLink.href;
            }
        }
    });
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>