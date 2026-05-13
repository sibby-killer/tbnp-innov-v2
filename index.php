<?php
/**
 * Courier Management System - Professional Landing Page
 */

// Define root path
define('ROOT_PATH', __DIR__);

// Load Composer autoloader
require_once ROOT_PATH . '/vendor/autoload.php';

// Load Bootstrap
require_once ROOT_PATH . '/core/Bootstrap.php';

use Core\Bootstrap;

// Get application instance
try {
    $app = Bootstrap::getInstance();
} catch (Exception $e) {
    error_log("System initialization error: " . $e->getMessage());
    die("System is temporarily unavailable. Please try again later.");
}

// Check if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role_id'])) {
    $role_id = $_SESSION['role_id'];
    switch($role_id) {
        case 1: 
            header('Location: admin/dashboard.php');
            exit;
        case 2: 
            header('Location: driver/dashboard.php');
            exit;
        case 3: 
            header('Location: client/dashboard.php');
            exit;
    }
}

// Get app name from config
$appName = 'Courier Management System';
$appVersion = '1.0.0';
try {
    $config = $app->getConfig();
    $appName = $config['app']['name'] ?? $appName;
    $appVersion = $_ENV['APP_VERSION'] ?? '1.0.0';
} catch (Exception $e) {
    // Use defaults
}

// Get current year for copyright
$currentYear = date('Y');

