<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// Check if user is already logged in and redirect BEFORE any output
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

$email = '';
$password = '';
$error = '';
$success = '';
$login_attempts = isset($_SESSION['login_attempts']) ? $_SESSION['login_attempts'] : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($login_attempts >= LOGIN_ATTEMPTS_LIMIT) {
        $error = 'Too many failed attempts. Please try again after 15 minutes.';
    } else {
        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['login_attempts'] = 0;
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role_name'] = $user['role_id'] == ROLE_ADMIN ? 'admin' : ($user['role_id'] == ROLE_DRIVER ? 'driver' : 'client');
                
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expiry = time() + (30 * 24 * 60 * 60);
                    setcookie('remember_token', $token, $expiry, '/', '', false, true);
                }
                
                // Redirect immediately - no output before this
                switch($user['role_id']) {
                    case ROLE_ADMIN: header('Location: ../admin/dashboard.php'); exit();
                    case ROLE_DRIVER: header('Location: ../driver/dashboard.php'); exit();
                    case ROLE_CLIENT: header('Location: ../client/dashboard.php'); exit();
                    default: header('Location: ../index.php'); exit();
                }
            } else {
                // Check if user exists but has pending status
                $checkStmt = $db->prepare("SELECT status FROM users WHERE email = ?");
                $checkStmt->execute([$email]);
                $existingUser = $checkStmt->fetch();

                if ($existingUser && $existingUser['status'] == 'pending') {
                    $error = 'Your account is pending approval. Please wait for an administrator to approve your registration.';
                } else {
                    $_SESSION['login_attempts'] = $login_attempts + 1;
                    $attempts_left = LOGIN_ATTEMPTS_LIMIT - ($login_attempts + 1);

                    if ($attempts_left > 0) {
                        $error = 'Invalid email or password.';
                    } else {
                        $error = 'Too many failed attempts. Please try again later.';
                        $_SESSION['lockout_time'] = time();
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

if (isset($_SESSION['lockout_time'])) {
    $lockout_time = $_SESSION['lockout_time'];
    $current_time = time();
    $time_left = LOCKOUT_TIME - ($current_time - $lockout_time);
    
    if ($time_left > 0) {
        $error = 'Account locked. Please try again later.';
    } else {
        unset($_SESSION['lockout_time']);
        $_SESSION['login_attempts'] = 0;
    }
}

if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $success = 'Password has been reset successfully.';
}

if (isset($_GET['registered']) && $_GET['registered'] == 'success') {
    $success = 'Registration successful! Please login with your credentials.';
}

if (isset($_GET['registered']) && $_GET['registered'] == 'pending') {
    $error = 'Your account is pending approval. Please wait for an administrator to approve your registration, or contact support.';
}

if (isset($_GET['logout']) && $_GET['logout'] == 'success') {
    $success = 'You have been logged out successfully.';
}

$pageTitle = "Login";
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

    .login-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    }

    .login-card {
        width: 100%;
        max-width: 440px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 2px 16px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        border: 1px solid #e8ecf1;
    }

    .login-header {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        padding: 28px 32px;
        text-align: center;
        color: #ffffff;
    }

    .login-header .brand-icon {
        width: 52px;
        height: 52px;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
    }

    .login-header .brand-icon i {
        font-size: 26px;
        color: #ffffff;
    }

    .login-header h2 {
        font-size: 1.35rem;
        font-weight: 700;
        margin-bottom: 4px;
        letter-spacing: -0.3px;
    }

    .login-header p {
        font-size: 0.85rem;
        opacity: 0.9;
        margin: 0;
    }

    .login-body {
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

    .form-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
    }

    .checkbox-wrapper {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        font-size: 0.85rem;
        color: #4b5563;
    }

    .checkbox-wrapper input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #4f46e5;
        cursor: pointer;
    }

    .forgot-link {
        font-size: 0.85rem;
        color: #4f46e5;
        text-decoration: none;
        font-weight: 500;
    }

    .forgot-link:hover {
        text-decoration: underline;
        color: #4338ca;
    }

    .btn-login {
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

    .btn-login:hover {
        background: linear-gradient(135deg, #4338ca 0%, #6d28d9 100%);
    }

    .btn-login:active {
        transform: scale(0.985);
    }

    .login-footer {
        padding: 0 32px 24px;
        text-align: center;
    }

    .login-footer .divider {
        border-top: 1px solid #e5e7eb;
        margin-bottom: 16px;
    }

    .footer-links {
        display: flex;
        justify-content: center;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }

    .footer-links a {
        font-size: 0.825rem;
        color: #6b7280;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .footer-links a:hover {
        color: #4f46e5;
    }

    .copyright {
        font-size: 0.775rem;
        color: #9ca3af;
    }

    .roles-badges {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 10px;
    }

    .roles-badges .badge {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 20px;
        letter-spacing: 0.01em;
    }

    .badge-admin {
        background: #fee2e2;
        color: #991b1b;
    }

    .badge-driver {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-client {
        background: #d1fae5;
        color: #065f46;
    }

    @media (max-width: 480px) {
        .login-wrapper {
            padding: 12px;
            align-items: flex-start;
            padding-top: 30px;
        }

        .login-header {
            padding: 22px 20px;
        }

        .login-body {
            padding: 20px;
        }

        .login-footer {
            padding: 0 20px 20px;
        }

        .footer-links {
            gap: 10px;
        }
    }
</style>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Header -->
        <div class="login-header">
            <div class="brand-icon">
                <i class="fas fa-truck"></i>
            </div>
            <h2><?php echo SITE_NAME; ?></h2>
            <p>Multiple Couriers Management System</p>
        </div>

        <!-- Body -->
        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert alert-danger" id="errorAlert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button class="close-alert" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success" id="successAlert">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
                <button class="close-alert" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               value="<?php echo htmlspecialchars($email); ?>"
                               placeholder="your@email.com"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="••••••••"
                               required>
                        <button type="button" class="toggle-password" id="togglePassword" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="checkbox-wrapper">
                        <input type="checkbox" name="remember" id="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="forgot-password.php" class="forgot-link">
                        <i class="fas fa-key"></i> Forgot Password?
                    </a>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="login-footer">
            <div class="divider"></div>
            <div class="footer-links">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="register.php"><i class="fas fa-user-plus"></i> Register</a>
                <a href="mailto:support@<?php echo strtolower(str_replace(' ', '', SITE_NAME)); ?>.com">
                    <i class="fas fa-envelope"></i> Support
                </a>
            </div>
            <div class="roles-badges">
                <span class="badge badge-admin">Admin</span>
                <span class="badge badge-driver">Driver</span>
                <span class="badge badge-client">Client</span>
            </div>
            <p class="copyright">&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?></p>
        </div>
    </div>
</div>

<script>
    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
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

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        var errorAlert = document.getElementById('errorAlert');
        var successAlert = document.getElementById('successAlert');
        if (errorAlert) errorAlert.style.display = 'none';
        if (successAlert) successAlert.style.display = 'none';
    }, 5000);

    // Focus on email field
    document.getElementById('email').focus();
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>