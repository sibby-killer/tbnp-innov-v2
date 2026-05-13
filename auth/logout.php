<?php
// logout.php - Secure logout script for Courier System

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Include configuration
require_once dirname(__DIR__) . '/config/constants.php';

// Store user info for logging before destroying session
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['user_name'] ?? 'Unknown';
$user_role = $_SESSION['role_id'] ?? null;
$role_name = 'Unknown';

switch ($user_role) {
    case ROLE_ADMIN:
        $role_name = 'Admin';
        break;
    case ROLE_DRIVER:
        $role_name = 'Driver';
        break;
    case ROLE_CLIENT:
        $role_name = 'Client';
        break;
}

// Log the logout activity (you can implement a logging system)
if (isset($db) && $user_id) {
    try {
        $log_stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
            VALUES (?, ?, 'logout', ?, ?, ?)
        ");
        $log_stmt->execute([
            $user_id,
            $role_name,
            "User '{$user_name}' logged out",
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (Exception $e) {
        // Log to error log if database logging fails
        error_log("Logout logging failed: " . $e->getMessage());
    }
}

// Store redirect URL based on role
$redirect_url = '../index.php'; // Default redirect

// Clear all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Finally, destroy the session
session_destroy();

// Clear any additional cookies if needed
setcookie('remember_token', '', time() - 3600, '/');

// Regenerate session ID to prevent session fixation
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_regenerate_id(true);

// Store logout message in new session
$_SESSION['logout_message'] = "You have been successfully logged out.";
$_SESSION['logout_type'] = "success";

// Redirect to appropriate page with delay for message display
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - Courier System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --dark-color: #1f2937;
            --light-color: #f9fafb;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .logout-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 90%;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .logout-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            color: white;
            font-size: 40px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7);
            }
            70% {
                box-shadow: 0 0 0 20px rgba(59, 130, 246, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
            }
        }
        
        h2 {
            color: var(--dark-color);
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .lead {
            color: #6b7280;
            font-size: 1.1rem;
            margin-bottom: 30px;
        }
        
        .user-info {
            background: #f3f4f6;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 30px;
        }
        
        .user-info p {
            margin: 5px 0;
            color: #4b5563;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #e5e7eb;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .countdown {
            font-size: 1.2rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .logout-message {
            background: #d1fae5;
            color: #065f46;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid var(--secondary-color);
        }
        
        .security-info {
            font-size: 0.9rem;
            color: #9ca3af;
            margin-top: 20px;
        }
        
        .security-info i {
            color: var(--secondary-color);
            margin-right: 5px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary-color), #1d4ed8);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
            color: white;
        }
        
        .progress-bar {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .progress {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            width: 100%;
            animation: progress 5s linear;
        }
        
        @keyframes progress {
            from { width: 0%; }
            to { width: 100%; }
        }
        
        /* Responsive design */
        @media (max-width: 576px) {
            .logout-container {
                padding: 30px 20px;
            }
            
            .logout-icon {
                width: 80px;
                height: 80px;
                font-size: 30px;
            }
            
            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <!-- Animated Icon -->
        <div class="logout-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        
        <!-- Title -->
        <h2>Logging Out</h2>
        <p class="lead">Please wait while we securely sign you out...</p>
        
        <!-- User Info -->
        <?php if ($user_name !== 'Unknown'): ?>
        <div class="user-info">
            <p><i class="fas fa-user me-2"></i> <strong>User:</strong> <?php echo htmlspecialchars($user_name); ?></p>
            <p><i class="fas fa-user-tag me-2"></i> <strong>Role:</strong> <?php echo htmlspecialchars($role_name); ?></p>
            <p><i class="fas fa-clock me-2"></i> <strong>Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Logout Message -->
        <div class="logout-message">
            <i class="fas fa-check-circle me-2"></i>
            You have been successfully logged out. All session data has been cleared.
        </div>
        
        <!-- Progress Bar -->
        <div class="progress-bar">
            <div class="progress"></div>
        </div>
        
        <!-- Countdown Spinner -->
        <div class="spinner"></div>
        
        <!-- Countdown Timer -->
        <div class="countdown" id="countdown">Redirecting in 5 seconds...</div>
        
        <!-- Security Info -->
        <div class="security-info">
            <p><i class="fas fa-shield-alt"></i> All session data has been securely cleared.</p>
            <p><i class="fas fa-cookie"></i> Session cookies have been deleted.</p>
            <p><i class="fas fa-redo"></i> Session ID has been regenerated.</p>
        </div>
        
        <!-- Manual Login Button -->
        <a href="../index.php" class="btn-login">
            <i class="fas fa-home me-1"></i> Return to Home
        </a>
        <br>
        <a href="login.php" class="btn-login mt-2">
            <i class="fas fa-sign-in-alt me-1"></i> Login Again
        </a>
    </div>

    <script>
        // Countdown timer
        let countdown = 5;
        const countdownElement = document.getElementById('countdown');
        
        const timer = setInterval(function() {
            countdown--;
            countdownElement.textContent = `Redirecting in ${countdown} second${countdown !== 1 ? 's' : ''}...`;
            
            if (countdown <= 0) {
                clearInterval(timer);
                // Redirect to home page
                window.location.href = '../index.php';
            }
        }, 1000);
        
        // Option to cancel redirect and stay on logout page
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                clearInterval(timer);
                countdownElement.textContent = 'Redirect cancelled. Click the buttons above.';
                countdownElement.style.color = '#ef4444';
            }
        });
        
        // Add click handler to stop redirect
        document.querySelector('.logout-container').addEventListener('click', function(e) {
            if (e.target.tagName === 'A') {
                clearInterval(timer);
                countdownElement.textContent = 'Redirect cancelled.';
                countdownElement.style.color = '#ef4444';
            }
        });
        
        // Auto redirect after 5 seconds (fallback)
        setTimeout(function() {
            window.location.href = '../index.php';
        }, 5000);
        
        // Force clear any remaining localStorage/sessionStorage for this app
        window.addEventListener('load', function() {
            // Clear any app-specific storage
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key.startsWith('courier_') || key.startsWith('driver_') || key.startsWith('admin_')) {
                    keysToRemove.push(key);
                }
            }
            
            keysToRemove.forEach(key => {
                localStorage.removeItem(key);
            });
            
            // Clear sessionStorage
            sessionStorage.clear();
            
            // Clear any service worker cache if used
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(function(registrations) {
                    for(let registration of registrations) {
                        registration.unregister();
                    }
                });
            }
        });
    </script>
</body>
</html>