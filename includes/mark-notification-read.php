<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$notificationId = $data['notification_id'] ?? null;

if (!$notificationId) {
    echo json_encode(['success' => false, 'message' => 'No notification ID']);
    exit;
}

try {
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE id = ? AND user_id = ?
    ");
    $success = $stmt->execute([$notificationId, $_SESSION['user_id']]);
    
    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    error_log("Error marking notification read: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>