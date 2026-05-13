<?php
// Prevent direct access
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

// Site Constants
define('SITE_NAME', 'Courier Truck Management System');
define('SITE_URL', 'http://localhost/courier-system/');
define('SITE_TIMEZONE', 'UTC');

// Path Constants
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('LOG_PATH', ROOT_PATH . '/logs/');

// User Roles (must match database)
define('ROLE_ADMIN', 1);
define('ROLE_DRIVER', 2);
define('ROLE_CLIENT', 3);

// Order Status IDs (must match database)
define('STATUS_PENDING', 1);
define('STATUS_CONFIRMED', 2);
define('STATUS_ASSIGNED', 3);
define('STATUS_PICKED_UP', 4);
define('STATUS_IN_TRANSIT', 5);
define('STATUS_OUT_FOR_DELIVERY', 6);
define('STATUS_DELIVERED', 7);
define('STATUS_CANCELLED', 8);
define('STATUS_RETURNED', 9);

// Truck Status
define('TRUCK_AVAILABLE', 'available');
define('TRUCK_ASSIGNED', 'assigned');
define('TRUCK_MAINTENANCE', 'maintenance');
define('TRUCK_OUT_OF_SERVICE', 'out_of_service');

// Driver Status
define('DRIVER_AVAILABLE', 'available');
define('DRIVER_ON_DELIVERY', 'on_delivery');
define('DRIVER_INACTIVE', 'inactive');
define('DRIVER_ON_BREAK', 'on_break');

// File Upload Constants
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'txt']);

// Pagination
define('ITEMS_PER_PAGE', 10);

// Security
define('SESSION_TIMEOUT', 3600); // 1 hour
define('LOGIN_ATTEMPTS_LIMIT', 5);
define('LOCKOUT_TIME', 900); // 15 minutes

// ================== NOTIFICATION CONSTANTS ==================

// Notification Types
define('NOTIFICATION_SYSTEM', 'system');
define('NOTIFICATION_ORDER', 'order');
define('NOTIFICATION_DRIVER', 'driver');
define('NOTIFICATION_USER', 'user');
define('NOTIFICATION_PAYMENT', 'payment');
define('NOTIFICATION_ALERT', 'alert');
define('NOTIFICATION_SECURITY', 'security');
define('NOTIFICATION_MAINTENANCE', 'maintenance');

// Notification Status
define('NOTIFICATION_UNREAD', 0);
define('NOTIFICATION_READ', 1);

// Notification Categories
define('CATEGORY_SYSTEM_UPDATE', 'system_update');
define('CATEGORY_ORDER_UPDATE', 'order_update');
define('CATEGORY_NEW_ORDER', 'new_order');
define('CATEGORY_DRIVER_ASSIGNMENT', 'driver_assignment');
define('CATEGORY_DELIVERY_STATUS', 'delivery_status');
define('CATEGORY_PAYMENT_RECEIVED', 'payment_received');
define('CATEGORY_PAYMENT_DUE', 'payment_due');
define('CATEGORY_USER_ACCOUNT', 'user_account');
define('CATEGORY_SECURITY_ALERT', 'security_alert');
define('CATEGORY_MAINTENANCE_REMINDER', 'maintenance_reminder');

// Notification Settings (for user preferences)
define('SETTING_EMAIL_NOTIFICATIONS', 'email_notifications');
define('SETTING_SMS_NOTIFICATIONS', 'sms_notifications');
define('SETTING_PUSH_NOTIFICATIONS', 'push_notifications');
define('SETTING_ORDER_UPDATES', 'order_updates');
define('SETTING_PAYMENT_REMINDERS', 'payment_reminders');
define('SETTING_SYSTEM_ANNOUNCEMENTS', 'system_announcements');

// Notification Limits
define('MAX_NOTIFICATIONS_PER_USER', 100); // Maximum stored notifications per user
define('NOTIFICATION_RETENTION_DAYS', 30); // Keep notifications for 30 days
define('NOTIFICATION_POPUP_TIMEOUT', 5000); // 5 seconds for popup display

// Notification Display Settings
define('NOTIFICATIONS_PER_PAGE', 20); // For pagination
define('NOTIFICATION_PREVIEW_LENGTH', 100); // Characters to show in preview
define('NOTIFICATION_AUTO_DISMISS', true); // Auto dismiss after timeout
define('NOTIFICATION_MAX_AGE_DAYS', 7); // Show "new" badge for up to 7 days
?>