<?php
/**
 * Reports & Analytics
 * 
 * Production-ready reports page following the same pattern as working files
 * Reports on all system tables: activity_logs, clients, couriers, drivers, 
 * fuel_records, notifications, orders, order_status, roles, settings, trucks,
 * truck_driver_assignments, truck_status_logs, users
 * 
 * @package CourierTruckManagement
 * @subpackage Admin
 * @version 1.0.0
 */

// ============================================================================
// 1. SESSION MANAGEMENT (Same as working files)
// ============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// 2. PATH DEFINITION (Same as working files)
// ============================================================================
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

// ============================================================================
// 3. INCLUDE CONFIGURATION (Same as working files)
// ============================================================================
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// ============================================================================
// 4. VERIFY DATABASE CONNECTION (Same as working files)
// ============================================================================
$db_connected = false;
$connection_error = null;

try {
    if (!isset($db) || !$db) {
        throw new Exception("Database connection not available");
    }
    
    // Test the connection with a simple query
    $test_query = $db->query("SELECT 1");
    if ($test_query) {
        $db_connected = true;
    } else {
        throw new Exception("Database connection test failed");
    }
} catch (Exception $e) {
    $connection_error = $e->getMessage();
    error_log("Reports database connection error: " . $connection_error);
}

// ============================================================================
// 5. AUTHENTICATION CHECKS (Same as working files)
// ============================================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

if ($_SESSION['role_id'] != ROLE_ADMIN) {
    header('Location: dashboard.php');
    exit();
}

$pageTitle = "Reports & Analytics";

// ============================================================================
// 6. GET FILTER PARAMETERS
// ============================================================================
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'overview';
$courier_id = isset($_GET['courier_id']) && $_GET['courier_id'] != '' ? intval($_GET['courier_id']) : null;
$driver_id = isset($_GET['driver_id']) && $_GET['driver_id'] != '' ? intval($_GET['driver_id']) : null;
$truck_id = isset($_GET['truck_id']) && $_GET['truck_id'] != '' ? intval($_GET['truck_id']) : null;
$status = $_GET['status'] ?? 'all';

// ============================================================================
// 7. GET FILTER OPTIONS FROM DATABASE
// ============================================================================
$couriers = [];
$drivers = [];
$trucks = [];
$order_statuses = [];

