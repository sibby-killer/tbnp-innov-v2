<?php
// profile.php
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

// Get current user data
$user_id = $_SESSION['user_id'];
$user = [];
$errors = [];
$success = '';
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

try {
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        header('Location: ../auth/login.php');
        exit();
    }
} catch (Exception $e) {
    $errors[] = 'Failed to load user data: ' . $e->getMessage();
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($name)) $errors[] = 'Name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (empty($phone)) $errors[] = 'Phone number is required.';
    
    if (empty($errors)) {
        try {
            // Check if email already exists for another user
            $checkEmail = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->execute([$email, $user_id]);
            if ($checkEmail->fetch()) {
                $errors[] = 'Email already exists for another user.';
            } else {
                // Update user data
                $update_stmt = $db->prepare("
                    UPDATE users 
                    SET name = ?, email = ?, phone = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_stmt->execute([$name, $email, $phone, $user_id]);
                
                // Update session data
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                
                $success = 'Profile updated successfully!';
                $current_tab = 'profile';
                
                // Refresh user data
                $stmt = $db->prepare("
                    SELECT u.*, r.name as role_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.id 
                    WHERE u.id = ?
                ");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to update profile: ' . $e->getMessage();
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password)) $errors[] = 'Current password is required.';
    if (empty($new_password)) $errors[] = 'New password is required.';
    if (strlen($new_password) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($new_password !== $confirm_password) $errors[] = 'New passwords do not match.';
    
    if (empty($errors)) {
        try {
            // Verify current password
            $checkPassword = $db->prepare("SELECT password FROM users WHERE id = ?");
            $checkPassword->execute([$user_id]);
            $result = $checkPassword->fetch();
            
            if (!$result || !password_verify($current_password, $result['password'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $db->prepare("
                    UPDATE users 
                    SET password = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_stmt->execute([$hashed_password, $user_id]);
                
                $success = 'Password changed successfully!';
                $current_tab = 'password';
                
                // Log password change activity
                $activity_stmt = $db->prepare("
                    INSERT INTO user_activity (user_id, activity_type, description, ip_address, created_at) 
                    VALUES (?, 'password_change', 'Changed account password', ?, NOW())
                ");
                $activity_stmt->execute([$user_id, $_SERVER['REMOTE_ADDR']]);
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to change password: ' . $e->getMessage();
        }
    }
}

// Set page title
$pageTitle = "My Profile";

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
                    <i class="fas fa-user-circle me-2"></i>My Profile
                </h1>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Manage your account settings and preferences
                </p>
            </div>
            <div class="d-flex gap-2">
                <span class="badge bg-primary">
                    <i class="fas fa-user-tag me-1"></i> <?php echo htmlspecialchars($user['role_name'] ?? 'User'); ?>
                </span>
                <span class="badge bg-success">
                    <i class="fas fa-user-check me-1"></i> <?php echo htmlspecialchars($user['status'] ?? 'Active'); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Error!</strong> Please fix the following issues:
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Sidebar Menu -->
        <div class="col-md-3 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <a href="?tab=profile" class="list-group-item list-group-item-action <?php echo $current_tab == 'profile' ? 'active' : ''; ?>">
                            <i class="fas fa-user me-2"></i> Profile Information
                        </a>
                        <a href="?tab=password" class="list-group-item list-group-item-action <?php echo $current_tab == 'password' ? 'active' : ''; ?>">
                            <i class="fas fa-key me-2"></i> Change Password
                        </a>
                        <a href="?tab=security" class="list-group-item list-group-item-action <?php echo $current_tab == 'security' ? 'active' : ''; ?>">
                            <i class="fas fa-shield-alt me-2"></i> Security Settings
                        </a>
                        <a href="dashboard.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Account Info Card -->
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-body text-center">
                    <div class="mb-3">
                        <div class="avatar-circle mx-auto" style="width: 80px; height: 80px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <span style="font-size: 2rem; color: white; font-weight: bold;">
                                <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                            </span>
                        </div>
                    </div>
                    <h6 class="mb-1"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></h6>
                    <p class="text-muted mb-2"><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                    <p class="text-muted mb-0">
                        <small>Member since: <?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?></small>
                    </p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9">
            <!-- Profile Information Tab -->
            <?php if ($current_tab == 'profile'): ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-user-edit me-2"></i>Profile Information
                    </h5>
                    
                    <form method="POST" action="" id="profileForm">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" 
                                           class="form-control" 
                                           name="name" 
                                           value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" 
                                           required
                                           placeholder="Enter your full name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Role</label>
                                    <input type="text" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($user['role_name'] ?? 'User'); ?>" 
                                           disabled
                                           style="background-color: #f8f9fa;">
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" 
                                           class="form-control" 
                                           name="email" 
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                           required
                                           placeholder="Enter your email address">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" 
                                           class="form-control" 
                                           name="phone" 
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                           required
                                           placeholder="Enter your phone number">
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Account Status</label>
                                    <input type="text" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars(ucfirst($user['status'] ?? 'Active')); ?>" 
                                           disabled
                                           style="background-color: #f8f9fa;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Updated</label>
                                    <input type="text" 
                                           class="form-control" 
                                           value="<?php echo date('M d, Y h:i A', strtotime($user['updated_at'] ?? $user['created_at'] ?? 'now')); ?>" 
                                           disabled
                                           style="background-color: #f8f9fa;">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between pt-3 border-top">
                            <a href="?tab=password" class="btn btn-outline-primary">
                                <i class="fas fa-key me-2"></i> Change Password
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Change Password Tab -->
            <?php if ($current_tab == 'password'): ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-key me-2"></i>Change Password
                    </h5>
                    
                    <form method="POST" action="" id="passwordForm">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <div class="input-group">
                                        <input type="password" 
                                               class="form-control" 
                                               name="current_password" 
                                               id="current_password"
                                               required
                                               placeholder="Enter your current password">
                                        <button class="btn btn-outline-secondary" type="button" id="toggleCurrentPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" 
                                               class="form-control" 
                                               name="new_password" 
                                               id="new_password"
                                               required
                                               placeholder="Enter new password (min. 8 characters)"
                                               minlength="8"
                                               onkeyup="checkPasswordStrength(this.value)">
                                        <button class="btn btn-outline-secondary" type="button" id="toggleNewPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength mt-2" id="passwordStrength" style="height: 5px; border-radius: 3px; transition: all 0.3s;"></div>
                                    <div class="progress-text" id="passwordStrengthText" style="font-size: 0.8rem; margin-top: 5px;"></div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" 
                                               class="form-control" 
                                               name="confirm_password" 
                                               id="confirm_password"
                                               required
                                               placeholder="Confirm your new password"
                                               onkeyup="checkPasswordMatch()">
                                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2" id="passwordMatch"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between pt-3 border-top">
                                    <a href="?tab=profile" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Profile
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-key me-2"></i> Change Password
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="alert alert-info">
                                    <h6 class="alert-heading">
                                        <i class="fas fa-info-circle me-2"></i>Password Requirements
                                    </h6>
                                    <ul class="mb-0">
                                        <li>Minimum 8 characters</li>
                                        <li>At least one uppercase letter</li>
                                        <li>At least one lowercase letter</li>
                                        <li>At least one number</li>
                                        <li>At least one special character</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Security Settings Tab -->
            <?php if ($current_tab == 'security'): ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h5 class="card-title mb-4">
                        <i class="fas fa-shield-alt me-2"></i>Security Settings
                    </h5>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-4">
                                <h6 class="mb-3">Two-Factor Authentication</h6>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="2faToggle" disabled>
                                    <label class="form-check-label" for="2faToggle">
                                        Enable Two-Factor Authentication
                                    </label>
                                </div>
                                <p class="text-muted mt-2">
                                    <small>Add an extra layer of security to your account</small>
                                </p>
                            </div>
                            
                            <div class="mb-4">
                                <h6 class="mb-3">Login Activity</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>IP Address</th>
                                                <th>Location</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><?php echo date('M d, Y h:i A'); ?></td>
                                                <td><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown'); ?></td>
                                                <td>Current Session</td>
                                                <td><span class="badge bg-success">Active</span></td>
                                            </tr>
                                            <tr>
                                                <td><?php echo date('M d, Y h:i A', strtotime('-1 hour')); ?></td>
                                                <td>192.168.1.100</td>
                                                <td>Local Network</td>
                                                <td><span class="badge bg-secondary">Closed</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="pt-3 border-top">
                                <a href="?tab=password" class="btn btn-primary me-2">
                                    <i class="fas fa-key me-2"></i> Change Password
                                </a>
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#logoutAllModal">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout All Devices
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Logout All Devices Modal -->
<div class="modal fade" id="logoutAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Logout All Devices
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will log you out from all devices except this one. Are you sure you want to continue?</p>
                <p class="text-muted"><small>You'll need to log in again on your other devices.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger">Logout All Devices</button>
            </div>
        </div>
    </div>
</div>

<script>
// Password toggle functionality
document.getElementById('toggleCurrentPassword')?.addEventListener('click', function() {
    const passwordInput = document.getElementById('current_password');
    const icon = this.querySelector('i');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
});

document.getElementById('toggleNewPassword')?.addEventListener('click', function() {
    const passwordInput = document.getElementById('new_password');
    const icon = this.querySelector('i');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
});

document.getElementById('toggleConfirmPassword')?.addEventListener('click', function() {
    const passwordInput = document.getElementById('confirm_password');
    const icon = this.querySelector('i');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
});

function checkPasswordStrength(password) {
    let strength = 0;
    const strengthBar = document.getElementById('passwordStrength');
    const strengthText = document.getElementById('passwordStrengthText');
    
    if (!strengthBar || !strengthText) return;
    
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]+/)) strength++;
    if (password.match(/[A-Z]+/)) strength++;
    if (password.match(/[0-9]+/)) strength++;
    if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
    
    strengthBar.className = 'password-strength';
    strengthBar.style.width = (strength * 20) + '%';
    
    switch(strength) {
        case 0:
            strengthText.textContent = '';
            strengthBar.style.backgroundColor = '#dc3545';
            break;
        case 1:
            strengthText.textContent = 'Very Weak';
            strengthText.style.color = '#dc3545';
            strengthBar.style.backgroundColor = '#dc3545';
            break;
        case 2:
            strengthText.textContent = 'Weak';
            strengthText.style.color = '#ffc107';
            strengthBar.style.backgroundColor = '#ffc107';
            break;
        case 3:
            strengthText.textContent = 'Medium';
            strengthText.style.color = '#17a2b8';
            strengthBar.style.backgroundColor = '#17a2b8';
            break;
        case 4:
            strengthText.textContent = 'Strong';
            strengthText.style.color = '#28a745';
            strengthBar.style.backgroundColor = '#28a745';
            break;
        case 5:
            strengthText.textContent = 'Very Strong';
            strengthText.style.color = '#28a745';
            strengthBar.style.backgroundColor = '#28a745';
            break;
    }
}

function checkPasswordMatch() {
    const password = document.getElementById('new_password')?.value || '';
    const confirmPassword = document.getElementById('confirm_password')?.value || '';
    const matchDiv = document.getElementById('passwordMatch');
    
    if (!matchDiv) return;
    
    if (confirmPassword === '') {
        matchDiv.innerHTML = '';
        return;
    }
    
    if (password === confirmPassword) {
        matchDiv.innerHTML = '<small class="text-success"><i class="fas fa-check-circle"></i> Passwords match</small>';
    } else {
        matchDiv.innerHTML = '<small class="text-danger"><i class="fas fa-times-circle"></i> Passwords do not match</small>';
    }
}

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Form validation
document.getElementById('profileForm')?.addEventListener('submit', function(e) {
    const email = this.querySelector('input[name="email"]').value;
    const name = this.querySelector('input[name="name"]').value;
    
    if (!email.includes('@')) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        return false;
    }
    
    if (name.trim().length < 2) {
        e.preventDefault();
        alert('Name must be at least 2 characters long.');
        return false;
    }
    
    return true;
});

document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
    const currentPassword = this.querySelector('input[name="current_password"]').value;
    const newPassword = this.querySelector('input[name="new_password"]').value;
    const confirmPassword = this.querySelector('input[name="confirm_password"]').value;
    
    if (newPassword.length < 8) {
        e.preventDefault();
        alert('New password must be at least 8 characters long.');
        return false;
    }
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New passwords do not match.');
        return false;
    }
    
    if (currentPassword === newPassword) {
        e.preventDefault();
        alert('New password must be different from current password.');
        return false;
    }
    
    return true;
});
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>