<?php

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__FILE__)));
}

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/constants.php';

// Redirect if already logged in - BEFORE any output
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$name = $email = $phone = $password = $confirm_password = $role = '';
$errors = [];
$success = '';

$isFirstUser = false;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as user_count FROM users");
    $stmt->execute();
    $result = $stmt->fetch();
    $isFirstUser = ($result['user_count'] == 0);
} catch (Exception $e) {
    $errors[] = 'System error. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? ($isFirstUser ? ROLE_ADMIN : ROLE_CLIENT);
    
    if (empty($name)) $errors[] = 'Name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (empty($phone)) $errors[] = 'Phone number is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
    
    if (!$isFirstUser && !in_array($role, [ROLE_CLIENT, ROLE_DRIVER])) {
        $errors[] = 'Invalid role selected.';
    }
    
    if (empty($errors)) {
        try {
            $checkEmail = $db->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->execute([$email]);
            if ($checkEmail->fetch()) {
                $errors[] = 'Email already exists.';
            }
        } catch (Exception $e) {
            $errors[] = 'System error. Please try again.';
        }
    }
    
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            if ($isFirstUser) {
                $role = ROLE_ADMIN;
                $status = 'active';
            } else {
                // Drivers are pending until admin approves
                // Customers are active immediately
                $status = ($role == ROLE_DRIVER) ? 'pending' : 'active';
            }
            
            $stmt = $db->prepare("
                INSERT INTO users (role_id, name, email, phone, password, status) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $role,
                $name,
                $email,
                $phone,
                $hashed_password,
                $status
            ]);
            
            $user_id = $db->lastInsertId();
            
            if ($role == ROLE_DRIVER) {
                $driver_stmt = $db->prepare("
                    INSERT INTO drivers (user_id, license_number, status) 
                    VALUES (?, 'PENDING', 'inactive')
                ");
                $driver_stmt->execute([$user_id]);
            }
            
            if ($role == ROLE_CLIENT) {
                $client_stmt = $db->prepare("
                    INSERT INTO clients (user_id, company_name) 
                    VALUES (?, ?)
                ");
                $client_stmt->execute([$user_id, $name]);
            }
            
            // Check if driver registration
            if ($role == ROLE_DRIVER) {
                header('Location: login.php?registered=pending');
                exit();
            }

            // Redirect immediately - no output before this
            header('Location: login.php?registered=success');
            exit();
            
        } catch (Exception $e) {
            $errors[] = 'Registration failed. Please try again.';
            error_log("Registration error: " . $e->getMessage());
        }
    }
}

// Set page title and include header AFTER all processing
$pageTitle = "Register";
require_once ROOT_PATH . '/includes/header.php';
?>