if ($db_connected) {
    try {
        // Get couriers for filter
        $courier_stmt = $db->prepare("SELECT id, name FROM couriers WHERE status = 'active' ORDER BY name");
        $courier_stmt->execute();
        $couriers = $courier_stmt->fetchAll();
        
        // Get drivers for filter
        $driver_stmt = $db->prepare("
            SELECT d.id, u.name as driver_name 
            FROM drivers d
            JOIN users u ON d.user_id = u.id
            WHERE d.status IN ('available', 'on_delivery')
            ORDER BY u.name
        ");
        $driver_stmt->execute();
        $drivers = $driver_stmt->fetchAll();
        
        // Get trucks for filter
        $truck_stmt = $db->prepare("
            SELECT id, plate_number 
            FROM trucks 
            WHERE status IN ('available', 'assigned', 'on_delivery')
            ORDER BY plate_number
        ");
        $truck_stmt->execute();
        $trucks = $truck_stmt->fetchAll();
        
        // Get order statuses for filter
        $status_stmt = $db->prepare("SELECT id, name, color FROM order_status ORDER BY sequence");
        $status_stmt->execute();
        $order_statuses = $status_stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Reports filter options error: " . $e->getMessage());
    }
}

// ============================================================================
// 8. GENERATE REPORT DATA
// ============================================================================
$report_data = [];

if ($db_connected) {
    try {
        
        switch ($report_type) {
            
            // =================================================================
            // OVERVIEW REPORT - Summary of all system data
            // =================================================================
            case 'overview':
                
                // System Summary Statistics
                $summary_sql = "
                    SELECT
                        -- Users stats
                        (SELECT COUNT(*) FROM users) as total_users,
                        (SELECT COUNT(*) FROM users WHERE status = 'active') as active_users,
                        (SELECT COUNT(*) FROM users WHERE role_id = 1) as total_admins,
                        (SELECT COUNT(*) FROM users WHERE role_id = 2) as total_drivers_users,
                        (SELECT COUNT(*) FROM users WHERE role_id = 3) as total_clients_users,
                        
                        -- Couriers stats
                        (SELECT COUNT(*) FROM couriers) as total_couriers,
                        (SELECT COUNT(*) FROM couriers WHERE status = 'active') as active_couriers,
                        
                        -- Drivers stats
                        (SELECT COUNT(*) FROM drivers) as total_drivers,
                        (SELECT COUNT(*) FROM drivers WHERE status = 'available') as drivers_available,
                        (SELECT COUNT(*) FROM drivers WHERE status = 'on_delivery') as drivers_on_delivery,
                        (SELECT COUNT(*) FROM drivers WHERE status = 'on_break') as drivers_on_break,
                        (SELECT COUNT(*) FROM drivers WHERE status = 'inactive') as drivers_inactive,
                        
                        -- Trucks stats
                        (SELECT COUNT(*) FROM trucks) as total_trucks,
                        (SELECT COUNT(*) FROM trucks WHERE status = 'available') as trucks_available,
                        (SELECT COUNT(*) FROM trucks WHERE status = 'assigned') as trucks_assigned,
                        (SELECT COUNT(*) FROM trucks WHERE status = 'on_delivery') as trucks_on_delivery,
                        (SELECT COUNT(*) FROM trucks WHERE status = 'maintenance') as trucks_maintenance,
                        (SELECT COUNT(*) FROM trucks WHERE status = 'out_of_service') as trucks_out_of_service,
                        
                        -- Orders stats
                        (SELECT COUNT(*) FROM orders) as total_orders,
                        (SELECT COUNT(*) FROM orders WHERE DATE(created_at) BETWEEN ? AND ?) as orders_in_period,
                        (SELECT COUNT(*) FROM orders WHERE status_id = 7 AND DATE(created_at) BETWEEN ? AND ?) as completed_orders,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status_id NOT IN (8,9) AND DATE(created_at) BETWEEN ? AND ?) as total_revenue,
                        
                        -- Clients stats
                        (SELECT COUNT(*) FROM clients) as total_clients,
                        (SELECT COUNT(*) FROM clients WHERE status = 'active') as active_clients,
                        
                        -- Activity stats
                        (SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) BETWEEN ? AND ?) as activities_in_period,
                        
                        -- Fuel records stats
                        (SELECT COUNT(*) FROM fuel_records WHERE DATE(created_at) BETWEEN ? AND ?) as fuel_records_count,
                        (SELECT COALESCE(SUM(cost), 0) FROM fuel_records WHERE DATE(created_at) BETWEEN ? AND ?) as total_fuel_cost,
                        
                        -- Notifications stats
                        (SELECT COUNT(*) FROM notifications WHERE DATE(created_at) BETWEEN ? AND ?) as notifications_sent,
                        (SELECT COUNT(*) FROM notifications WHERE is_read = 0) as unread_notifications,
                        
                        -- Assignments stats
                        (SELECT COUNT(*) FROM truck_driver_assignments WHERE status = 'active') as active_assignments
                ";
                
                $stmt = $db->prepare($summary_sql);
                $params = [
                    $start_date, $end_date,  // orders in period
                    $start_date, $end_date,  // completed orders
                    $start_date, $end_date,  // revenue
                    $start_date, $end_date,  // activities
                    $start_date, $end_date,  // fuel records count
                    $start_date, $end_date,  // fuel cost
                    $start_date, $end_date   // notifications
                ];
                $stmt->execute($params);
                $report_data['summary'] = $stmt->fetch();
                
                // Orders by status
                $status_sql = "
                    SELECT os.name, os.color, COUNT(o.id) as count,
                           COALESCE(SUM(o.total_amount), 0) as revenue
                    FROM order_status os
                    LEFT JOIN orders o ON os.id = o.status_id 
                        AND DATE(o.created_at) BETWEEN ? AND ?
                    GROUP BY os.id, os.name, os.color, os.sequence
                    ORDER BY os.sequence
                ";
                $stmt = $db->prepare($status_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['orders_by_status'] = $stmt->fetchAll();
                
                // Daily orders trend
                $trend_sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_amount), 0) as revenue
                    FROM orders
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ";
                $stmt = $db->prepare($trend_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['daily_trend'] = $stmt->fetchAll();
                
                // Top couriers by orders
                $top_couriers_sql = "
                    SELECT 
                        c.id,
                        c.name,
                        COUNT(o.id) as order_count,
                        COALESCE(SUM(o.total_amount), 0) as revenue,
                        COUNT(DISTINCT t.id) as truck_count,
                        COUNT(DISTINCT d.id) as driver_count
                    FROM couriers c
                    LEFT JOIN orders o ON c.id = o.courier_id AND DATE(o.created_at) BETWEEN ? AND ?
                    LEFT JOIN trucks t ON c.id = t.courier_id
                    LEFT JOIN drivers d ON c.id = d.courier_id
                    WHERE c.status = 'active'
                    GROUP BY c.id, c.name
                    ORDER BY order_count DESC
                    LIMIT 10
                ";
                $stmt = $db->prepare($top_couriers_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['top_couriers'] = $stmt->fetchAll();
                
                // Recent activity
                $activity_sql = "
                    SELECT al.*, u.name as user_name, r.name as role_name
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE DATE(al.created_at) BETWEEN ? AND ?
                    ORDER BY al.created_at DESC
                    LIMIT 20
                ";
                $stmt = $db->prepare($activity_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['recent_activity'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // ORDERS REPORT
            // =================================================================
            case 'orders':
                
                $sql = "
                    SELECT 
                        o.*,
                        c.name as courier_name,
                        cl.company_name,
                        cl.contact_person as client_contact,
                        u.name as sender_name,
                        os.name as status_name,
                        os.color as status_color,
                        t.plate_number,
                        d.id as driver_id,
                        u2.name as driver_name,
                        u2.phone as driver_phone,
                        DATEDIFF(o.actual_delivery, o.created_at) as delivery_days,
                        TIMESTAMPDIFF(HOUR, o.created_at, o.actual_delivery) as delivery_hours
                    FROM orders o
                    LEFT JOIN couriers c ON o.courier_id = c.id
                    LEFT JOIN clients cl ON o.client_id = cl.id
                    LEFT JOIN users u ON cl.user_id = u.id
                    LEFT JOIN order_status os ON o.status_id = os.id
                    LEFT JOIN trucks t ON o.truck_id = t.id
                    LEFT JOIN drivers d ON o.driver_id = d.id
                    LEFT JOIN users u2 ON d.user_id = u2.id
                    WHERE DATE(o.created_at) BETWEEN ? AND ?
                ";
                
                $params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $sql .= " AND o.courier_id = ?";
                    $params[] = $courier_id;
                }
                
                if ($driver_id) {
                    $sql .= " AND o.driver_id = ?";
                    $params[] = $driver_id;
                }
                
                if ($truck_id) {
                    $sql .= " AND o.truck_id = ?";
                    $params[] = $truck_id;
                }
                
                if ($status != 'all') {
                    $sql .= " AND o.status_id = ?";
                    $params[] = $status;
                }
                
                $sql .= " ORDER BY o.created_at DESC";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $report_data['orders'] = $stmt->fetchAll();
                
                // Orders summary stats
                $stats_sql = "
                    SELECT 
                        COUNT(*) as total_orders,
                        COALESCE(SUM(CASE WHEN status_id = 7 THEN 1 ELSE 0 END), 0) as delivered,
                        COALESCE(SUM(CASE WHEN status_id = 8 THEN 1 ELSE 0 END), 0) as cancelled,
                        COALESCE(SUM(total_amount), 0) as total_revenue,
                        COALESCE(AVG(total_amount), 0) as avg_order_value,
                        COALESCE(AVG(weight), 0) as avg_weight
                    FROM orders
                    WHERE DATE(created_at) BETWEEN ? AND ?
                ";
                
                $stats_params = [$start_date, $end_date];
                if ($courier_id) {
                    $stats_sql .= " AND courier_id = ?";
                    $stats_params[] = $courier_id;
                }
                
                $stmt = $db->prepare($stats_sql);
                $stmt->execute($stats_params);
                $report_data['orders_summary'] = $stmt->fetch();
                
                break;
            
            // =================================================================
            // TRUCKS REPORT
            // =================================================================
            case 'trucks':
                
                $sql = "
                    SELECT 
                        t.*,
                        c.name as courier_name,
                        u.name as driver_name,
                        d.license_number,
                        d.rating as driver_rating,
                        d.total_deliveries as driver_deliveries,
                        (SELECT COUNT(*) FROM orders WHERE truck_id = t.id AND DATE(created_at) BETWEEN ? AND ?) as orders_in_period,
                        DATEDIFF(t.insurance_expiry, CURDATE()) as days_to_insurance_expiry,
                        DATEDIFF(t.last_maintenance, CURDATE()) as days_since_maintenance
                    FROM trucks t
                    LEFT JOIN couriers c ON t.courier_id = c.id
                    LEFT JOIN drivers d ON t.driver_id = d.id
                    LEFT JOIN users u ON d.user_id = u.id
                    WHERE 1=1
                ";
                
                $params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $sql .= " AND t.courier_id = ?";
                    $params[] = $courier_id;
                }
                
                $sql .= " ORDER BY t.courier_id, t.plate_number";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $report_data['trucks'] = $stmt->fetchAll();
                
                // Trucks by status
                $status_sql = "
                    SELECT t.status, COUNT(*) as count
                    FROM trucks t
                    WHERE 1=1
                ";
                
                $status_params = [];
                if ($courier_id) {
                    $status_sql .= " AND t.courier_id = ?";
                    $status_params[] = $courier_id;
                }
                
                $status_sql .= " GROUP BY t.status";
                
                $stmt = $db->prepare($status_sql);
                $stmt->execute($status_params);
                $report_data['trucks_by_status'] = $stmt->fetchAll();
                
                // Maintenance alerts
                $maintenance_sql = "
                    SELECT 
                        t.id,
                        t.plate_number,
                        c.name as courier_name,
                        t.last_maintenance,
                        DATEDIFF(CURDATE(), t.last_maintenance) as days_since_maintenance,
                        t.status
                    FROM trucks t
                    LEFT JOIN couriers c ON t.courier_id = c.id
                    WHERE t.last_maintenance IS NOT NULL
                        AND DATEDIFF(CURDATE(), t.last_maintenance) > 30
                    ORDER BY days_since_maintenance DESC
                ";
                
                $stmt = $db->prepare($maintenance_sql);
                $stmt->execute();
                $report_data['maintenance_alerts'] = $stmt->fetchAll();
                
                // Insurance expiry alerts
                $insurance_sql = "
                    SELECT 
                        t.id,
                        t.plate_number,
                        c.name as courier_name,
                        t.insurance_number,
                        t.insurance_expiry,
                        DATEDIFF(t.insurance_expiry, CURDATE()) as days_until_expiry
                    FROM trucks t
                    LEFT JOIN couriers c ON t.courier_id = c.id
                    WHERE t.insurance_expiry IS NOT NULL
                        AND DATEDIFF(t.insurance_expiry, CURDATE()) < 30
                    ORDER BY days_until_expiry
                ";
                
                $stmt = $db->prepare($insurance_sql);
                $stmt->execute();
                $report_data['insurance_alerts'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // DRIVERS REPORT
            // =================================================================
            case 'drivers':
                
                $sql = "
                    SELECT 
                        d.*,
                        c.name as courier_name,
                        u.name as driver_full_name,
                        u.email,
                        u.phone,
                        u.status as user_status,
                        u.last_login,
                        u.created_at as joined_date,
                        t.plate_number as current_truck,
                        (SELECT COUNT(*) FROM orders WHERE driver_id = d.id AND DATE(created_at) BETWEEN ? AND ?) as deliveries_in_period,
                        (SELECT COUNT(*) FROM truck_driver_assignments WHERE driver_id = d.id) as total_assignments
                    FROM drivers d
                    JOIN users u ON d.user_id = u.id
                    LEFT JOIN couriers c ON d.courier_id = c.id
                    LEFT JOIN trucks t ON d.id = t.driver_id
                    WHERE 1=1
                ";
                
                $params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $sql .= " AND d.courier_id = ?";
                    $params[] = $courier_id;
                }
                
                $sql .= " ORDER BY d.courier_id, u.name";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $report_data['drivers'] = $stmt->fetchAll();
                
                // Drivers by status
                $status_sql = "
                    SELECT d.status, COUNT(*) as count
                    FROM drivers d
                    WHERE 1=1
                ";
                
                $status_params = [];
                if ($courier_id) {
                    $status_sql .= " AND d.courier_id = ?";
                    $status_params[] = $courier_id;
                }
                
                $status_sql .= " GROUP BY d.status";
                
                $stmt = $db->prepare($status_sql);
                $stmt->execute($status_params);
                $report_data['drivers_by_status'] = $stmt->fetchAll();
                
                // Top performing drivers
                $top_sql = "
                    SELECT 
                        d.id,
                        u.name as driver_name,
                        c.name as courier_name,
                        d.rating,
                        d.total_deliveries,
                        (SELECT COUNT(*) FROM orders WHERE driver_id = d.id AND DATE(created_at) BETWEEN ? AND ?) as recent_deliveries
                    FROM drivers d
                    JOIN users u ON d.user_id = u.id
                    LEFT JOIN couriers c ON d.courier_id = c.id
                    WHERE d.status IN ('available', 'on_delivery')
                    ORDER BY d.rating DESC
                    LIMIT 20
                ";
                
                $stmt = $db->prepare($top_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['top_drivers'] = $stmt->fetchAll();
                
                // License expiry alerts
                $license_sql = "
                    SELECT 
                        d.id,
                        u.name as driver_name,
                        c.name as courier_name,
                        d.license_number,
                        d.license_expiry,
                        DATEDIFF(d.license_expiry, CURDATE()) as days_until_expiry
                    FROM drivers d
                    JOIN users u ON d.user_id = u.id
                    LEFT JOIN couriers c ON d.courier_id = c.id
                    WHERE d.license_expiry IS NOT NULL
                        AND DATEDIFF(d.license_expiry, CURDATE()) < 30
                    ORDER BY days_until_expiry
                ";
                
                $stmt = $db->prepare($license_sql);
                $stmt->execute();
                $report_data['license_alerts'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // REVENUE REPORT
            // =================================================================
            case 'revenue':
                
                // Daily revenue
                $daily_sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_amount), 0) as revenue,
                        COALESCE(AVG(total_amount), 0) as avg_order_value,
                        COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_revenue,
                        COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_revenue,
                        COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN total_amount ELSE 0 END), 0) as bank_revenue,
                        COALESCE(SUM(CASE WHEN payment_method = 'cod' THEN total_amount ELSE 0 END), 0) as cod_revenue
                    FROM orders
                    WHERE DATE(created_at) BETWEEN ? AND ?
                        AND status_id NOT IN (8,9)
                ";
                
                $params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $daily_sql .= " AND courier_id = ?";
                    $params[] = $courier_id;
                }
                
                $daily_sql .= " GROUP BY DATE(created_at) ORDER BY date";
                
                $stmt = $db->prepare($daily_sql);
                $stmt->execute($params);
                $report_data['daily_revenue'] = $stmt->fetchAll();
                
                // Monthly revenue
                $monthly_sql = "
                    SELECT 
                        DATE_FORMAT(created_at, '%Y-%m') as month,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_amount), 0) as revenue,
                        COALESCE(AVG(total_amount), 0) as avg_order_value
                    FROM orders
                    WHERE DATE(created_at) BETWEEN ? AND ?
                        AND status_id NOT IN (8,9)
                ";
                
                $monthly_params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $monthly_sql .= " AND courier_id = ?";
                    $monthly_params[] = $courier_id;
                }
                
                $monthly_sql .= " GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month";
                
                $stmt = $db->prepare($monthly_sql);
                $stmt->execute($monthly_params);
                $report_data['monthly_revenue'] = $stmt->fetchAll();
                
                // Revenue by courier
                $courier_revenue_sql = "
                    SELECT 
                        c.id,
                        c.name as courier_name,
                        COUNT(o.id) as order_count,
                        COALESCE(SUM(o.total_amount), 0) as revenue,
                        COALESCE(AVG(o.total_amount), 0) as avg_order_value
                    FROM couriers c
                    LEFT JOIN orders o ON c.id = o.courier_id 
                        AND DATE(o.created_at) BETWEEN ? AND ?
                        AND o.status_id NOT IN (8,9)
                    GROUP BY c.id, c.name
                    ORDER BY revenue DESC
                ";
                
                $stmt = $db->prepare($courier_revenue_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['revenue_by_courier'] = $stmt->fetchAll();
                
                // Revenue by service type
                $service_sql = "
                    SELECT 
                        o.service_type,
                        COUNT(*) as order_count,
                        COALESCE(SUM(o.total_amount), 0) as revenue,
                        COALESCE(AVG(o.total_amount), 0) as avg_order_value
                    FROM orders o
                    WHERE DATE(o.created_at) BETWEEN ? AND ?
                        AND o.status_id NOT IN (8,9)
                ";
                
                $service_params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $service_sql .= " AND o.courier_id = ?";
                    $service_params[] = $courier_id;
                }
                
                $service_sql .= " GROUP BY o.service_type ORDER BY revenue DESC";
                
                $stmt = $db->prepare($service_sql);
                $stmt->execute($service_params);
                $report_data['revenue_by_service'] = $stmt->fetchAll();
                
                // Revenue by payment method
                $payment_sql = "
                    SELECT 
                        o.payment_method,
                        COUNT(*) as order_count,
                        COALESCE(SUM(o.total_amount), 0) as revenue
                    FROM orders o
                    WHERE DATE(o.created_at) BETWEEN ? AND ?
                        AND o.status_id NOT IN (8,9)
                ";
                
                $payment_params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $payment_sql .= " AND o.courier_id = ?";
                    $payment_params[] = $courier_id;
                }
                
                $payment_sql .= " GROUP BY o.payment_method ORDER BY revenue DESC";
                
                $stmt = $db->prepare($payment_sql);
                $stmt->execute($payment_params);
                $report_data['revenue_by_payment'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // CLIENTS REPORT
            // =================================================================
            case 'clients':
                
                $sql = "
                    SELECT 
                        cl.*,
                        u.name as user_name,
                        u.email,
                        u.phone,
                        u.status as user_status,
                        u.last_login,
                        u.created_at as registered_date,
                        (SELECT COUNT(*) FROM orders WHERE client_id = cl.id AND DATE(created_at) BETWEEN ? AND ?) as orders_in_period,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE client_id = cl.id AND DATE(created_at) BETWEEN ? AND ?) as revenue_in_period,
                        (SELECT COUNT(*) FROM orders WHERE client_id = cl.id) as total_orders,
                        (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE client_id = cl.id) as total_revenue
                    FROM clients cl
                    JOIN users u ON cl.user_id = u.id
                    WHERE 1=1
                ";
                
                $params = [$start_date, $end_date, $start_date, $end_date];
                
                $sql .= " ORDER BY u.created_at DESC";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $report_data['clients'] = $stmt->fetchAll();
                
                // Clients by status
                $status_sql = "
                    SELECT cl.status, COUNT(*) as count
                    FROM clients cl
                    GROUP BY cl.status
                ";
                
                $stmt = $db->prepare($status_sql);
                $stmt->execute();
                $report_data['clients_by_status'] = $stmt->fetchAll();
                
                // Top clients by revenue
                $top_sql = "
                    SELECT 
                        cl.id,
                        cl.company_name,
                        u.name as client_name,
                        COUNT(o.id) as order_count,
                        COALESCE(SUM(o.total_amount), 0) as total_revenue
                    FROM clients cl
                    JOIN users u ON cl.user_id = u.id
                    LEFT JOIN orders o ON cl.id = o.client_id
                    GROUP BY cl.id, cl.company_name, u.name
                    ORDER BY total_revenue DESC
                    LIMIT 20
                ";
                
                $stmt = $db->prepare($top_sql);
                $stmt->execute();
                $report_data['top_clients'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // ACTIVITY REPORT
            // =================================================================
            case 'activity':
                
                // Activity logs
                $logs_sql = "
                    SELECT 
                        al.*,
                        u.name as user_name,
                        r.name as role_name
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE DATE(al.created_at) BETWEEN ? AND ?
                    ORDER BY al.created_at DESC
                ";
                
                $logs_params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $logs_sql = str_replace(
                        "WHERE DATE(al.created_at) BETWEEN ? AND ?",
                        "WHERE DATE(al.created_at) BETWEEN ? AND ? AND al.user_id IN (SELECT user_id FROM drivers WHERE courier_id = ? UNION SELECT ?)",
                        $logs_sql
                    );
                    $logs_params[] = $courier_id;
                    $logs_params[] = $_SESSION['user_id']; // Include current admin
                }
                
                $logs_sql .= " LIMIT 500";
                
                $stmt = $db->prepare($logs_sql);
                $stmt->execute($logs_params);
                $report_data['activity_logs'] = $stmt->fetchAll();
                
                // Activity summary
                $summary_sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as activity_count,
                        COUNT(DISTINCT user_id) as unique_users
                    FROM activity_logs
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ";
                
                $stmt = $db->prepare($summary_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['activity_summary'] = $stmt->fetchAll();
                
                // Activity by type
                $type_sql = "
                    SELECT 
                        action,
                        COUNT(*) as count
                    FROM activity_logs
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY action
                    ORDER BY count DESC
                ";
                
                $stmt = $db->prepare($type_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['activity_by_type'] = $stmt->fetchAll();
                
                // Activity by user
                $user_sql = "
                    SELECT 
                        u.name,
                        r.name as role_name,
                        COUNT(al.id) as activity_count,
                        MAX(al.created_at) as last_activity
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    LEFT JOIN activity_logs al ON u.id = al.user_id AND DATE(al.created_at) BETWEEN ? AND ?
                    GROUP BY u.id, u.name, r.name
                    HAVING activity_count > 0
                    ORDER BY activity_count DESC
                ";
                
                $stmt = $db->prepare($user_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['activity_by_user'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // FUEL REPORT
            // =================================================================
            case 'fuel':
                
                $sql = "
                    SELECT 
                        fr.*,
                        t.plate_number,
                        t.model as truck_model,
                        c.name as courier_name,
                        u.name as driver_name
                    FROM fuel_records fr
                    JOIN trucks t ON fr.truck_id = t.id
                    JOIN couriers c ON t.courier_id = c.id
                    JOIN drivers d ON fr.driver_id = d.id
                    JOIN users u ON d.user_id = u.id
                    WHERE DATE(fr.fuel_date) BETWEEN ? AND ?
                    ORDER BY fr.fuel_date DESC, fr.fuel_time DESC
                ";
                
                $params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $sql .= " AND t.courier_id = ?";
                    $params[] = $courier_id;
                }
                
                if ($truck_id) {
                    $sql .= " AND fr.truck_id = ?";
                    $params[] = $truck_id;
                }
                
                if ($driver_id) {
                    $sql .= " AND fr.driver_id = ?";
                    $params[] = $driver_id;
                }
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $report_data['fuel_records'] = $stmt->fetchAll();
                
                // Fuel summary
                $summary_sql = "
                    SELECT 
                        COUNT(*) as total_records,
                        COALESCE(SUM(fuel_amount), 0) as total_fuel_liters,
                        COALESCE(SUM(cost), 0) as total_cost,
                        COALESCE(AVG(fuel_price_per_liter), 0) as avg_price_per_liter,
                        COUNT(DISTINCT truck_id) as unique_trucks
                    FROM fuel_records
                    WHERE DATE(fuel_date) BETWEEN ? AND ?
                ";
                
                $summary_params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $summary_sql .= " AND truck_id IN (SELECT id FROM trucks WHERE courier_id = ?)";
                    $summary_params[] = $courier_id;
                }
                
                $stmt = $db->prepare($summary_sql);
                $stmt->execute($summary_params);
                $report_data['fuel_summary'] = $stmt->fetch();
                
                // Fuel consumption by truck
                $truck_sql = "
                    SELECT 
                        t.id,
                        t.plate_number,
                        c.name as courier_name,
                        COUNT(fr.id) as records,
                        COALESCE(SUM(fr.fuel_amount), 0) as total_liters,
                        COALESCE(SUM(fr.cost), 0) as total_cost,
                        COALESCE(AVG(fr.fuel_price_per_liter), 0) as avg_price
                    FROM trucks t
                    LEFT JOIN fuel_records fr ON t.id = fr.truck_id AND DATE(fr.fuel_date) BETWEEN ? AND ?
                    LEFT JOIN couriers c ON t.courier_id = c.id
                    WHERE 1=1
                ";
                
                $truck_params = [$start_date, $end_date];
                
                if ($courier_id) {
                    $truck_sql .= " AND t.courier_id = ?";
                    $truck_params[] = $courier_id;
                }
                
                $truck_sql .= " GROUP BY t.id, t.plate_number, c.name
                                HAVING records > 0
                                ORDER BY total_liters DESC";
                
                $stmt = $db->prepare($truck_sql);
                $stmt->execute($truck_params);
                $report_data['fuel_by_truck'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // NOTIFICATIONS REPORT
            // =================================================================
            case 'notifications':
                
                $sql = "
                    SELECT 
                        n.*,
                        u.name as recipient_name,
                        r.name as recipient_role
                    FROM notifications n
                    LEFT JOIN users u ON n.user_id = u.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE DATE(n.created_at) BETWEEN ? AND ?
                    ORDER BY n.created_at DESC
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['notifications'] = $stmt->fetchAll();
                
                // Notifications summary
                $summary_sql = "
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count,
                        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
                        COUNT(DISTINCT type) as types_count
                    FROM notifications
                    WHERE DATE(created_at) BETWEEN ? AND ?
                ";
                
                $stmt = $db->prepare($summary_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['notifications_summary'] = $stmt->fetch();
                
                // Notifications by type
                $type_sql = "
                    SELECT 
                        type,
                        COUNT(*) as count,
                        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count
                    FROM notifications
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY type
                    ORDER BY count DESC
                ";
                
                $stmt = $db->prepare($type_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['notifications_by_type'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // ASSIGNMENTS REPORT
            // =================================================================
            case 'assignments':
                
                $sql = "
                    SELECT 
                        tda.*,
                        t.plate_number,
                        t.model as truck_model,
                        c.name as courier_name,
                        u.name as driver_name,
                        u2.name as assigned_by_name
                    FROM truck_driver_assignments tda
                    JOIN trucks t ON tda.truck_id = t.id
                    JOIN couriers c ON t.courier_id = c.id
                    JOIN drivers d ON tda.driver_id = d.id
                    JOIN users u ON d.user_id = u.id
                    LEFT JOIN users u2 ON tda.assigned_by = u2.id
                    WHERE DATE(tda.assigned_at) BETWEEN ? AND ?
                    ORDER BY tda.assigned_at DESC
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['assignments'] = $stmt->fetchAll();
                
                // Active assignments
                $active_sql = "
                    SELECT 
                        COUNT(*) as active_assignments,
                        COUNT(DISTINCT truck_id) as active_trucks,
                        COUNT(DISTINCT driver_id) as active_drivers
                    FROM truck_driver_assignments
                    WHERE status = 'active'
                ";
                
                $stmt = $db->prepare($active_sql);
                $stmt->execute();
                $report_data['active_assignments'] = $stmt->fetch();
                
                break;
            
            // =================================================================
            // SETTINGS REPORT
            // =================================================================
            case 'settings':
                
                $sql = "
                    SELECT *
                    FROM settings
                    ORDER BY category, setting_key
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute();
                $report_data['settings'] = $stmt->fetchAll();
                
                // Settings by category
                $category_sql = "
                    SELECT 
                        category,
                        COUNT(*) as setting_count
                    FROM settings
                    GROUP BY category
                    ORDER BY category
                ";
                
                $stmt = $db->prepare($category_sql);
                $stmt->execute();
                $report_data['settings_by_category'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // USERS REPORT
            // =================================================================
            case 'users':
                
                $sql = "
                    SELECT 
                        u.*,
                        r.name as role_name,
                        r.description as role_description,
                        d.id as driver_id,
                        d.license_number,
                        cl.id as client_id,
                        cl.company_name
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN drivers d ON u.id = d.user_id
                    LEFT JOIN clients cl ON u.id = cl.user_id
                    WHERE DATE(u.created_at) BETWEEN ? AND ?
                    ORDER BY u.created_at DESC
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['users'] = $stmt->fetchAll();
                
                // Users by role
                $role_sql = "
                    SELECT 
                        r.name as role_name,
                        COUNT(u.id) as user_count
                    FROM roles r
                    LEFT JOIN users u ON r.id = u.role_id
                    GROUP BY r.id, r.name
                    ORDER BY r.id
                ";
                
                $stmt = $db->prepare($role_sql);
                $stmt->execute();
                $report_data['users_by_role'] = $stmt->fetchAll();
                
                // Users by status
                $status_sql = "
                    SELECT 
                        status,
                        COUNT(*) as count
                    FROM users
                    GROUP BY status
                ";
                
                $stmt = $db->prepare($status_sql);
                $stmt->execute();
                $report_data['users_by_status'] = $stmt->fetchAll();
                
                // Recent logins
                $login_sql = "
                    SELECT 
                        u.name,
                        u.email,
                        r.name as role_name,
                        u.last_login
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    WHERE u.last_login IS NOT NULL
                        AND DATE(u.last_login) BETWEEN ? AND ?
                    ORDER BY u.last_login DESC
                ";
                
                $stmt = $db->prepare($login_sql);
                $stmt->execute([$start_date, $end_date]);
                $report_data['recent_logins'] = $stmt->fetchAll();
                
                break;
            
            // =================================================================
            // DEFAULT: OVERVIEW
            // =================================================================
            default:
                // Redirect to overview
                header('Location: reports.php?report_type=overview&start_date=' . $start_date . '&end_date=' . $end_date);
                exit();
        }
        
    } catch (Exception $e) {
        error_log("Reports data generation error: " . $e->getMessage());
        $error_message = "Error generating report: " . $e->getMessage();
    }
}

// ============================================================================
// 9. GET USER NAME (Same as working files)
// ============================================================================
$user_name = $_SESSION['user_name'] ?? 'Admin';
if (empty($user_name) && $db_connected) {
    try {
        $name_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
        $name_stmt->execute([$_SESSION['user_id']]);
        $user_data = $name_stmt->fetch();
        $user_name = $user_data['name'] ?? 'Admin';
        $_SESSION['user_name'] = $user_name;
    } catch (Exception $e) {
        error_log("Failed to fetch user name: " . $e->getMessage());
    }
}

// ============================================================================
// 10. CHECK FOR MESSAGES (Same as working files)
// ============================================================================
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// ============================================================================
// 11. INCLUDE HEADER AND SIDEBAR
// ============================================================================
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/sidebar.php';
?>

<div class="main-container" style="margin-left: 250px; padding: 20px; min-height: 100vh;">
    
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #306998;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #0D2B4E; font-weight: 700;">
                    <i class="fas fa-chart-bar me-2"></i>Reports & Analytics
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Comprehensive system reports across all modules
                </p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i> Print
                </button>
                <button class="btn btn-success" onclick="exportToCSV()">
                    <i class="fas fa-file-csv me-2"></i> Export CSV
                </button>
            </div>
        </div>
    </div>

    <!-- Database Connection Error -->
    <?php if (!$db_connected): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Database connection error: <?php echo htmlspecialchars($connection_error ?? 'Unknown error'); ?>
        </div>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Form -->
    <div class="card filter-card mb-4 bg-primary text-white">
        <div class="card-body">
            <form method="GET" action="" id="reportFilter">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label text-white">Report Type</label>
                        <select class="form-select" name="report_type" onchange="this.form.submit()">
                            <option value="overview" <?php echo $report_type == 'overview' ? 'selected' : ''; ?>>System Overview</option>
                            <option value="orders" <?php echo $report_type == 'orders' ? 'selected' : ''; ?>>Orders Report</option>
                            <option value="trucks" <?php echo $report_type == 'trucks' ? 'selected' : ''; ?>>Trucks Report</option>
                            <option value="drivers" <?php echo $report_type == 'drivers' ? 'selected' : ''; ?>>Drivers Report</option>
                            <option value="revenue" <?php echo $report_type == 'revenue' ? 'selected' : ''; ?>>Revenue Report</option>
                            <option value="clients" <?php echo $report_type == 'clients' ? 'selected' : ''; ?>>Clients Report</option>
                            <option value="activity" <?php echo $report_type == 'activity' ? 'selected' : ''; ?>>Activity Logs</option>
                            <option value="fuel" <?php echo $report_type == 'fuel' ? 'selected' : ''; ?>>Fuel Records</option>
                            <option value="notifications" <?php echo $report_type == 'notifications' ? 'selected' : ''; ?>>Notifications</option>
                            <option value="assignments" <?php echo $report_type == 'assignments' ? 'selected' : ''; ?>>Assignments</option>
                            <option value="settings" <?php echo $report_type == 'settings' ? 'selected' : ''; ?>>System Settings</option>
                            <option value="users" <?php echo $report_type == 'users' ? 'selected' : ''; ?>>Users Report</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-white">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-white">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-white">Courier</label>
                        <select class="form-select" name="courier_id">
                            <option value="">All Couriers</option>
                            <?php foreach ($couriers as $courier): ?>
                                <option value="<?php echo $courier['id']; ?>" <?php echo $courier_id == $courier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($courier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (in_array($report_type, ['orders', 'fuel', 'assignments'])): ?>
                    <div class="col-md-2">
                        <label class="form-label text-white">Driver</label>
                        <select class="form-select" name="driver_id">
                            <option value="">All Drivers</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?php echo $driver['id']; ?>" <?php echo $driver_id == $driver['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($driver['driver_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if (in_array($report_type, ['orders', 'fuel'])): ?>
                    <div class="col-md-2">
                        <label class="form-label text-white">Truck</label>
                        <select class="form-select" name="truck_id">
                            <option value="">All Trucks</option>
                            <?php foreach ($trucks as $truck): ?>
                                <option value="<?php echo $truck['id']; ?>" <?php echo $truck_id == $truck['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($truck['plate_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php if ($report_type == 'orders'): ?>
                    <div class="col-md-2">
                        <label class="form-label text-white">Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <?php foreach ($order_statuses as $order_status): ?>
                                <option value="<?php echo $order_status['id']; ?>" <?php echo $status == $order_status['id'] ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($order_status['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-light w-100">
                            <i class="fas fa-filter me-2"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Title -->
    <div class="mb-4">
        <h5 class="text-muted">
            <?php 
            $report_titles = [
                'overview' => 'System Overview Report',
                'orders' => 'Orders Report',
                'trucks' => 'Trucks Report',
                'drivers' => 'Drivers Report',
                'revenue' => 'Revenue Report',
                'clients' => 'Clients Report',
                'activity' => 'Activity Logs Report',
                'fuel' => 'Fuel Records Report',
                'notifications' => 'Notifications Report',
                'assignments' => 'Truck-Driver Assignments Report',
                'settings' => 'System Settings Report',
                'users' => 'Users Report'
            ];
            echo $report_titles[$report_type] ?? 'Report';
            ?>
            <span class="badge bg-secondary ms-2"><?php echo $start_date; ?> to <?php echo $end_date; ?></span>
        </h5>
    </div>

    <!-- ====================================================================
         Report Content Based on Type
         ==================================================================== -->
    
    <?php if ($report_type == 'overview'): ?>
        <!-- System Overview Report -->
        
        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Total Users</h6>
                        <h3><?php echo number_format($report_data['summary']['total_users'] ?? 0); ?></h3>
                        <small>Active: <?php echo number_format($report_data['summary']['active_users'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Active Couriers</h6>
                        <h3><?php echo number_format($report_data['summary']['active_couriers'] ?? 0); ?></h3>
                        <small>Total: <?php echo number_format($report_data['summary']['total_couriers'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Total Drivers</h6>
                        <h3><?php echo number_format($report_data['summary']['total_drivers'] ?? 0); ?></h3>
                        <small>Available: <?php echo number_format($report_data['summary']['drivers_available'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Total Trucks</h6>
                        <h3><?php echo number_format($report_data['summary']['total_trucks'] ?? 0); ?></h3>
                        <small>Available: <?php echo number_format($report_data['summary']['trucks_available'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Orders (Period)</h6>
                        <h3><?php echo number_format($report_data['summary']['orders_in_period'] ?? 0); ?></h3>
                        <small>Total: <?php echo number_format($report_data['summary']['total_orders'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-secondary text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Revenue (Period)</h6>
                        <h3>KES <?php echo number_format($report_data['summary']['total_revenue'] ?? 0, 2); ?></h3>
                        <small>Completed: <?php echo number_format($report_data['summary']['completed_orders'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-dark text-white">
                    <div class="card-body">
                        <h6 class="text-white-50">Total Clients</h6>
                        <h3><?php echo number_format($report_data['summary']['total_clients'] ?? 0); ?></h3>
                        <small>Active: <?php echo number_format($report_data['summary']['active_clients'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-purple text-white" style="background-color: #6f42c1;">
                    <div class="card-body">
                        <h6 class="text-white-50">Activities</h6>
                        <h3><?php echo number_format($report_data['summary']['activities_in_period'] ?? 0); ?></h3>
                        <small>Unread Notif: <?php echo number_format($report_data['summary']['unread_notifications'] ?? 0); ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders by Status Chart -->
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Orders by Status</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="ordersByStatusChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php if (!empty($report_data['orders_by_status'])): ?>
                                <?php foreach ($report_data['orders_by_status'] as $status): ?>
                                    <div class="d-inline-block me-3">
                                        <span class="badge" style="background-color: <?php echo $status['color'] ?? '#6c757d'; ?>;">&nbsp;</span>
                                        <?php echo ucfirst($status['name']); ?>: <?php echo $status['count']; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Daily Orders Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="dailyTrendChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Couriers -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Top Couriers by Orders</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Courier</th>
                                <th>Orders</th>
                                <th>Revenue</th>
                                <th>Trucks</th>
                                <th>Drivers</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach (($report_data['top_couriers'] ?? []) as $courier): 
                            ?>
                            <tr>
                                <td><?php echo $rank++; ?></td>
                                <td><?php echo htmlspecialchars($courier['name']); ?></td>
                                <td><?php echo number_format($courier['order_count']); ?></td>
                                <td>KES <?php echo number_format($courier['revenue'], 2); ?></td>
                                <td><?php echo number_format($courier['truck_count']); ?></td>
                                <td><?php echo number_format($courier['driver_count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent System Activity</h5>
            </div>
            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                <div class="list-group list-group-flush">
                    <?php if (!empty($report_data['recent_activity'])): ?>
                        <?php foreach ($report_data['recent_activity'] as $activity): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></h6>
                                    <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($activity['created_at'])); ?></small>
                                </div>
                                <p class="mb-1"><?php echo htmlspecialchars($activity['action']); ?></p>
                                <small class="text-muted"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center py-3">No activity found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'orders'): ?>
        <!-- Orders Report -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Orders Report Summary</h5>
                <span class="badge bg-primary">Total: <?php echo count($report_data['orders'] ?? []); ?> orders</span>
            </div>
            <div class="card-body">
                <?php if (!empty($report_data['orders_summary'])): ?>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="border p-3 text-center">
                                <h6>Total Orders</h6>
                                <h3><?php echo number_format($report_data['orders_summary']['total_orders']); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border p-3 text-center">
                                <h6>Delivered</h6>
                                <h3 class="text-success"><?php echo number_format($report_data['orders_summary']['delivered']); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border p-3 text-center">
                                <h6>Cancelled</h6>
                                <h3 class="text-danger"><?php echo number_format($report_data['orders_summary']['cancelled']); ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border p-3 text-center">
                                <h6>Total Revenue</h6>
                                <h3>KES <?php echo number_format($report_data['orders_summary']['total_revenue'], 2); ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="border p-3 text-center">
                                <h6>Average Order Value</h6>
                                <h4>KES <?php echo number_format($report_data['orders_summary']['avg_order_value'], 2); ?></h4>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border p-3 text-center">
                                <h6>Average Weight</h6>
                                <h4><?php echo number_format($report_data['orders_summary']['avg_weight'], 2); ?> kg</h4>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Orders List</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($report_data['orders'])): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Tracking</th>
                                    <th>Client</th>
                                    <th>Courier</th>
                                    <th>Driver</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['orders'] as $order): ?>
                                <tr>
                                    <td>#<?php echo $order['id']; ?></td>
                                    <td><?php echo htmlspecialchars($order['tracking_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['company_name'] ?? $order['sender_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['courier_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['driver_name'] ?? 'Unassigned'); ?></td>
                                    <td>
                                        <span class="badge" style="background-color: <?php echo $order['status_color']; ?>;">
                                            <?php echo ucfirst($order['status_name']); ?>
                                        </span>
                                    </td>
                                    <td>KES <?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted py-5">No orders found for the selected period</p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($report_type == 'trucks'): ?>
        <!-- Trucks Report -->
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Trucks by Status</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="trucksByStatusChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php if (!empty($report_data['trucks_by_status'])): ?>
                                <?php foreach ($report_data['trucks_by_status'] as $status): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo ucfirst($status['status']); ?>:</span>
                                        <span class="badge bg-primary"><?php echo $status['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($report_data['maintenance_alerts'])): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Maintenance Alerts</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($report_data['maintenance_alerts'] as $alert): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <strong><?php echo $alert['plate_number']; ?></strong> (<?php echo $alert['courier_name']; ?>)<br>
                                <small>Last maintenance: <?php echo $alert['last_maintenance']; ?> (<?php echo $alert['days_since_maintenance']; ?> days ago)</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Trucks List</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['trucks'])): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Plate #</th>
                                            <th>Courier</th>
                                            <th>Model</th>
                                            <th>Driver</th>
                                            <th>Status</th>
                                            <th>Capacity</th>
                                            <th>Insurance</th>
                                            <th>Orders</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['trucks'] as $truck): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($truck['plate_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($truck['courier_name']); ?></td>
                                            <td><?php echo htmlspecialchars($truck['model'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($truck['driver_name'] ?: 'Unassigned'); ?></td>
                                            <td>
                                                <?php
                                                $status_colors = [
                                                    'available' => 'success',
                                                    'assigned' => 'info',
                                                    'on_delivery' => 'primary',
                                                    'maintenance' => 'warning',
                                                    'out_of_service' => 'danger'
                                                ];
                                                $color = $status_colors[$truck['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>">
                                                    <?php echo ucfirst($truck['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $truck['capacity']; ?> kg</td>
                                            <td>
                                                <?php if ($truck['insurance_expiry']): ?>
                                                    <small class="<?php echo ($truck['days_to_insurance_expiry'] ?? 999) < 30 ? 'text-danger' : ''; ?>">
                                                        <?php echo date('Y-m-d', strtotime($truck['insurance_expiry'])); ?>
                                                    </small>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $truck['orders_in_period']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted py-5">No trucks found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'drivers'): ?>
        <!-- Drivers Report -->
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Drivers by Status</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="driversByStatusChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php if (!empty($report_data['drivers_by_status'])): ?>
                                <?php foreach ($report_data['drivers_by_status'] as $status): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo ucfirst($status['status']); ?>:</span>
                                        <span class="badge bg-primary"><?php echo $status['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Top Performers</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['top_drivers'])): ?>
                            <?php foreach (array_slice($report_data['top_drivers'], 0, 5) as $driver): ?>
                                <div class="mb-2">
                                    <strong><?php echo htmlspecialchars($driver['driver_name']); ?></strong>
                                    <div class="d-flex justify-content-between">
                                        <small><?php echo $driver['courier_name']; ?></small>
                                        <span class="text-warning"><?php echo number_format($driver['rating'], 1); ?> ★</span>
                                    </div>
                                    <small class="text-muted"><?php echo $driver['total_deliveries']; ?> total deliveries</small>
                                </div>
                                <?php if (!$loop->last): ?><hr><?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($report_data['license_alerts'])): ?>
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">License Expiry Alerts</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($report_data['license_alerts'] as $alert): ?>
                            <div class="alert alert-warning py-2 mb-2">
                                <strong><?php echo htmlspecialchars($alert['driver_name']); ?></strong><br>
                                <small>License #: <?php echo $alert['license_number']; ?></small><br>
                                <small>Expires: <?php echo $alert['license_expiry']; ?> (<?php echo $alert['days_until_expiry']; ?> days)</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Drivers List</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['drivers'])): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Courier</th>
                                            <th>License</th>
                                            <th>Status</th>
                                            <th>Experience</th>
                                            <th>Rating</th>
                                            <th>Deliveries</th>
                                            <th>Period</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['drivers'] as $driver): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($driver['driver_full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($driver['courier_name']); ?></td>
                                            <td><?php echo htmlspecialchars($driver['license_number']); ?></td>
                                            <td>
                                                <?php
                                                $status_colors = [
                                                    'available' => 'success',
                                                    'on_delivery' => 'primary',
                                                    'on_break' => 'warning',
                                                    'inactive' => 'secondary'
                                                ];
                                                $color = $status_colors[$driver['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>">
                                                    <?php echo ucfirst($driver['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $driver['experience_years']; ?> years</td>
                                            <td><?php echo number_format($driver['rating'], 1); ?> ★</td>
                                            <td><?php echo number_format($driver['total_deliveries']); ?></td>
                                            <td><?php echo $driver['deliveries_in_period']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted py-5">No drivers found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'revenue'): ?>
        <!-- Revenue Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Monthly Revenue Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Revenue by Courier</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['revenue_by_courier'])): ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Courier</th>
                                        <th>Orders</th>
                                        <th>Revenue</th>
                                        <th>Avg Order</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data['revenue_by_courier'] as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['courier_name']); ?></td>
                                        <td><?php echo number_format($item['order_count']); ?></td>
                                        <td>KES <?php echo number_format($item['revenue'], 2); ?></td>
                                        <td>KES <?php echo number_format($item['avg_order_value'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted text-center">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Revenue by Service Type</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="serviceTypeChart" height="250"></canvas>
                        <div class="mt-3">
                            <?php if (!empty($report_data['revenue_by_service'])): ?>
                                <?php foreach ($report_data['revenue_by_service'] as $service): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo ucfirst($service['service_type']); ?>:</span>
                                        <span class="badge bg-primary">KES <?php echo number_format($service['revenue'], 2); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Daily Revenue Details</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['daily_revenue'])): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Orders</th>
                                            <th>Revenue</th>
                                            <th>Cash</th>
                                            <th>Card</th>
                                            <th>Bank</th>
                                            <th>COD</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['daily_revenue'] as $day): ?>
                                        <tr>
                                            <td><?php echo $day['date']; ?></td>
                                            <td><?php echo $day['order_count']; ?></td>
                                            <td>KES <?php echo number_format($day['revenue'], 2); ?></td>
                                            <td>KES <?php echo number_format($day['cash_revenue'], 2); ?></td>
                                            <td>KES <?php echo number_format($day['card_revenue'], 2); ?></td>
                                            <td>KES <?php echo number_format($day['bank_revenue'], 2); ?></td>
                                            <td>KES <?php echo number_format($day['cod_revenue'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No revenue data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'clients'): ?>
        <!-- Clients Report -->
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Clients by Status</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="clientsByStatusChart" height="200"></canvas>
                        <div class="mt-3">
                            <?php if (!empty($report_data['clients_by_status'])): ?>
                                <?php foreach ($report_data['clients_by_status'] as $status): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo ucfirst($status['status']); ?>:</span>
                                        <span class="badge bg-primary"><?php echo $status['count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Top Clients</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['top_clients'])): ?>
                            <?php foreach (array_slice($report_data['top_clients'], 0, 5) as $client): ?>
                                <div class="mb-2">
                                    <strong><?php echo htmlspecialchars($client['company_name'] ?: $client['client_name']); ?></strong>
                                    <div class="d-flex justify-content-between">
                                        <small>Orders: <?php echo $client['order_count']; ?></small>
                                        <span class="text-success">KES <?php echo number_format($client['total_revenue'], 2); ?></span>
                                    </div>
                                </div>
                                <?php if (!$loop->last): ?><hr><?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Clients List</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['clients'])): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Company</th>
                                            <th>Contact Person</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Status</th>
                                            <th>Orders</th>
                                            <th>Revenue</th>
                                            <th>Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['clients'] as $client): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($client['company_name'] ?: 'Individual'); ?></td>
                                            <td><?php echo htmlspecialchars($client['contact_person'] ?: $client['user_name']); ?></td>
                                            <td><?php echo htmlspecialchars($client['email']); ?></td>
                                            <td><?php echo htmlspecialchars($client['phone']); ?></td>
                                            <td>
                                                <?php
                                                $status_colors = [
                                                    'active' => 'success',
                                                    'inactive' => 'secondary',
                                                    'pending' => 'warning'
                                                ];
                                                $color = $status_colors[$client['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?>">
                                                    <?php echo ucfirst($client['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($client['orders_in_period']); ?> (<?php echo $client['total_orders']; ?> total)</td>
                                            <td>KES <?php echo number_format($client['revenue_in_period'], 2); ?></td>
                                            <td>KES <?php echo number_format($client['account_balance'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted py-5">No clients found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'activity'): ?>
        <!-- Activity Report -->
        <div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Activity Logs</h5>
                    </div>
                    <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                        <?php if (!empty($report_data['activity_logs'])): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($report_data['activity_logs'] as $log): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                                                <small class="text-muted ms-2">(<?php echo htmlspecialchars($log['role_name'] ?? 'N/A'); ?>)</small>
                                            </h6>
                                            <small class="text-muted"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-1">
                                            <span class="badge bg-info"><?php echo str_replace('_', ' ', $log['action']); ?></span>
                                        </p>
                                        <small class="text-muted"><?php echo htmlspecialchars($log['description'] ?? ''); ?></small>
                                        <?php if ($log['ip_address']): ?>
                                            <small class="text-muted d-block">IP: <?php echo $log['ip_address']; ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted py-5">No activity logs found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Activity Summary</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['activity_summary'])): ?>
                            <canvas id="activitySummaryChart" height="200"></canvas>
                            <div class="mt-3">
                                <?php foreach ($report_data['activity_summary'] as $day): ?>
                                    <div class="d-flex justify-content-between">
                                        <small><?php echo $day['date']; ?></small>
                                        <span class="badge bg-primary"><?php echo $day['activity_count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No summary data</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Most Active Users</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['activity_by_user'])): ?>
                            <?php foreach (array_slice($report_data['activity_by_user'], 0, 5) as $user): ?>
                                <div class="mb-2">
                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                    <small class="text-muted d-block"><?php echo $user['role_name']; ?></small>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo $user['activity_count']; ?> activities</span>
                                        <small><?php echo date('H:i', strtotime($user['last_activity'])); ?></small>
                                    </div>
                                </div>
                                <?php if (!$loop->last): ?><hr><?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'fuel'): ?>
        <!-- Fuel Records Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Fuel Summary</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['fuel_summary'])): ?>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="border p-3 text-center">
                                        <h6>Total Records</h6>
                                        <h3><?php echo number_format($report_data['fuel_summary']['total_records']); ?></h3>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border p-3 text-center">
                                        <h6>Total Fuel</h6>
                                        <h3><?php echo number_format($report_data['fuel_summary']['total_fuel_liters'], 2); ?> L</h3>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border p-3 text-center">
                                        <h6>Total Cost</h6>
                                        <h3>KES <?php echo number_format($report_data['fuel_summary']['total_cost'], 2); ?></h3>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border p-3 text-center">
                                        <h6>Avg Price/L</h6>
                                        <h3>KES <?php echo number_format($report_data['fuel_summary']['avg_price_per_liter'], 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Fuel Consumption by Truck</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['fuel_by_truck'])): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Truck</th>
                                            <th>Courier</th>
                                            <th>Records</th>
                                            <th>Liters</th>
                                            <th>Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['fuel_by_truck'] as $truck): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($truck['plate_number']); ?></td>
                                            <td><?php echo htmlspecialchars($truck['courier_name']); ?></td>
                                            <td><?php echo $truck['records']; ?></td>
                                            <td><?php echo number_format($truck['total_liters'], 2); ?> L</td>
                                            <td>KES <?php echo number_format($truck['total_cost'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No fuel data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Fuel Records</h5>
                    </div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($report_data['fuel_records'])): ?>
                            <?php foreach (array_slice($report_data['fuel_records'], 0, 10) as $record): ?>
                                <div class="mb-2 pb-2 border-bottom">
                                    <div class="d-flex justify-content-between">
                                        <strong><?php echo $record['plate_number']; ?></strong>
                                        <small><?php echo $record['fuel_date']; ?></small>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo $record['driver_name']; ?></span>
                                        <span><?php echo number_format($record['fuel_amount'], 2); ?> L</span>
                                    </div>
                                    <small class="text-muted">KES <?php echo number_format($record['cost'], 2); ?> @ KES <?php echo number_format($record['fuel_price_per_liter'], 2); ?>/L</small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No fuel records found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'notifications'): ?>
        <!-- Notifications Report -->
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Notifications Summary</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['notifications_summary'])): ?>
                            <div class="text-center mb-3">
                                <h2><?php echo $report_data['notifications_summary']['total']; ?></h2>
                                <p>Total Notifications</p>
                            </div>
                            <div class="row">
                                <div class="col-6 text-center">
                                    <div class="border p-2">
                                        <h5 class="text-success"><?php echo $report_data['notifications_summary']['read_count']; ?></h5>
                                        <small>Read</small>
                                    </div>
                                </div>
                                <div class="col-6 text-center">
                                    <div class="border p-2">
                                        <h5 class="text-danger"><?php echo $report_data['notifications_summary']['unread_count']; ?></h5>
                                        <small>Unread</small>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <canvas id="notificationsByTypeChart" height="200"></canvas>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Notifications List</h5>
                    </div>
                    <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                        <?php if (!empty($report_data['notifications'])): ?>
                            <?php foreach ($report_data['notifications'] as $note): ?>
                                <div class="list-group-item <?php echo !$note['is_read'] ? 'bg-light' : ''; ?>">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">
                                            <?php echo htmlspecialchars($note['title']); ?>
                                            <?php if (!$note['is_read']): ?>
                                                <span class="badge bg-danger">New</span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($note['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-1"><?php echo htmlspecialchars($note['message']); ?></p>
                                    <small class="text-muted">
                                        Type: <?php echo ucfirst($note['type']); ?> | 
                                        Recipient: <?php echo htmlspecialchars($note['recipient_name'] ?: 'All'); ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-muted py-5">No notifications found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'assignments'): ?>
        <!-- Assignments Report -->
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Active Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['active_assignments'])): ?>
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <h3><?php echo $report_data['active_assignments']['active_assignments']; ?></h3>
                                    <p>Active Assignments</p>
                                </div>
                                <div class="col-md-4 text-center">
                                    <h3><?php echo $report_data['active_assignments']['active_trucks']; ?></h3>
                                    <p>Trucks Assigned</p>
                                </div>
                                <div class="col-md-4 text-center">
                                    <h3><?php echo $report_data['active_assignments']['active_drivers']; ?></h3>
                                    <p>Drivers Assigned</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Assignment History</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($report_data['assignments'])): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Truck</th>
                                    <th>Driver</th>
                                    <th>Courier</th>
                                    <th>Assigned By</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['assignments'] as $assignment): ?>
                                <tr>
                                    <td><?php echo date('Y-m-d H:i', strtotime($assignment['assigned_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['plate_number']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['driver_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['courier_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['assigned_by_name'] ?: 'System'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $assignment['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($assignment['status']); ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo htmlspecialchars($assignment['assignment_notes'] ?? ''); ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted py-5">No assignments found</p>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($report_type == 'settings'): ?>
        <!-- Settings Report -->
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Settings by Category</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['settings_by_category'])): ?>
                            <canvas id="settingsByCategoryChart" height="200"></canvas>
                            <div class="mt-3">
                                <?php foreach ($report_data['settings_by_category'] as $cat): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo ucfirst($cat['category'] ?: 'General'); ?>:</span>
                                        <span class="badge bg-primary"><?php echo $cat['setting_count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">System Settings</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($report_data['settings'])): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Key</th>
                                            <th>Value</th>
                                            <th>Type</th>
                                            <th>Category</th>
                                            <th>Description</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['settings'] as $setting): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($setting['setting_key']); ?></code></td>
                                            <td>
                                                <?php 
                                                if ($setting['setting_type'] == 'password') {
                                                    echo '********';
                                                } elseif ($setting['setting_type'] == 'boolean') {
                                                    echo $setting['setting_value'] ? 'Yes' : 'No';
                                                } else {
                                                    echo htmlspecialchars($setting['setting_value'] ?: 'NULL');
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo $setting['setting_type']; ?></td>
                                            <td><?php echo ucfirst($setting['category'] ?: 'General'); ?></td>
                                            <td><small><?php echo htmlspecialchars($setting['description'] ?: ''); ?></small></td>
                                            <td><small><?php echo date('Y-m-d', strtotime($setting['updated_at'])); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center text-muted py-5">No settings found</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($report_type == 'users'): ?>
        <!-- Users Report -->
        <div class="row">
            <div class="col-md-3">
                <div class="card mb-4 bg-primary text-white">
                    <div class="card-body text-center">
                        <h2><?php echo array_sum(array_column($report_data['users_by_role'] ?? [], 'user_count')); ?></h2>
                        <p>Total Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card mb-4 bg-success text-white">
                    <div class="card-body text-center">
                        <h2><?php echo $report_data['users_by_status']['active'] ?? 0; ?></h2>
                        <p>Active Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card mb-4 bg-warning text-white">
                    <div class="card-body text-center">
                        <h2><?php echo $report_data['users_by_status']['inactive'] ?? 0; ?></h2>
                        <p>Inactive Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card mb-4 bg-info text-white">
                    <div class="card-body text-center">
                        <h2><?php echo count($report_data['recent_logins'] ?? []); ?></h2>
                        <p>Recent Logins</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-5">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Users by Role</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="usersByRoleChart" height="200"></canvas>
                        <div class="mt-3">
                            <?php if (!empty($report_data['users_by_role'])): ?>
                                <?php foreach ($report_data['users_by_role'] as $role): ?>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo ucfirst($role['role_name']); ?>:</span>
                                        <span class="badge bg-primary"><?php echo $role['user_count']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Logins</h5>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (!empty($report_data['recent_logins'])): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($report_data['recent_logins'] as $login): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo htmlspecialchars($login['name']); ?></strong>
                                            <small><?php echo date('Y-m-d H:i', strtotime($login['last_login'])); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo $login['email']; ?> (<?php echo ucfirst($login['role_name']); ?>)</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No recent logins</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Users List</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($report_data['users'])): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Created</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['users'] as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?: 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $user['role_name'] == 'admin' ? 'danger' : 
                                                ($user['role_name'] == 'driver' ? 'warning' : 'info'); 
                                        ?>">
                                            <?php echo ucfirst($user['role_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $user['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php if ($user['driver_id']): ?>
                                            <small>Driver #<?php echo $user['driver_id']; ?></small>
                                        <?php endif; ?>
                                        <?php if ($user['client_id']): ?>
                                            <small>Client: <?php echo htmlspecialchars($user['company_name'] ?: 'Individual'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted py-5">No users found</p>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    <?php if ($report_type == 'overview' && !empty($report_data['orders_by_status'])): ?>
        // Orders by Status Chart
        const statusCtx = document.getElementById('ordersByStatusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($report_data['orders_by_status'], 'name')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($report_data['orders_by_status'], 'count')); ?>,
                    backgroundColor: <?php echo json_encode(array_column($report_data['orders_by_status'], 'color')); ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'overview' && !empty($report_data['daily_trend'])): ?>
        // Daily Trend Chart
        const trendCtx = document.getElementById('dailyTrendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($report_data['daily_trend'], 'date')); ?>,
                datasets: [{
                    label: 'Orders',
                    data: <?php echo json_encode(array_column($report_data['daily_trend'], 'order_count')); ?>,
                    borderColor: '#306998',
                    backgroundColor: 'rgba(48, 105, 152, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'trucks' && !empty($report_data['trucks_by_status'])): ?>
        // Trucks by Status Chart
        const trucksCtx = document.getElementById('trucksByStatusChart').getContext('2d');
        const trucksLabels = <?php echo json_encode(array_column($report_data['trucks_by_status'], 'status')); ?>;
        const trucksData = <?php echo json_encode(array_column($report_data['trucks_by_status'], 'count')); ?>;
        
        new Chart(trucksCtx, {
            type: 'doughnut',
            data: {
                labels: trucksLabels,
                datasets: [{
                    data: trucksData,
                    backgroundColor: trucksLabels.map(status => {
                        if (status === 'available') return '#28a745';
                        if (status === 'assigned') return '#17a2b8';
                        if (status === 'on_delivery') return '#007bff';
                        if (status === 'maintenance') return '#ffc107';
                        if (status === 'out_of_service') return '#dc3545';
                        return '#6c757d';
                    })
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'drivers' && !empty($report_data['drivers_by_status'])): ?>
        // Drivers by Status Chart
        const driversCtx = document.getElementById('driversByStatusChart').getContext('2d');
        const driversLabels = <?php echo json_encode(array_column($report_data['drivers_by_status'], 'status')); ?>;
        const driversData = <?php echo json_encode(array_column($report_data['drivers_by_status'], 'count')); ?>;
        
        new Chart(driversCtx, {
            type: 'pie',
            data: {
                labels: driversLabels,
                datasets: [{
                    data: driversData,
                    backgroundColor: driversLabels.map(status => {
                        if (status === 'available') return '#28a745';
                        if (status === 'on_delivery') return '#007bff';
                        if (status === 'on_break') return '#ffc107';
                        if (status === 'inactive') return '#6c757d';
                        return '#6c757d';
                    })
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'revenue' && !empty($report_data['monthly_revenue'])): ?>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($report_data['monthly_revenue'], 'month')); ?>,
                datasets: [{
                    label: 'Revenue (KES)',
                    data: <?php echo json_encode(array_column($report_data['monthly_revenue'], 'revenue')); ?>,
                    backgroundColor: '#28a745'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return 'KES ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'revenue' && !empty($report_data['revenue_by_service'])): ?>
        // Service Type Chart
        const serviceCtx = document.getElementById('serviceTypeChart').getContext('2d');
        const serviceLabels = <?php echo json_encode(array_map('ucfirst', array_column($report_data['revenue_by_service'], 'service_type'))); ?>;
        const serviceData = <?php echo json_encode(array_column($report_data['revenue_by_service'], 'revenue')); ?>;
        
        new Chart(serviceCtx, {
            type: 'doughnut',
            data: {
                labels: serviceLabels,
                datasets: [{
                    data: serviceData,
                    backgroundColor: ['#28a745', '#007bff', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'clients' && !empty($report_data['clients_by_status'])): ?>
        // Clients by Status Chart
        const clientsCtx = document.getElementById('clientsByStatusChart').getContext('2d');
        const clientsLabels = <?php echo json_encode(array_column($report_data['clients_by_status'], 'status')); ?>;
        const clientsData = <?php echo json_encode(array_column($report_data['clients_by_status'], 'count')); ?>;
        
        new Chart(clientsCtx, {
            type: 'pie',
            data: {
                labels: clientsLabels,
                datasets: [{
                    data: clientsData,
                    backgroundColor: ['#28a745', '#dc3545', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'activity' && !empty($report_data['activity_summary'])): ?>
        // Activity Summary Chart
        const activityCtx = document.getElementById('activitySummaryChart').getContext('2d');
        new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($report_data['activity_summary'], 'date')); ?>,
                datasets: [{
                    label: 'Activities',
                    data: <?php echo json_encode(array_column($report_data['activity_summary'], 'activity_count')); ?>,
                    borderColor: '#306998',
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'notifications' && !empty($report_data['notifications_by_type'])): ?>
        // Notifications by Type Chart
        const notifCtx = document.getElementById('notificationsByTypeChart').getContext('2d');
        new Chart(notifCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($report_data['notifications_by_type'], 'type')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($report_data['notifications_by_type'], 'count')); ?>,
                    backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'settings' && !empty($report_data['settings_by_category'])): ?>
        // Settings by Category Chart
        const settingsCtx = document.getElementById('settingsByCategoryChart').getContext('2d');
        new Chart(settingsCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_map('ucfirst', array_column($report_data['settings_by_category'], 'category'))); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($report_data['settings_by_category'], 'setting_count')); ?>,
                    backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    <?php endif; ?>

    <?php if ($report_type == 'users' && !empty($report_data['users_by_role'])): ?>
        // Users by Role Chart
        const usersCtx = document.getElementById('usersByRoleChart').getContext('2d');
        new Chart(usersCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_map('ucfirst', array_column($report_data['users_by_role'], 'role_name'))); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($report_data['users_by_role'], 'user_count')); ?>,
                    backgroundColor: ['#dc3545', '#ffc107', '#28a745']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    <?php endif; ?>

});

// Export to CSV function
function exportToCSV() {
    let csvContent = "data:text/csv;charset=utf-8,";
    
    <?php if ($report_type == 'overview'): ?>
        // Add summary data
        csvContent += "Metric,Value\n";
        csvContent += "Total Users,<?php echo $report_data['summary']['total_users'] ?? 0; ?>\n";
        csvContent += "Active Users,<?php echo $report_data['summary']['active_users'] ?? 0; ?>\n";
        csvContent += "Total Couriers,<?php echo $report_data['summary']['total_couriers'] ?? 0; ?>\n";
        csvContent += "Active Couriers,<?php echo $report_data['summary']['active_couriers'] ?? 0; ?>\n";
        csvContent += "Total Drivers,<?php echo $report_data['summary']['total_drivers'] ?? 0; ?>\n";
        csvContent += "Total Trucks,<?php echo $report_data['summary']['total_trucks'] ?? 0; ?>\n";
        csvContent += "Total Orders,<?php echo $report_data['summary']['total_orders'] ?? 0; ?>\n";
        csvContent += "Total Revenue,<?php echo $report_data['summary']['total_revenue'] ?? 0; ?>\n";
        csvContent += "Total Clients,<?php echo $report_data['summary']['total_clients'] ?? 0; ?>\n";
    <?php endif; ?>
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "report_<?php echo $report_type; ?>_<?php echo date('Y-m-d'); ?>.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>