// Static statistics (you can replace with actual DB queries later)
$stats = [
    'deliveries' => '50,000+',
    'drivers' => '500+',
    'trucks' => '300+',
    'companies' => '25+'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($appName); ?></title>
    
    <!-- Meta tags -->
    <meta name="description" content="Manage multiple courier companies, drivers, trucks and deliveries from one system.">
    <meta name="keywords" content="courier management, fleet tracking, delivery software">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Simple Lightbox -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/simple-lightbox/2.12.1/simple-lightbox.min.css">
    <!-- Custom CSS -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: #1e293b;
            line-height: 1.6;
            background: #ffffff;
        }

        /* Simplified color palette */
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #10b981;
            --dark: #1e293b;
            --light: #f8fafc;
            --gray: #64748b;
            --border: #e2e8f0;
        }

        /* Typography */
        h1, h2, h3, h4 {
            font-weight: 600;
            color: var(--dark);
        }

        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }

        .section-title h2 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .section-title p {
            color: var(--gray);
            max-width: 600px;
            margin: 0 auto;
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Navigation */
        .navbar {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 0;
        }

        .navbar-brand {
            font-weight: 600;
            color: var(--primary);
        }

        .navbar-brand i {
            color: var(--secondary);
            margin-right: 0.5rem;
        }

        .nav-link {
            font-weight: 500;
            color: var(--dark);
            margin: 0 0.5rem;
        }

        .nav-link:hover {
            color: var(--primary);
        }

        /* Hero Section - Simplified */
        .hero {
            padding: 100px 0 60px;
            background: white;
            border-bottom: 1px solid var(--border);
        }

        .hero h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .hero p {
            font-size: 1.1rem;
            color: var(--gray);
            margin-bottom: 2rem;
            max-width: 500px;
        }

        .hero-image img {
            max-width: 100%;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        /* Stats Section */
        .stats {
            padding: 40px 0;
            background: var(--light);
            border-bottom: 1px solid var(--border);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* How It Works */
        .how-it-works {
            padding: 60px 0;
            background: white;
        }

        .step-card {
            text-align: center;
            padding: 1.5rem;
        }

        .step-number {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-weight: 600;
        }

        .step-card h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .step-card p {
            color: var(--gray);
            font-size: 0.95rem;
            margin: 0;
        }

        /* Dashboard Preview */
        .dashboard-preview {
            padding: 60px 0;
            background: var(--light);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .preview-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 30px;
        }

        .preview-item {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.2s;
        }

        .preview-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .preview-item img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-bottom: 1px solid var(--border);
        }

        .preview-item .preview-info {
            padding: 1rem;
        }

        .preview-info h4 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .preview-info p {
            color: var(--gray);
            font-size: 0.85rem;
            margin: 0;
        }

        /* System Roles */
        .system-roles {
            padding: 60px 0;
            background: white;
        }

        .role-card {
            background: var(--light);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            height: 100%;
        }

        .role-icon {
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            color: white;
            font-size: 1.2rem;
        }

        .role-card h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .role-list {
            list-style: none;
            padding: 0;
            margin: 1rem 0 0;
        }

        .role-list li {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            padding-left: 1.2rem;
            position: relative;
        }

        .role-list li:before {
            content: "•";
            color: var(--primary);
            position: absolute;
            left: 0;
        }

        /* Features */
        .features {
            padding: 60px 0;
            background: white;
            border-top: 1px solid var(--border);
        }

        .feature-item {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: var(--light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }

        .feature-text h4 {
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .feature-text p {
            color: var(--gray);
            font-size: 0.9rem;
            margin: 0;
        }

        /* Gallery */
        .gallery {
            padding: 60px 0;
            background: var(--light);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 30px;
        }

        .gallery-item {
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .gallery-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            transition: transform 0.2s;
            cursor: pointer;
        }

        .gallery-item img:hover {
            transform: scale(1.05);
        }

        /* CTA Section */
        .cta-simple {
            padding: 60px 0;
            background: white;
            text-align: center;
        }

        .cta-simple h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .cta-simple p {
            color: var(--gray);
            margin-bottom: 1.5rem;
        }

        .cta-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        /* Footer */
        .footer {
            background: var(--dark);
            color: white;
            padding: 40px 0 20px;
            border-top: 1px solid var(--border);
        }

        .footer-brand {
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .footer-brand i {
            color: var(--secondary);
            margin-right: 0.5rem;
        }

        .footer-text {
            color: #94a3b8;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .footer-links h5 {
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .footer-links ul {
            list-style: none;
            padding: 0;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: white;
        }

        .copyright {
            text-align: center;
            padding-top: 30px;
            margin-top: 30px;
            border-top: 1px solid #334155;
            color: #94a3b8;
            font-size: 0.85rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2rem;
            }
            
            .preview-grid {
                grid-template-columns: 1fr;
            }
            
            .gallery-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .cta-buttons .btn {
                width: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-truck-moving"></i>
                <?php echo htmlspecialchars($appName); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#how-it-works">How it Works</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#roles">Roles</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a class="btn btn-outline" href="auth/login.php">Login</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a class="btn btn-primary" href="auth/register.php">Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1>Multiple Couriers Management System</h1>
                    <p>Manage deliveries, drivers, trucks and multiple courier companies from one centralized system.</p>
                    <div>
                        <a href="auth/register.php" class="btn btn-primary me-2">Get Started</a>
                        <a href="#how-it-works" class="btn btn-outline">Learn More</a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <img src="https://images.unsplash.com/photo-1566576912323-6e8c9c6d8f9b?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" 
                         alt="Dashboard Preview" class="img-fluid hero-image">
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats">
        <div class="container">
            <div class="row">
                <div class="col-md-3 col-6 mb-3 mb-md-0">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stats['deliveries']; ?></div>
                        <div class="stat-label">Deliveries Managed</div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3 mb-md-0">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stats['drivers']; ?></div>
                        <div class="stat-label">Drivers Registered</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stats['trucks']; ?></div>
                        <div class="stat-label">Trucks in Fleet</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $stats['companies']; ?></div>
                        <div class="stat-label">Courier Companies</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="how-it-works" class="how-it-works">
        <div class="container">
            <div class="section-title">
                <h2>How It Works</h2>
                <p>Get started in four simple steps</p>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h3>Register Account</h3>
                        <p>Create your account as admin, driver or client</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h3>Add Companies</h3>
                        <p>Register multiple courier companies</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h3>Assign Resources</h3>
                        <p>Add trucks and assign drivers</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="step-card">
                        <div class="step-number">4</div>
                        <h3>Start Tracking</h3>
                        <p>Manage deliveries and track orders</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Dashboard Preview Section -->
    <section class="dashboard-preview">
        <div class="container">
            <div class="section-title">
                <h2>System Dashboards</h2>
                <p>Real screenshots of our management interface</p>
            </div>
            <div class="preview-grid">
                <div class="preview-item">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" 
                         alt="Admin Dashboard">
                    <div class="preview-info">
                        <h4>Admin Dashboard</h4>
                        <p>Manage companies, users and system settings</p>
                    </div>
                </div>
                <div class="preview-item">
                    <img src="https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" 
                         alt="Driver Dashboard">
                    <div class="preview-info">
                        <h4>Driver Dashboard</h4>
                        <p>View deliveries and update status</p>
                    </div>
                </div>
                <div class="preview-item">
                    <img src="https://images.unsplash.com/photo-1563013544-824ae1b704d3?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" 
                         alt="Order Tracking">
                    <div class="preview-info">
                        <h4>Order Tracking</h4>
                        <p>Track deliveries in real-time</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- System Roles Section -->
    <section id="roles" class="system-roles">
        <div class="container">
            <div class="section-title">
                <h2>System Roles</h2>
                <p>Three user types with specific permissions</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="role-card">
                        <div class="role-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3>Administrator</h3>
                        <ul class="role-list">
                            <li>Manage courier companies</li>
                            <li>Add and manage trucks</li>
                            <li>Manage driver accounts</li>
                            <li>View system analytics</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="role-card">
                        <div class="role-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <h3>Driver</h3>
                        <ul class="role-list">
                            <li>View assigned deliveries</li>
                            <li>Update delivery status</li>
                            <li>Track delivery routes</li>
                            <li>Manage delivery confirmations</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="role-card">
                        <div class="role-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <h3>Client</h3>
                        <ul class="role-list">
                            <li>Create delivery orders</li>
                            <li>Track order progress</li>
                            <li>View delivery history</li>
                            <li>Receive status updates</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="container">
            <div class="section-title">
                <h2>Key Features</h2>
                <p>Everything you need for efficient courier management</p>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Multiple Couriers</h4>
                            <p>Manage several courier companies from one account</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Truck Management</h4>
                            <p>Track fleet status and maintenance schedules</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Driver Management</h4>
                            <p>Assign and track driver performance</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Order Tracking</h4>
                            <p>Real-time delivery status updates</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Reports</h4>
                            <p>Delivery analytics and performance reports</p>
                        </div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="feature-text">
                            <h4>Role-based Access</h4>
                            <p>Secure permissions for each user type</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Gallery Section -->
    <section class="gallery">
        <div class="container">
            <div class="section-title">
                <h2>Operations Gallery</h2>
                <p>Real-world logistics in action</p>
            </div>
            <div class="gallery-grid">
                <div class="gallery-item">
                    <a href="https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" class="gallery-link">
                        <img src="https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Delivery Truck">
                    </a>
                </div>
                <div class="gallery-item">
                    <a href="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" class="gallery-link">
                        <img src="https://images.unsplash.com/photo-1542744173-8e7e53415bb0?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Warehouse">
                    </a>
                </div>
                <div class="gallery-item">
                    <a href="https://images.unsplash.com/photo-1566576912323-6e8c9c6d8f9b?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" class="gallery-link">
                        <img src="https://images.unsplash.com/photo-1566576912323-6e8c9c6d8f9b?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Delivery Person">
                    </a>
                </div>
                <div class="gallery-item">
                    <a href="https://images.unsplash.com/photo-1601584115197-04ecc0da31d7?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" class="gallery-link">
                        <img src="https://images.unsplash.com/photo-1601584115197-04ecc0da31d7?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80" alt="Logistics">
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Simple CTA Section -->
    <section class="cta-simple">
        <div class="container">
            <h2>Start managing your courier operations today</h2>
            <p>Join companies using our system to streamline deliveries</p>
            <div class="cta-buttons">
                <a href="auth/register.php" class="btn btn-primary">Register Now</a>
                <a href="auth/login.php" class="btn btn-outline">Login</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <a href="#" class="footer-brand">
                        <i class="fas fa-truck-moving"></i>
                        <?php echo htmlspecialchars($appName); ?>
                    </a>
                    <p class="footer-text">
                        A complete solution for managing multiple courier companies, 
                        trucks, drivers and deliveries.
                    </p>
                </div>
                <div class="col-lg-2 col-md-6 mb-4">
                    <div class="footer-links">
                        <h5>Quick Links</h5>
                        <ul>
                            <li><a href="#how-it-works">How it Works</a></li>
                            <li><a href="#features">Features</a></li>
                            <li><a href="#roles">Roles</a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="footer-links">
                        <h5>Access</h5>
                        <ul>
                            <li><a href="auth/login.php">Admin Login</a></li>
                            <li><a href="auth/login.php">Driver Login</a></li>
                            <li><a href="auth/login.php">Client Login</a></li>
                            <li><a href="auth/register.php">Create Account</a></li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="footer-links">
                        <h5>Contact</h5>
                        <ul>
                            <li>support@courier-system.com</li>
                            <li>+254 700 000 000</li>
                            <li>Nairobi, Kenya</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="copyright">
                <p>&copy; <?php echo $currentYear; ?> <?php echo htmlspecialchars($appName); ?>. Version <?php echo htmlspecialchars($appVersion); ?></p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/simple-lightbox/2.12.1/simple-lightbox.min.js"></script>
    <script>
        // Initialize gallery lightbox
        const gallery = new SimpleLightbox('.gallery-grid a');
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>