<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

$error = '';
$success = '';
$step = 1; // Step 1: Enter email, Step 2: Enter new password
$email = '';
$token = '';
$user_data = null;

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role_id'])) {
        $role_id = $_SESSION['role_id'];
        switch($role_id) {
            case ROLE_ADMIN: header('Location: ../admin/dashboard.php'); break;
            case ROLE_DRIVER: header('Location: ../driver/dashboard.php'); break;
            case ROLE_CLIENT: header('Location: ../client/dashboard.php'); break;
        }
        exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Step 1: Verify email
    if (isset($_POST['verify_email'])) {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $stmt = $db->prepare("SELECT id, name, email, role_id FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $user_data = $stmt->fetch();
                
                if ($user_data) {
                    // Email found, proceed to step 2
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_user_id'] = $user_data['id'];
                    $_SESSION['reset_token'] = bin2hex(random_bytes(32));
                    $step = 2;
                    $success = 'Email verified. Please enter your new password.';
                } else {
                    $error = 'No account found with this email address.';
                }
            } catch (Exception $e) {
                $error = 'An error occurred. Please try again.';
                error_log("Forgot password error: " . $e->getMessage());
            }
        }
    }
    
    // Step 2: Update password
    if (isset($_POST['reset_password'])) {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate session
        if (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_token'])) {
            $error = 'Session expired. Please start again.';
            $step = 1;
            session_destroy();
        } elseif (empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill in both password fields.';
            $step = 2;
            $email = $_SESSION['reset_email'];
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long.';
            $step = 2;
            $email = $_SESSION['reset_email'];
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error = 'Password must contain at least one uppercase letter.';
            $step = 2;
            $email = $_SESSION['reset_email'];
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $error = 'Password must contain at least one lowercase letter.';
            $step = 2;
            $email = $_SESSION['reset_email'];
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $error = 'Password must contain at least one number.';
            $step = 2;
            $email = $_SESSION['reset_email'];
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
            $step = 2;
            $email = $_SESSION['reset_email'];
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? AND email = ? AND status = 'active'");
                $stmt->execute([$hashed_password, $_SESSION['reset_user_id'], $_SESSION['reset_email']]);
                
                if ($stmt->rowCount() > 0) {
                    // Clear reset session data
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_token']);
                    
                    // Set success message and redirect to login
                    $_SESSION['password_reset_success'] = true;
                    header('Location: login.php?reset=success');
                    exit();
                } else {
                    $error = 'Password update failed. Please try again.';
                    $step = 2;
                    $email = $_SESSION['reset_email'];
                }
            } catch (Exception $e) {
                $error = 'An error occurred. Please try again.';
                $step = 2;
                $email = $_SESSION['reset_email'];
                error_log("Password update error: " . $e->getMessage());
            }
        }
    }
}

// Check if returning to step 2 from a page refresh with valid session
if ($step == 1 && isset($_SESSION['reset_email']) && isset($_SESSION['reset_token'])) {
    $step = 2;
    $email = $_SESSION['reset_email'];
}

