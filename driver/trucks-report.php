<?php
// trucks-report.php - Report Issue for Truck
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

// Check if user is driver
if ($_SESSION['role_id'] != ROLE_DRIVER) {
    header('Location: ../index.php');
    exit();
}

$pageTitle = "Report Truck Issue - Driver Dashboard";
$driver_id = $_SESSION['user_id'];

// Get truck ID from URL
$truck_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$truck_id) {
    header('Location: trucks.php');
    exit();
}

// Get truck information
$truck_info = [];
$error = '';
$success = '';

try {
    // Verify the truck belongs to this driver
    $truck_stmt = $db->prepare("
        SELECT t.*, d.user_id as driver_user_id, c.name as courier_name
        FROM trucks t
        LEFT JOIN drivers d ON t.driver_id = d.id
        LEFT JOIN couriers c ON t.courier_id = c.id
        WHERE t.id = ? AND d.user_id = ?
    ");
    $truck_stmt->execute([$truck_id, $driver_id]);
    $truck_info = $truck_stmt->fetch();
    
    if (!$truck_info) {
        $error = "Truck not found or you don't have permission to report issues for this truck.";
    }
} catch (Exception $e) {
    error_log("Truck report error: " . $e->getMessage());
    $error = "Error loading truck information: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    try {
        $issue_type = isset($_POST['issue_type']) ? trim($_POST['issue_type']) : '';
        $severity = isset($_POST['severity']) ? trim($_POST['severity']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $current_location = isset($_POST['current_location']) ? trim($_POST['current_location']) : '';
        $emergency_contact = isset($_POST['emergency_contact']) ? trim($_POST['emergency_contact']) : '';
        
        // Validate inputs
        if (empty($issue_type)) {
            throw new Exception("Please select an issue type.");
        }
        
        if (empty($description)) {
            throw new Exception("Please provide a description of the issue.");
        }
        
        if (empty($severity)) {
            throw new Exception("Please select the severity level.");
        }
        
        // Check if truck_issues table exists, if not create it
        $table_check = $db->prepare("SHOW TABLES LIKE 'truck_issues'");
        $table_check->execute();
        $table_exists = $table_check->fetch();
        
        if (!$table_exists) {
            // Create truck_issues table
            $create_table = $db->prepare("
                CREATE TABLE IF NOT EXISTS truck_issues (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    truck_id INT NOT NULL,
                    driver_id INT NOT NULL,
                    issue_type VARCHAR(100) NOT NULL,
                    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
                    description TEXT NOT NULL,
                    current_location VARCHAR(255),
                    emergency_contact VARCHAR(50),
                    status ENUM('reported', 'acknowledged', 'in_progress', 'resolved', 'closed') DEFAULT 'reported',
                    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    acknowledged_at TIMESTAMP NULL,
                    resolved_at TIMESTAMP NULL,
                    resolved_by INT NULL,
                    resolution_notes TEXT,
                    FOREIGN KEY (truck_id) REFERENCES trucks(id) ON DELETE CASCADE,
                    FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $create_table->execute();
        }
        
        // Get driver ID from drivers table
        $driver_stmt = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
        $driver_stmt->execute([$driver_id]);
        $driver_record = $driver_stmt->fetch();
        
        if (!$driver_record) {
            throw new Exception("Driver record not found.");
        }
        
        $driver_db_id = $driver_record['id'];
        
        // Insert issue report
        $insert_stmt = $db->prepare("
            INSERT INTO truck_issues 
            (truck_id, driver_id, issue_type, severity, description, current_location, emergency_contact, status, reported_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'reported', NOW())
        ");
        
        $insert_stmt->execute([
            $truck_id,
            $driver_db_id,
            $issue_type,
            $severity,
            $description,
            $current_location,
            $emergency_contact
        ]);
        
        // Update truck status if issue is critical
        if ($severity === 'critical') {
            $update_stmt = $db->prepare("
                UPDATE trucks 
                SET status = 'maintenance', 
                    notes = CONCAT(IFNULL(notes, ''), '\nCRITICAL ISSUE REPORTED: ', ?)
                WHERE id = ?
            ");
            $update_stmt->execute(["$issue_type - $description", $truck_id]);
        }
        
        // Create notification for administrators/dispatchers
        try {
            $notif_check = $db->prepare("SHOW TABLES LIKE 'notifications'");
            $notif_check->execute();
            $notif_exists = $notif_check->fetch();
            
            if ($notif_exists) {
                $notification_msg = "Truck {$truck_info['plate_number']} reported issue: {$issue_type} (Severity: {$severity})";
                $notif_stmt = $db->prepare("
                    INSERT INTO notifications (user_id, title, message, type, priority, created_at)
                    SELECT id, 'Truck Issue Reported', ?, 'warning', ?, NOW()
                    FROM users 
                    WHERE role_id IN (?, ?)  -- Admin and Dispatcher roles
                ");
                $notif_priority = ($severity === 'critical') ? 'high' : (($severity === 'high') ? 'medium' : 'low');
                $notif_stmt->execute([$notification_msg, $notif_priority, ROLE_ADMIN, ROLE_DISPATCHER]);
            }
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
            // Continue even if notification fails
        }
        
        $success = "Issue reported successfully! Our team has been notified and will contact you shortly.";
        
        // Redirect after 3 seconds if successful
        header("refresh:3;url=trucks.php");
        
    } catch (Exception $e) {
        $error = "Error submitting report: " . $e->getMessage();
    }
}

// Include header and sidebar
require_once ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/driver/driver-sidebar.php';
?>

<style>
/* Prevent content from spilling over sidebar */
.driver-container {
    margin-left: 250px;
    padding: 20px;
    max-width: calc(100% - 250px);
    overflow-x: hidden;
    box-sizing: border-box;
}

@media (max-width: 992px) {
    .driver-container {
        margin-left: 0;
        max-width: 100%;
        padding: 15px;
    }
}

/* Form styling */
.form-card {
    border-radius: 10px;
    border: none;
    box-shadow: 0 0 20px rgba(0,0,0,0.1);
}

.form-label {
    font-weight: 600;
    color: #333;
}

.required:after {
    content: " *";
    color: #dc3545;
}

/* Severity badges */
.severity-badge {
    cursor: pointer;
    transition: all 0.2s;
}

.severity-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

/* Preview card */
.preview-card {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .form-section {
        margin-bottom: 20px;
    }
}
</style>

<!-- Driver Container -->
<div class="driver-container">
    <!-- Page Header -->
    <div class="page-header bg-white p-4 rounded shadow-sm mb-4" style="border-left: 4px solid #dc3545;">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1 class="h4 mb-1" style="color: #1f2937; font-weight: 700;">
                    <i class="fas fa-exclamation-triangle me-2"></i>Report Truck Issue
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    <?php if (!empty($truck_info)): ?>
                        Reporting issue for: <strong><?php echo htmlspecialchars($truck_info['plate_number']); ?></strong>
                        (<?php echo htmlspecialchars($truck_info['brand'] ?? ''); ?> <?php echo htmlspecialchars($truck_info['model'] ?? ''); ?>)
                    <?php else: ?>
                        Truck Issue Reporting
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <a href="trucks.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Truck Details
                </a>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Main Content -->
    <?php if (!empty($truck_info)): ?>
        <div class="row">
            <!-- Left Column: Form -->
            <div class="col-lg-8">
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <form method="POST" action="" id="issueReportForm">
                            <!-- Truck Information -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-truck me-2 text-primary"></i>Truck Information
                                </h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Plate Number</label>
                                        <div class="form-control bg-light"><?php echo htmlspecialchars($truck_info['plate_number']); ?></div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Model & Brand</label>
                                        <div class="form-control bg-light">
                                            <?php echo htmlspecialchars(($truck_info['brand'] ?? '') . ' ' . ($truck_info['model'] ?? '')); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Current Status</label>
                                        <div class="form-control bg-light">
                                            <?php 
                                            $status = $truck_info['status'] ?? 'unknown';
                                            echo ucfirst(str_replace('_', ' ', $status));
                                            ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Courier Company</label>
                                        <div class="form-control bg-light"><?php echo htmlspecialchars($truck_info['courier_name'] ?? 'Not assigned'); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Issue Details -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-info-circle me-2 text-warning"></i>Issue Details
                                </h5>
                                
                                <!-- Issue Type -->
                                <div class="mb-3">
                                    <label class="form-label required">Issue Type</label>
                                    <select class="form-select" name="issue_type" required>
                                        <option value="">Select issue type</option>
                                        <option value="mechanical">Mechanical Problem</option>
                                        <option value="electrical">Electrical Issue</option>
                                        <option value="engine">Engine Trouble</option>
                                        <option value="brakes">Brake System</option>
                                        <option value="tires">Tire Problem</option>
                                        <option value="fuel">Fuel System</option>
                                        <option value="transmission">Transmission</option>
                                        <option value="suspension">Suspension</option>
                                        <option value="steering">Steering Problem</option>
                                        <option value="lights">Lighting System</option>
                                        <option value="ac">AC/Heating System</option>
                                        <option value="accident">Accident/Damage</option>
                                        <option value="breakdown">Complete Breakdown</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                
                                <!-- Severity Level -->
                                <div class="mb-4">
                                    <label class="form-label required d-block">Severity Level</label>
                                    <div class="row g-2">
                                        <div class="col-6 col-md-3">
                                            <input type="radio" class="btn-check" name="severity" value="low" id="severity_low" required>
                                            <label class="btn btn-outline-success w-100 severity-badge" for="severity_low">
                                                <i class="fas fa-info-circle me-1"></i> Low
                                            </label>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <input type="radio" class="btn-check" name="severity" value="medium" id="severity_medium">
                                            <label class="btn btn-outline-warning w-100 severity-badge" for="severity_medium">
                                                <i class="fas fa-exclamation-circle me-1"></i> Medium
                                            </label>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <input type="radio" class="btn-check" name="severity" value="high" id="severity_high">
                                            <label class="btn btn-outline-orange w-100 severity-badge" for="severity_high">
                                                <i class="fas fa-exclamation-triangle me-1"></i> High
                                            </label>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <input type="radio" class="btn-check" name="severity" value="critical" id="severity_critical">
                                            <label class="btn btn-outline-danger w-100 severity-badge" for="severity_critical">
                                                <i class="fas fa-skull-crossbones me-1"></i> Critical
                                            </label>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <strong>Low:</strong> Minor issue, truck still operational<br>
                                                <strong>Medium:</strong> Significant issue, needs attention soon<br>
                                                <strong>High:</strong> Serious issue, immediate attention required<br>
                                                <strong>Critical:</strong> Safety hazard, stop driving immediately
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Description -->
                                <div class="mb-3">
                                    <label class="form-label required">Detailed Description</label>
                                    <textarea class="form-control" name="description" rows="5" 
                                              placeholder="Please describe the issue in detail. Include when it started, symptoms, and any warning lights..." 
                                              required></textarea>
                                    <small class="text-muted">
                                        Be as detailed as possible to help our technicians diagnose the problem.
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Location & Contact -->
                            <div class="mb-4">
                                <h5 class="mb-3">
                                    <i class="fas fa-map-marker-alt me-2 text-info"></i>Location & Contact
                                </h5>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Current Location</label>
                                        <input type="text" class="form-control" name="current_location" 
                                               placeholder="Where is the truck currently located?">
                                        <small class="text-muted">
                                            Street address, landmark, or GPS coordinates
                                        </small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Emergency Contact Number</label>
                                        <input type="text" class="form-control" name="emergency_contact" 
                                               placeholder="Your current contact number">
                                        <small class="text-muted">
                                            Where can we reach you regarding this issue?
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between">
                                    <button type="button" class="btn btn-outline-secondary" onclick="previewReport()">
                                        <i class="fas fa-eye me-2"></i> Preview Report
                                    </button>
                                    <button type="submit" name="submit_report" class="btn btn-danger px-4">
                                        <i class="fas fa-paper-plane me-2"></i> Submit Issue Report
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Help & Preview -->
            <div class="col-lg-4">
                <!-- Help Card -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-question-circle me-2 text-primary"></i>Need Help?
                        </h5>
                        
                        <div class="mb-3">
                            <h6 class="small fw-bold">Emergency Contacts:</h6>
                            <ul class="list-unstyled small">
                                <?php if (!empty($truck_info['courier_name'])): ?>
                                    <li class="mb-2">
                                        <i class="fas fa-building text-muted me-2"></i>
                                        <?php echo htmlspecialchars($truck_info['courier_name']); ?>
                                        <?php if (!empty($truck_info['courier_phone'])): ?>
                                            <br>
                                            <a href="tel:<?php echo htmlspecialchars($truck_info['courier_phone']); ?>" 
                                               class="text-decoration-none">
                                                <i class="fas fa-phone text-success me-1"></i>
                                                <?php echo htmlspecialchars($truck_info['courier_phone']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                                <li class="mb-2">
                                    <i class="fas fa-ambulance text-danger me-2"></i>
                                    Emergency Services: 
                                    <a href="tel:999" class="text-decoration-none">999</a>
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-road text-warning me-2"></i>
                                    Roadside Assistance: 
                                    <a href="tel:0700000000" class="text-decoration-none">0700-000-000</a>
                                </li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning small">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> For critical safety issues, stop driving immediately and move to a safe location before reporting.
                        </div>
                    </div>
                </div>
                
                <!-- Preview Card (Hidden by default) -->
                <div class="card preview-card mb-4 d-none" id="previewCard">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-file-alt me-2"></i>Report Preview
                        </h5>
                        <div id="previewContent">
                            <!-- Preview will be inserted here by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <!-- Recent Issues -->
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-history me-2"></i>Recent Issues
                        </h5>
                        <div class="small text-muted">
                            <?php
                            try {
                                // Get recent issues for this truck
                                $recent_stmt = $db->prepare("
                                    SELECT issue_type, severity, status, reported_at 
                                    FROM truck_issues 
                                    WHERE truck_id = ? 
                                    ORDER BY reported_at DESC 
                                    LIMIT 3
                                ");
                                $recent_stmt->execute([$truck_id]);
                                $recent_issues = $recent_stmt->fetchAll();
                                
                                if (!empty($recent_issues)) {
                                    foreach ($recent_issues as $issue) {
                                        $badge_class = '';
                                        if ($issue['severity'] == 'critical') $badge_class = 'danger';
                                        elseif ($issue['severity'] == 'high') $badge_class = 'warning';
                                        elseif ($issue['severity'] == 'medium') $badge_class = 'info';
                                        else $badge_class = 'success';
                                        
                                        echo '<div class="mb-2 pb-2 border-bottom">';
                                        echo '<div class="d-flex justify-content-between">';
                                        echo '<strong>' . htmlspecialchars($issue['issue_type']) . '</strong>';
                                        echo '<span class="badge bg-' . $badge_class . '">' . ucfirst($issue['severity']) . '</span>';
                                        echo '</div>';
                                        echo '<div class="text-muted">' . date('M d, H:i', strtotime($issue['reported_at'])) . '</div>';
                                        echo '<div>Status: <span class="badge bg-secondary">' . ucfirst($issue['status']) . '</span></div>';
                                        echo '</div>';
                                    }
                                } else {
                                    echo '<div class="text-center text-muted py-3">';
                                    echo '<i class="fas fa-check-circle fa-2x mb-2"></i><br>';
                                    echo 'No recent issues reported';
                                    echo '</div>';
                                }
                            } catch (Exception $e) {
                                echo '<div class="text-muted">Unable to load recent issues</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript for Form Handling -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.getElementById('issueReportForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const severity = document.querySelector('input[name="severity"]:checked');
            const issueType = document.querySelector('select[name="issue_type"]');
            const description = document.querySelector('textarea[name="description"]');
            
            let errors = [];
            
            if (!issueType.value) {
                errors.push('Please select an issue type');
                issueType.classList.add('is-invalid');
            } else {
                issueType.classList.remove('is-invalid');
            }
            
            if (!severity) {
                errors.push('Please select a severity level');
            }
            
            if (!description.value.trim()) {
                errors.push('Please provide a description');
                description.classList.add('is-invalid');
            } else {
                description.classList.remove('is-invalid');
            }
            
            if (errors.length > 0) {
                e.preventDefault();
                alert('Please fix the following errors:\n\n' + errors.join('\n'));
                return false;
            }
            
            // Confirm submission for critical issues
            if (severity.value === 'critical') {
                if (!confirm('WARNING: You are reporting a CRITICAL issue. This will mark the truck as out of service.\n\nAre you sure you want to proceed?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
    
    // Severity selection styling
    document.querySelectorAll('.severity-badge').forEach(badge => {
        badge.addEventListener('click', function() {
            document.querySelectorAll('.severity-badge').forEach(b => {
                b.classList.remove('active');
            });
            this.classList.add('active');
        });
    });
});

function previewReport() {
    const form = document.getElementById('issueReportForm');
    const previewCard = document.getElementById('previewCard');
    const previewContent = document.getElementById('previewContent');
    
    // Get form values
    const issueType = form.querySelector('select[name="issue_type"]').value || 'Not specified';
    const severity = form.querySelector('input[name="severity"]:checked')?.value || 'Not specified';
    const description = form.querySelector('textarea[name="description"]').value || 'Not provided';
    const location = form.querySelector('input[name="current_location"]').value || 'Not specified';
    const contact = form.querySelector('input[name="emergency_contact"]').value || 'Not provided';
    
    // Severity badge class
    let severityClass = 'secondary';
    if (severity === 'low') severityClass = 'success';
    else if (severity === 'medium') severityClass = 'warning';
    else if (severity === 'high') severityClass = 'orange';
    else if (severity === 'critical') severityClass = 'danger';
    
    // Build preview HTML
    let html = `
        <div class="mb-3">
            <strong>Truck:</strong> <?php echo htmlspecialchars($truck_info['plate_number'] ?? ''); ?>
        </div>
        <div class="mb-3">
            <strong>Issue Type:</strong> ${issueType.replace('_', ' ').toUpperCase()}
        </div>
        <div class="mb-3">
            <strong>Severity:</strong> 
            <span class="badge bg-${severityClass}">${severity.toUpperCase()}</span>
        </div>
        <div class="mb-3">
            <strong>Description:</strong>
            <div class="bg-light p-2 rounded mt-1 small">${description}</div>
        </div>
        <div class="mb-3">
            <strong>Location:</strong> ${location}
        </div>
        <div class="mb-3">
            <strong>Contact:</strong> ${contact}
        </div>
        <hr>
        <div class="alert alert-info small">
            <i class="fas fa-info-circle me-2"></i>
            This is a preview of your report. Click "Submit Issue Report" to send it to the maintenance team.
        </div>
    `;
    
    previewContent.innerHTML = html;
    previewCard.classList.remove('d-none');
    
    // Scroll to preview
    previewCard.scrollIntoView({ behavior: 'smooth' });
}

// Auto-fill current location if geolocation is available
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(function(position) {
        const lat = position.coords.latitude;
        const lng = position.coords.longitude;
        const locationInput = document.querySelector('input[name="current_location"]');
        
        if (locationInput && !locationInput.value) {
            // Use reverse geocoding to get address (simplified)
            locationInput.value = `GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            
            // Show success message
            const successMsg = document.createElement('small');
            successMsg.className = 'text-success';
            successMsg.innerHTML = '<i class="fas fa-check-circle me-1"></i>Location detected from GPS';
            locationInput.parentNode.appendChild(successMsg);
        }
    }, function(error) {
        console.log('Geolocation error:', error);
    });
}
</script>

<style>
.btn-outline-orange {
    color: #fd7e14;
    border-color: #fd7e14;
}

.btn-check:checked + .btn-outline-orange,
.btn-outline-orange:hover {
    color: #fff;
    background-color: #fd7e14;
    border-color: #fd7e14;
}

.bg-orange {
    background-color: #fd7e14 !important;
    color: white;
}
</style>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>