<style>
    .register-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        padding: 60px 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .register-container {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        overflow: hidden;
    }
    
    .register-left {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
        padding: 50px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    
    .register-right {
        padding: 50px;
    }
    
    .system-name {
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 10px;
    }
    
    .password-strength {
        height: 5px;
        margin-top: 5px;
        border-radius: 3px;
        transition: all 0.3s;
    }
    
    .strength-0 { width: 0%; background-color: #dc3545; }
    .strength-1 { width: 25%; background-color: #dc3545; }
    .strength-2 { width: 50%; background-color: #ffc107; }
    .strength-3 { width: 75%; background-color: #17a2b8; }
    .strength-4 { width: 100%; background-color: #28a745; }
    
    .progress-text {
        font-size: 0.8rem;
        margin-top: 5px;
    }
    
    @media (max-width: 768px) {
        .register-left {
            padding: 30px;
        }
        .register-right {
            padding: 30px;
        }
        .register-page {
            padding: 20px;
        }
    }
</style>

<div class="register-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="register-container">
                    <div class="row g-0">
                        <div class="col-lg-6">
                            <div class="register-left">
                                <div class="mb-4">
                                    <div class="system-name">
                                        <i class="fas fa-truck-moving"></i> <?php echo SITE_NAME; ?>
                                    </div>
                                    <div class="system-tagline">
                                        Create Your Account
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <span class="badge bg-danger me-2">
                                        <i class="fas fa-user-shield"></i> Admin
                                    </span>
                                    <span class="badge bg-warning text-dark me-2">
                                        <i class="fas fa-truck"></i> Driver
                                    </span>
                                    <span class="badge bg-success">
                                        <i class="fas fa-user-tie"></i> Client
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-6">
                            <div class="register-right">
                                <h3 class="mb-4">Create Account</h3>
                                
                                <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($success): ?>
                                <div class="alert alert-success">
                                    <?php echo htmlspecialchars($success); ?>
                                </div>
                                <?php endif; ?>
                                
                                <form method="POST" action="" id="registerForm">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Full Name</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-user"></i>
                                            </span>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="name" 
                                                   name="name" 
                                                   value="<?php echo htmlspecialchars($name); ?>"
                                                   required
                                                   placeholder="Enter your full name">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-envelope"></i>
                                            </span>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="email" 
                                                   name="email" 
                                                   value="<?php echo htmlspecialchars($email); ?>"
                                                   required
                                                   placeholder="Enter your email address">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-phone"></i>
                                            </span>
                                            <input type="tel" 
                                                   class="form-control" 
                                                   id="phone" 
                                                   name="phone" 
                                                   value="<?php echo htmlspecialchars($phone); ?>"
                                                   required
                                                   placeholder="Enter your phone number">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="password" 
                                                   name="password" 
                                                   required
                                                   placeholder="Enter password (min. 8 characters)"
                                                   minlength="8"
                                                   onkeyup="checkPasswordStrength(this.value)">
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength" id="passwordStrength"></div>
                                        <div class="progress-text" id="passwordStrengthText"></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                            <input type="password" 
                                                   class="form-control" 
                                                   id="confirm_password" 
                                                   name="confirm_password" 
                                                   required
                                                   placeholder="Confirm your password">
                                            <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="mt-1" id="passwordMatch"></div>
                                    </div>
                                    
                                    <?php if (!$isFirstUser): ?>
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Account Type</label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-user-tag"></i>
                                            </span>
                                            <select class="form-select" id="role" name="role" required>
                                                <option value="<?php echo ROLE_CLIENT; ?>" <?php echo $role == ROLE_CLIENT ? 'selected' : ''; ?>>
                                                    Client - Create and track orders
                                                </option>
                                                <option value="<?php echo ROLE_DRIVER; ?>" <?php echo $role == ROLE_DRIVER ? 'selected' : ''; ?>>
                                                    Driver - Deliver packages
                                                </option>
                                            </select>
                                        </div>
                                        <div class="form-text">
                                            Select the type of account you need
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-grid gap-2 mb-3">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-user-plus me-2"></i> 
                                            <?php echo $isFirstUser ? 'Create Admin Account' : 'Create Account'; ?>
                                        </button>
                                    </div>
                                    
                                    <div class="text-center mt-3">
                                        <a href="login.php" class="text-decoration-none">
                                            <i class="fas fa-sign-in-alt me-1"></i> Already have an account? Login
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
    
    document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
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
        
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
        
        strengthBar.className = 'password-strength strength-' + strength;
        
        switch(strength) {
            case 0:
                strengthText.textContent = '';
                break;
            case 1:
                strengthText.textContent = 'Very Weak';
                strengthText.style.color = '#dc3545';
                break;
            case 2:
                strengthText.textContent = 'Weak';
                strengthText.style.color = '#ffc107';
                break;
            case 3:
                strengthText.textContent = 'Medium';
                strengthText.style.color = '#17a2b8';
                break;
            case 4:
                strengthText.textContent = 'Strong';
                strengthText.style.color = '#28a745';
                break;
            case 5:
                strengthText.textContent = 'Very Strong';
                strengthText.style.color = '#28a745';
                break;
        }
    }
    
    function checkPasswordMatch() {
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const matchDiv = document.getElementById('passwordMatch');
        
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
    
    document.getElementById('password').addEventListener('keyup', checkPasswordMatch);
    document.getElementById('confirm_password').addEventListener('keyup', checkPasswordMatch);
    
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    document.getElementById('name').focus();
</script>

<?php
require_once ROOT_PATH . '/includes/footer.php';
?>