$pageTitle = "Forgot Password";
require_once ROOT_PATH . '/includes/header.php';
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f0f2f5;
        min-height: 100vh;
    }

    .forgot-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    }

    .forgot-card {
        width: 100%;
        max-width: 440px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 2px 16px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        border: 1px solid #e8ecf1;
    }

    .forgot-header {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        padding: 28px 32px;
        text-align: center;
        color: #ffffff;
    }

    .forgot-header .icon-circle {
        width: 52px;
        height: 52px;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
    }

    .forgot-header .icon-circle i {
        font-size: 24px;
        color: #ffffff;
    }

    .forgot-header h2 {
        font-size: 1.35rem;
        font-weight: 700;
        margin-bottom: 4px;
        letter-spacing: -0.3px;
    }

    .forgot-header p {
        font-size: 0.85rem;
        opacity: 0.9;
        margin: 0;
    }

    .forgot-body {
        padding: 28px 32px 24px;
    }

    .alert {
        padding: 12px 14px;
        border-radius: 8px;
        font-size: 0.875rem;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid transparent;
        line-height: 1.4;
    }

    .alert-danger {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
    }

    .alert-success {
        background: #f0fdf4;
        border-color: #bbf7d0;
        color: #166534;
    }

    .alert-info {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1e40af;
    }

    .alert i {
        font-size: 16px;
        flex-shrink: 0;
    }

    .alert .close-alert {
        margin-left: auto;
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;
        opacity: 0.6;
        font-size: 18px;
        padding: 0 2px;
        line-height: 1;
    }

    .alert .close-alert:hover {
        opacity: 1;
    }

    .user-info-box {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.85rem;
        color: #166534;
    }

    .user-info-box i {
        font-size: 18px;
        flex-shrink: 0;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        font-size: 0.825rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 6px;
        letter-spacing: 0.01em;
    }

    .input-group {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-group .input-icon {
        position: absolute;
        left: 12px;
        color: #9ca3af;
        font-size: 15px;
        z-index: 1;
        pointer-events: none;
    }

    .input-group input {
        width: 100%;
        padding: 10px 14px 10px 36px;
        border: 1.5px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.9rem;
        color: #1f2937;
        background: #f9fafb;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        outline: none;
    }

    .input-group input:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.08);
        background: #ffffff;
    }

    .input-group input::placeholder {
        color: #b0b7c3;
        font-size: 0.85rem;
    }

    .input-group .toggle-password {
        position: absolute;
        right: 8px;
        background: none;
        border: none;
        color: #9ca3af;
        cursor: pointer;
        padding: 6px 8px;
        font-size: 15px;
        z-index: 1;
        border-radius: 4px;
    }

    .input-group .toggle-password:hover {
        color: #4f46e5;
        background: #f3f4f6;
    }

    .password-requirements {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 6px;
        padding: 10px 12px;
        margin-bottom: 18px;
        font-size: 0.775rem;
        color: #92400e;
    }

    .password-requirements ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .password-requirements ul li {
        padding: 2px 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .password-requirements ul li i {
        font-size: 12px;
        width: 14px;
        text-align: center;
    }

    .btn-primary {
        width: 100%;
        padding: 11px;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: #ffffff;
        border: none;
        border-radius: 8px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        letter-spacing: 0.01em;
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
    }

    .btn-primary:active {
        transform: scale(0.985);
    }

    .btn-outline {
        width: 100%;
        padding: 10px;
        background: #ffffff;
        color: #4f46e5;
        border: 1.5px solid #4f46e5;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        text-decoration: none;
        margin-top: 10px;
    }

    .btn-outline:hover {
        background: #f5f3ff;
    }

    .forgot-footer {
        padding: 0 32px 24px;
        text-align: center;
    }

    .forgot-footer .divider {
        border-top: 1px solid #e5e7eb;
        margin-bottom: 14px;
    }

    .back-link {
        font-size: 0.85rem;
        color: #6b7280;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .back-link:hover {
        color: #4f46e5;
    }

    .step-indicator {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-bottom: 20px;
    }

    .step-dot {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 700;
        background: #e5e7eb;
        color: #6b7280;
        position: relative;
    }

    .step-dot.active {
        background: #4f46e5;
        color: #ffffff;
    }

    .step-dot.completed {
        background: #10b981;
        color: #ffffff;
    }

    .step-line {
        width: 40px;
        height: 2px;
        background: #e5e7eb;
        align-self: center;
    }

    .step-line.completed {
        background: #10b981;
    }

    .step-label {
        font-size: 0.7rem;
        color: #6b7280;
        text-align: center;
        margin-top: 2px;
    }

    .step-label.active {
        color: #4f46e5;
        font-weight: 600;
    }

    @media (max-width: 480px) {
        .forgot-wrapper {
            padding: 12px;
            align-items: flex-start;
            padding-top: 30px;
        }

        .forgot-header {
            padding: 22px 20px;
        }

        .forgot-body {
            padding: 20px;
        }

        .forgot-footer {
            padding: 0 20px 20px;
        }
    }
</style>

<div class="forgot-wrapper">
    <div class="forgot-card">
        <!-- Header -->
        <div class="forgot-header">
            <div class="icon-circle">
                <i class="fas fa-key"></i>
            </div>
            <h2>Reset Password</h2>
            <p><?php echo $step == 1 ? 'Verify your email address' : 'Create a new password'; ?></p>
        </div>

        <!-- Body -->
        <div class="forgot-body">
            <!-- Step Indicator -->
            <div style="display: flex; justify-content: center; align-items: center; margin-bottom: 20px;">
                <div style="text-align: center;">
                    <div class="step-dot <?php echo $step == 1 ? 'active' : 'completed'; ?>">1</div>
                    <div class="step-label <?php echo $step == 1 ? 'active' : ''; ?>">Email</div>
                </div>
                <div class="step-line <?php echo $step == 2 ? 'completed' : ''; ?>"></div>
                <div style="text-align: center;">
                    <div class="step-dot <?php echo $step == 2 ? 'active' : ''; ?>">2</div>
                    <div class="step-label <?php echo $step == 2 ? 'active' : ''; ?>">Password</div>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger" id="errorAlert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button class="close-alert" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php endif; ?>

            <?php if ($success && $step == 2): ?>
            <div class="alert alert-success" id="successAlert">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
                <button class="close-alert" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php endif; ?>

            <?php if ($step == 1): ?>
            <!-- Step 1: Email Verification Form -->
            <form method="POST" action="" autocomplete="off">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               value="<?php echo htmlspecialchars($email); ?>"
                               placeholder="Enter your registered email"
                               required
                               autofocus>
                    </div>
                </div>

                <button type="submit" name="verify_email" class="btn-primary">
                    <i class="fas fa-search"></i> Verify Email
                </button>
            </form>

            <?php elseif ($step == 2): ?>
            <!-- Step 2: Password Reset Form -->
            <?php if (isset($_SESSION['reset_email'])): ?>
            <div class="user-info-box">
                <i class="fas fa-user-check"></i>
                <span>Resetting password for: <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off" id="resetForm">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" 
                               id="new_password" 
                               name="new_password" 
                               placeholder="Enter new password"
                               required
                               autofocus>
                        <button type="button" class="toggle-password" data-target="new_password" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               placeholder="Confirm new password"
                               required>
                        <button type="button" class="toggle-password" data-target="confirm_password" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="password-requirements">
                    <ul>
                        <li><i class="fas fa-check-circle"></i> At least 8 characters</li>
                        <li><i class="fas fa-check-circle"></i> At least one uppercase letter</li>
                        <li><i class="fas fa-check-circle"></i> At least one lowercase letter</li>
                        <li><i class="fas fa-check-circle"></i> At least one number</li>
                    </ul>
                </div>

                <button type="submit" name="reset_password" class="btn-primary">
                    <i class="fas fa-save"></i> Update Password
                </button>

                <a href="forgot-password.php" class="btn-outline">
                    <i class="fas fa-redo"></i> Start Over
                </a>
            </form>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="forgot-footer">
            <div class="divider"></div>
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>
</div>

<script>
    // Toggle password visibility for all toggle buttons
    document.querySelectorAll('.toggle-password').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const passwordInput = document.getElementById(targetId);
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
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        var errorAlert = document.getElementById('errorAlert');
        var successAlert = document.getElementById('successAlert');
        if (errorAlert) errorAlert.style.display = 'none';
        if (successAlert) successAlert.style.display = 'none';
    }, 5000);

    // Password strength validation
    document.getElementById('resetForm')?.addEventListener('submit', function(e) {
        var newPass = document.getElementById('new_password').value;
        var confirmPass = document.getElementById('confirm_password').value;
        
        if (newPass.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters long.');
            return false;
        }
        if (!/[A-Z]/.test(newPass)) {
            e.preventDefault();
            alert('Password must contain at least one uppercase letter.');
            return false;
        }
        if (!/[a-z]/.test(newPass)) {
            e.preventDefault();
            alert('Password must contain at least one lowercase letter.');
            return false;
        }
        if (!/[0-9]/.test(newPass)) {
            e.preventDefault();
            alert('Password must contain at least one number.');
            return false;
        }
        if (newPass !== confirmPass) {
            e.preventDefault();
            alert('Passwords do not match.');
            return false;
        }
    });
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>