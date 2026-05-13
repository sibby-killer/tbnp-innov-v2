MULTIPLE COURIER TRUCK MANAGEMENT SYSTEM
text

████████████████████████████████████████████████████████████
█                                                          █
█     BUNGOMA NATIONAL POLYTECHNIC — 2026 INNOVATION       █
█     MULTIPLE COURIER TRUCK MANAGEMENT SYSTEM            █
█     Version: 1.0.0  |  Status: LIVE & TESTED ✅          █
█     Last Updated: 13th May 2026                         █
█                                                          █
████████████████████████████████████████████████████████████
🏫 INSTITUTION DETAILS
Institution	The Bungoma National Polytechnic
P.O. Box	158, Bungoma
Tel	254119468162
Email	info@bungomapoly.ac.ke
Website	www.bungomapoly.ac.ke
Supervisor	Enricah Wafula
Innovation Year	2026
🎯 INNOVATION DETAILS
Theme	Transforming Tomorrow: TVET Powering Bottom-Up Transformation Through Skilling, Innovation, Robotics & Research
Sub-Theme	The Role of Science, Technology and Innovation (STI) in Economic Development
Project Title	Multiple Courier Truck Management System
Category	Software / Web Application
Project Start Date	29th April 2026
Testing Date	12th May 2026 ✅
Presentation Date	13th May 2026
📌 TABLE OF CONTENTS
text

1.  Project Overview
2.  Kenyan Context & Justification
3.  Abstract
4.  Problem Statement
5.  Objectives
6.  System Architecture
7.  Tech Stack & Tools Used
8.  Database Design
9.  System Modules & Features
10. Development Timeline (What We Did)
11. Deployment — InfinityFree Hosting
12. Testing & Results
13. Security Implementation
14. Payment Integration (M-Pesa Sandbox)
15. Budget
16. Future Enhancements
17. How to Run the Project
18. Team & Roles
19. References
1. 🌍 PROJECT OVERVIEW
The Multiple Courier Truck Management System is a fully functional, web-based application we developed to solve the real-world challenges facing courier and logistics companies in Kenya — particularly in the Western Kenya region (Bungoma, Eldoret, Kisumu corridor).

We built this system from the ground up over 2 weeks, starting on 29th April 2026, and completed live testing on 12th May 2026. The system is currently deployed and accessible live on InfinityFree hosting with a working domain.

The system enables:

🚛 Real-time visual fleet management
📦 Delivery creation, assignment, and tracking
👷 Driver management and accountability
📊 Reports, analytics and performance monitoring
💳 Payment simulation via M-Pesa Sandbox
🚨 Emergency/SOS reporting
🌐 Public customer parcel tracking portal
2. 🌍 KENYAN CONTEXT & JUSTIFICATION
Kenya's logistics industry continues to grow rapidly. With major corridors like Bungoma → Eldoret → Nairobi, Kisumu → Nakuru, and Mombasa SGR freight routes handling thousands of deliveries daily, the need for digital management tools has never been greater.

Most courier companies in Western Kenya — especially small and medium enterprises (SMEs) — still rely on:

📞 Phone calls to track drivers
📝 Paper waybills and manual logs
💬 WhatsApp messages for dispatching
❌ No real-time visibility whatsoever
This is the exact gap our system bridges. We designed it specifically for the Kenyan operator, with:

Swahili-friendly terminology in the UI
M-Pesa payment flow (the dominant payment method in Kenya)
Africa's Talking SMS structure for local number formats (+254)
InfinityFree hosting (zero-cost deployment accessible across Kenya)
Optimized for 3G/4G mobile networks common in Bungoma County
3. 📄 ABSTRACT
We developed the Multiple Courier Truck Management System as a web-based application to automate and simplify the daily operations of courier companies — covering vehicle tracking, driver management, delivery scheduling, customer notifications, and road emergency response.

We started development on 29th April 2026 using VS Code as our primary IDE. We used HTML, CSS, JavaScript, and Bootstrap 5 for the frontend; PHP 8 for backend logic; and MySQL for database management — all hosted live on InfinityFree, a free hosting provider that supports PHP and MySQL.

We tested the complete system yesterday, 12th May 2026, and confirmed all core modules are functional and accessible via our live URL. The system includes a simulated M-Pesa STK Push flow using the Safaricom Daraja Sandbox, allowing demo payment testing during the presentation.

4. 🛑 PROBLEM STATEMENT
We identified the following key challenges that prompted us to build this system:

4.1 Identified Problems
#	Problem	Impact
1	No real-time truck visibility	Dispatchers cannot monitor fleet
2	Manual delivery scheduling	Errors, delays, overbooking
3	Poor driver accountability	Low performance, ghost trips
4	No customer tracking portal	Customer dissatisfaction
5	No emergency response system	Driver safety risks on roads
6	Fuel & resource waste	High operational costs
7	No centralized data records	Poor decision making by management
4.2 Our Solution
We built a centralized digital platform that addresses every one of these problems through a role-based, real-time, interactive web application — accessible from any device with a browser.

5. ✅ OBJECTIVES
5.1 General Objective
To design, develop, and deploy a web-based Multiple Courier Truck Management System that automates coordination, tracking, and monitoring of courier trucks — built specifically for the Kenyan logistics environment.

5.2 Specific Objectives
We set out to:

✅ Build a live fleet tracking dashboard using Leaflet.js + OpenStreetMap
✅ Implement an automated delivery scheduling and dispatching module
✅ Develop a route planning and visualization interface
✅ Build a driver performance monitoring and reporting module
✅ Create a customer-facing parcel tracking portal
✅ Integrate M-Pesa Daraja Sandbox for payment simulation
✅ Deploy the entire system live on InfinityFree hosting
✅ Implement role-based access control (Admin, Dispatcher, Driver, Customer)
6. 🏗️ SYSTEM ARCHITECTURE
text

┌─────────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                       │
│    HTML5 | CSS3 | Bootstrap 5 | JavaScript | jQuery | AJAX      │
│    Chart.js (Analytics) | Leaflet.js (Maps) | Font Awesome       │
└───────────────────────────┬─────────────────────────────────────┘
                            │ HTTP Requests
┌───────────────────────────▼─────────────────────────────────────┐
│                         APPLICATION LAYER                        │
│              PHP 8 — REST API + Server-Side Logic               │
│    Auth | Trucks | Drivers | Deliveries | Reports | Payments     │
└───────────────────────────┬─────────────────────────────────────┘
                            │ SQL Queries
┌───────────────────────────▼─────────────────────────────────────┐
│                           DATA LAYER                             │
│              MySQL Database (InfinityFree / phpMyAdmin)          │
│    users | trucks | drivers | deliveries | tracking_logs        │
│    fuel_records | emergencies | customers | payments            │
└─────────────────────────────────────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────────┐
│                     HOSTING LAYER (LIVE)                         │
│         InfinityFree — Free PHP + MySQL Hosting                  │
│         Deployed via FileZilla FTP | SSL via InfinityFree        │
│         Domain: [yourproject].infinityfreeapp.com                │
└─────────────────────────────────────────────────────────────────┘
7. 🛠️ TECH STACK & TOOLS USED
Frontend
Tool	Version	Purpose
HTML5	5	Page structure
CSS3	3	Styling
Bootstrap	5.3	Responsive UI components
JavaScript (ES6)	ES6+	Interactivity & AJAX
jQuery	3.6	DOM manipulation
Chart.js	4.x	Analytics charts
Leaflet.js	1.9	Interactive truck tracking map
Font Awesome	6.x	Icons
Google Fonts	—	Typography
Backend
Tool	Version	Purpose
PHP	8.x	Server-side logic
MySQL	5.7+	Production database
SQLite3	3.x	Local development DB
Apache	2.4	Web server (XAMPP local)
Development Tools
Tool	Purpose
VS Code	Primary IDE
XAMPP	Local development server
phpMyAdmin	Database management
FileZilla	FTP deployment to InfinityFree
Git & GitHub	Version control
Postman	API testing
Chrome DevTools	Frontend debugging
Hosting & Deployment
Service	Cost	Purpose
InfinityFree	FREE	PHP + MySQL live hosting
InfinityFree Domain	FREE	.infinityfreeapp.com subdomain
SSL (InfinityFree)	FREE	HTTPS security
FileZilla FTP	FREE	File transfer to server
APIs & Integrations
API	Provider	Cost	Purpose
M-Pesa Daraja	Safaricom	FREE (Sandbox)	Payment simulation
OpenStreetMap	OSM	FREE	Map tiles
Leaflet.js	Leaflet	FREE	Map rendering
PHPMailer	Open Source	FREE	Email notifications
💡 Total External Tool Cost: KES 0 — We used 100% free tools for this project.

8. 🗄️ DATABASE DESIGN
Database Name: courier_system Engine: MySQL (InnoDB) Host: InfinityFree MySQL Server

8.1 Complete Database Schema
SQL

-- ============================================================
-- COURIER TRUCK MANAGEMENT SYSTEM — DATABASE SCHEMA
-- Bungoma National Polytechnic 2026
-- Created: 29 April 2026 | Last Modified: 12 May 2026
-- ============================================================

CREATE DATABASE IF NOT EXISTS courier_system;
USE courier_system;

-- USERS TABLE (All system users)
CREATE TABLE users (
    user_id     INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(100) NOT NULL,
    email       VARCHAR(100) UNIQUE NOT NULL,
    phone       VARCHAR(15) NOT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','manager','dispatcher','driver','customer') DEFAULT 'customer',
    status      ENUM('active','inactive','suspended') DEFAULT 'active',
    profile_pic VARCHAR(255) DEFAULT 'default.png',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login  TIMESTAMP NULL
);

-- TRUCKS TABLE
CREATE TABLE trucks (
    truck_id    INT AUTO_INCREMENT PRIMARY KEY,
    plate_no    VARCHAR(20) UNIQUE NOT NULL,
    model       VARCHAR(100) NOT NULL,
    brand       VARCHAR(50),
    capacity_kg DECIMAL(10,2),
    fuel_type   ENUM('petrol','diesel','electric') DEFAULT 'diesel',
    year_model  YEAR,
    status      ENUM('available','on_trip','maintenance','inactive') DEFAULT 'available',
    driver_id   INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- DRIVERS TABLE
CREATE TABLE drivers (
    driver_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    license_no      VARCHAR(50) UNIQUE NOT NULL,
    license_expiry  DATE,
    id_number       VARCHAR(20),
    address         TEXT,
    emergency_contact VARCHAR(15),
    total_trips     INT DEFAULT 0,
    rating          DECIMAL(3,2) DEFAULT 5.00,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- CUSTOMERS TABLE
CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL UNIQUE,
    company     VARCHAR(100),
    address     TEXT,
    county      VARCHAR(50) DEFAULT 'Bungoma',
    total_orders INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- DELIVERIES TABLE
CREATE TABLE deliveries (
    delivery_id         INT AUTO_INCREMENT PRIMARY KEY,
    tracking_code       VARCHAR(20) UNIQUE NOT NULL,
    customer_id         INT NOT NULL,
    truck_id            INT NULL,
    driver_id           INT NULL,
    dispatcher_id       INT NULL,
    pickup_location     VARCHAR(255) NOT NULL,
    dropoff_location    VARCHAR(255) NOT NULL,
    pickup_lat          DECIMAL(10,8) NULL,
    pickup_lng          DECIMAL(11,8) NULL,
    dropoff_lat         DECIMAL(10,8) NULL,
    dropoff_lng         DECIMAL(11,8) NULL,
    cargo_description   TEXT,
    weight_kg           DECIMAL(10,2),
    amount_kes          DECIMAL(10,2) DEFAULT 0.00,
    payment_status      ENUM('pending','paid','failed') DEFAULT 'pending',
    payment_method      ENUM('mpesa','cash','credit') DEFAULT 'mpesa',
    status              ENUM('pending','assigned','picked_up','in_transit',
                             'delivered','cancelled','failed') DEFAULT 'pending',
    priority            ENUM('normal','urgent','express') DEFAULT 'normal',
    scheduled_date      DATE,
    delivered_at        TIMESTAMP NULL,
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (truck_id) REFERENCES trucks(truck_id) ON DELETE SET NULL,
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE SET NULL
);

-- TRACKING LOGS (GPS History)
CREATE TABLE tracking_logs (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    truck_id    INT NOT NULL,
    delivery_id INT NULL,
    latitude    DECIMAL(10,8) NOT NULL,
    longitude   DECIMAL(11,8) NOT NULL,
    speed_kmh   DECIMAL(5,2) DEFAULT 0,
    location_name VARCHAR(255),
    logged_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (truck_id) REFERENCES trucks(truck_id) ON DELETE CASCADE
);

-- FUEL RECORDS
CREATE TABLE fuel_records (
    fuel_id         INT AUTO_INCREMENT PRIMARY KEY,
    truck_id        INT NOT NULL,
    driver_id       INT NOT NULL,
    liters_filled   DECIMAL(8,2) NOT NULL,
    cost_per_liter  DECIMAL(6,2),
    total_cost_kes  DECIMAL(10,2),
    odometer_km     INT,
    station_name    VARCHAR(100),
    county          VARCHAR(50),
    filled_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (truck_id) REFERENCES trucks(truck_id),
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id)
);

-- EMERGENCIES / SOS
CREATE TABLE emergencies (
    emergency_id    INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT NOT NULL,
    truck_id        INT NOT NULL,
    delivery_id     INT NULL,
    type            ENUM('accident','breakdown','flat_tyre',
                         'theft','medical','other') NOT NULL,
    description     TEXT,
    latitude        DECIMAL(10,8),
    longitude       DECIMAL(11,8),
    location_name   VARCHAR(255),
    status          ENUM('reported','responding','resolved') DEFAULT 'reported',
    resolved_at     TIMESTAMP NULL,
    reported_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id),
    FOREIGN KEY (truck_id) REFERENCES trucks(truck_id)
);

-- PAYMENTS (M-Pesa Sandbox Logs)
CREATE TABLE payments (
    payment_id          INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id         INT NOT NULL,
    customer_phone      VARCHAR(15) NOT NULL,
    amount_kes          DECIMAL(10,2) NOT NULL,
    mpesa_ref           VARCHAR(50),
    checkout_request_id VARCHAR(100),
    status              ENUM('pending','completed','failed','cancelled') DEFAULT 'pending',
    initiated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at        TIMESTAMP NULL,
    FOREIGN KEY (delivery_id) REFERENCES deliveries(delivery_id)
);

-- NOTIFICATIONS
CREATE TABLE notifications (
    notif_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    title       VARCHAR(150),
    message     TEXT,
    type        ENUM('delivery','emergency','payment','system') DEFAULT 'system',
    is_read     TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- SYSTEM SETTINGS
CREATE TABLE settings (
    setting_id  INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_val TEXT,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
9. 📦 SYSTEM MODULES & FEATURES
9.1 Module Map
text

┌─────────────────────────────────────────────────────────┐
│                   SYSTEM MODULES                         │
│                                                         │
│  🔐 AUTH MODULE          📊 DASHBOARD MODULE            │
│  ├── Login               ├── Admin Dashboard            │
│  ├── Register            ├── Manager Dashboard          │
│  ├── Password Reset      ├── Dispatcher Dashboard       │
│  └── Role Guard          ├── Driver Dashboard           │
│                          └── Customer Dashboard         │
│                                                         │
│  🚛 FLEET MODULE         📦 DELIVERY MODULE             │
│  ├── Add Truck           ├── Create Delivery            │
│  ├── Edit/Delete Truck   ├── Assign to Truck/Driver     │
│  ├── Truck Status        ├── Update Delivery Status     │
│  └── Maintenance Logs    ├── Delivery History           │
│                          └── Tracking Code Generator    │
│                                                         │
│  👷 DRIVER MODULE        📍 TRACKING MODULE             │
│  ├── Add Driver          ├── Live Map (Leaflet.js)      │
│  ├── Driver Profiles     ├── GPS Log History            │
│  ├── Trip History        ├── Truck Location Pins        │
│  └── Performance Rating  └── Route Visualization        │
│                                                         │
│  💳 PAYMENT MODULE       📊 REPORTS MODULE              │
│  ├── M-Pesa STK Push     ├── Delivery Reports           │
│  │   (Sandbox Demo)      ├── Fuel Reports               │
│  ├── Payment Status      ├── Driver Performance         │
│  └── Payment History     └── Revenue Charts             │
│                                                         │
│  🚨 EMERGENCY MODULE     🌐 CUSTOMER PORTAL             │
│  ├── Driver SOS Button   ├── Parcel Tracking Page       │
│  ├── Alert Dashboard     ├── Delivery History           │
│  └── Resolution Tracking └── Payment Status             │
│                                                         │
│  🏠 LANDING PAGE                                        │
│  ├── Hero Section        ├── Features Section           │
│  ├── How It Works        ├── Track Parcel (Public)      │
│  └── Contact / Login     └── About the System           │
└─────────────────────────────────────────────────────────┘
9.2 Landing Page (Public-Facing)
We built a professional, fully responsive landing page as the public entry point of the system. It includes:

Section	Content
🎯 Hero	System name, tagline, call-to-action buttons
📦 Track Parcel	Public input to track any delivery by code
⚙️ Features	6 key feature cards
🔄 How It Works	4-step visual process
📊 Stats Counter	Trucks, Deliveries, Drivers, Customers
🏢 About	System overview & TVET innovation story
📞 Contact	Address, phone, email
🔐 Login/Register	Entry to system
10. 📅 DEVELOPMENT TIMELINE (WHAT WE DID)
Started: 29th April 2026 | Tested Live: 12th May 2026

Date	What We Did
29 Apr	Project kickoff. Set up VS Code, XAMPP, GitHub repo. Planned modules and drew ERD
30 Apr	Created database schema (all 10 tables). Set up config/db.php. Populated test data
1 May	Built landing page — Hero, Features, How It Works, Public Tracker. Made it fully responsive
2–3 May	Built Authentication module — Login, Register, Role-Based redirect, Session management
4–5 May	Built Admin & Dispatcher Dashboards with stat cards and charts (Chart.js)
6 May	Built Fleet Management module — Add, Edit, Delete, Status update for trucks
7 May	Built Driver Management module — Profiles, assign to truck, performance view
8 May	Built Delivery module — Create delivery, assign to truck/driver, status flow
9 May	Integrated Leaflet.js + OpenStreetMap for live truck tracking map
10 May	Built Fuel Records module, Emergency/SOS module, and Notifications system
11 May	M-Pesa Sandbox STK Push simulation — payment flow demo. Reports & Charts page
12 May	✅ Full system testing — Uploaded to InfinityFree via FileZilla. Tested all modules live
13 May	🎤 Presentation Day — System is LIVE and ready for demo
11. 🌐 DEPLOYMENT — INFINITYFREE HOSTING
We chose InfinityFree as our hosting platform because:

✅ Completely FREE — no credit card required
✅ Supports PHP 8, MySQL, HTML, CSS, JavaScript
✅ Comes with phpMyAdmin for database management
✅ Provides free subdomain (e.g., couriertrack.infinityfreeapp.com)
✅ Supports FTP deployment via FileZilla
✅ Provides free SSL certificate
✅ Reliable enough for demo and academic deployment
Deployment Steps We Followed:
text

Step 1: Created account on infinityfree.com
Step 2: Created a new hosting account & subdomain
Step 3: Noted FTP credentials (host, username, password)
Step 4: Opened FileZilla → Connected to InfinityFree FTP
Step 5: Uploaded all project files to /htdocs directory
Step 6: Opened phpMyAdmin on InfinityFree panel
Step 7: Created database → Imported our SQL schema
Step 8: Updated config/db.php with live database credentials
Step 9: Tested all pages on live URL — ✅ All working
Step 10: Enabled free SSL → System now runs on HTTPS
Live Project Configuration:
PHP

// config/db.php — Live (InfinityFree)
define('DB_HOST', 'sql.infinityfree.com');
define('DB_USER', 'if0_xxxxxxxx');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'if0_xxxxxxxx_courier');
define('BASE_URL', 'https://couriertrack.infinityfreeapp.com');
12. 🧪 TESTING & RESULTS
Testing Date: 12th May 2026 — Live on InfinityFree

12.1 Test Case Results
Test ID	Module	Scenario	Expected	Result
TC-001	Auth	Admin login with correct credentials	Redirect to Admin Dashboard	✅ PASS
TC-002	Auth	Wrong password attempt	Error message shown	✅ PASS
TC-003	Auth	Driver login redirects to Driver Dashboard	Correct role redirect	✅ PASS
TC-004	Fleet	Add new truck with plate number	Truck saved to DB	✅ PASS
TC-005	Fleet	Change truck status to "On Trip"	Status updates live	✅ PASS
TC-006	Delivery	Create new delivery + generate tracking code	Unique code generated	✅ PASS
TC-007	Delivery	Assign delivery to driver and truck	Assignment saved	✅ PASS
TC-008	Delivery	Update delivery status to "Delivered"	Status updates, timestamp saved	✅ PASS
TC-009	Tracking	Customer enters tracking code on landing page	Delivery progress shown	✅ PASS
TC-010	Maps	Live tracking map loads with truck pins	Leaflet map renders	✅ PASS
TC-011	Payments	M-Pesa STK Push demo initiated	Payment prompt simulated	✅ PASS
TC-012	Emergency	Driver clicks SOS button	Alert appears on Admin panel	✅ PASS
TC-013	Reports	Revenue chart loads with Chart.js	Chart renders correctly	✅ PASS
TC-014	Security	SQL Injection attempt on login	Blocked — prepared statements	✅ PASS
TC-015	Mobile	System loaded on mobile browser	Fully responsive layout	✅ PASS
TC-016	Hosting	All pages load on InfinityFree live URL	Pages load under 4 seconds	✅ PASS
Overall Result: 16/16 Tests Passed ✅

13. 🔐 SECURITY IMPLEMENTATION
PHP

// 1. Password Hashing
$hashed = password_hash($password, PASSWORD_BCRYPT);
password_verify($input, $hashed_from_db);

// 2. SQL Injection Prevention — Prepared Statements (PDO)
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);

// 3. XSS Prevention
$clean = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

// 4. CSRF Token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// 5. Session Security
session_start();
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);

// 6. Role-Based Access Guard
function requireRole($role) {
    if (!isset($_SESSION['user_role']) ||
        $_SESSION['user_role'] !== $role) {
        header("Location: ../login.php?error=unauthorized");
        exit();
    }
}
14. 💳 M-PESA SANDBOX INTEGRATION
We integrated Safaricom's Daraja API Sandbox for payment simulation. During the live demo, the judge can observe a real M-Pesa STK Push flow — including the simulated phone prompt.

PHP

// api/mpesa_stk.php — Daraja Sandbox
function getMpesaToken() {
    $consumerKey    = 'YOUR_SANDBOX_KEY';
    $consumerSecret = 'YOUR_SANDBOX_SECRET';
    $credentials    = base64_encode($consumerKey.':'.$consumerSecret);

    $ch = curl_init('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic '.$credentials]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response)->access_token;
}

function stkPush($phone, $amount, $reference) {
    $token      = getMpesaToken();
    $timestamp  = date('YmdHis');
    $shortcode  = '174379';
    $passkey    = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
    $password   = base64_encode($shortcode.$passkey.$timestamp);

    $data = [
        "BusinessShortCode" => $shortcode,
        "Password"          => $password,
        "Timestamp"         => $timestamp,
        "TransactionType"   => "CustomerPayBillOnline",
        "Amount"            => $amount,
        "PartyA"            => $phone,
        "PartyB"            => $shortcode,
        "PhoneNumber"       => $phone,
        "CallBackURL"       => BASE_URL."/api/mpesa_callback.php",
        "AccountReference"  => $reference,
        "TransactionDesc"   => "Delivery Payment - Courier System"
    ];
    // ... POST to Daraja sandbox endpoint
}
⚠️ Note to Judges: This uses the Safaricom Daraja Sandbox environment — no real money is charged. It demonstrates the full M-Pesa payment flow that would work in production with a registered Safaricom Daraja account.

15. 💰 BUDGET
No	Item	Tool Used	Cost (KES)
1	Frontend (HTML/CSS/JS/Bootstrap)	Bootstrap 5, Chart.js, Leaflet.js	FREE
2	Backend	PHP 8	FREE
3	Database	MySQL + SQLite3	FREE
4	IDE	VS Code	FREE
5	Local Server	XAMPP	FREE
6	Live Hosting	InfinityFree	FREE
7	Domain	InfinityFree Subdomain	FREE
8	SSL Certificate	InfinityFree SSL	FREE
9	FTP Deployment	FileZilla	FREE
10	Map API	OpenStreetMap + Leaflet	FREE
11	Payment API	M-Pesa Daraja Sandbox	FREE
12	Version Control	GitHub	FREE
13	DB Management	phpMyAdmin	FREE
14	Stationery & Printing	—	KES 1,500
15	Internet / Data	Safaricom/Airtel	KES 1,000
TOTAL	KES 2,500
💡 We deliberately chose a 100% free software stack to demonstrate that TVET students can build world-class systems without significant financial barriers — proving TVET's role in bottom-up transformation.

16. 🔭 FUTURE ENHANCEMENTS
Feature	Technology	Timeline
Android/iOS Mobile App	React Native	Phase 2
Real GPS tracking (hardware)	IoT GPS Module	Phase 2
AI Route Optimization	Python ML Model	Phase 3
Live M-Pesa (Production)	Safaricom Daraja Live	Phase 2
SMS Notifications	Africa's Talking API	Phase 2
Cross-border tracking (EAC)	Extended DB schema	Phase 3
Business Intelligence Dashboard	Power BI / Apache Superset	Phase 3
17. 🚀 HOW TO RUN THE PROJECT LOCALLY
Bash

# Step 1: Clone the repository
git clone https://github.com/yourteam/courier-truck-system.git

# Step 2: Move to XAMPP/WAMP htdocs folder
# C:/xampp/htdocs/courier_system/

# Step 3: Start Apache + MySQL in XAMPP Control Panel

# Step 4: Open phpMyAdmin → http://localhost/phpmyadmin
# Create database: courier_system
# Import: /database/courier_system.sql

# Step 5: Update config/db.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'courier_system');
define('BASE_URL', 'http://localhost/courier_system');

# Step 6: Open browser → http://localhost/courier_system

# Default Login Credentials:
# Admin:      admin@courier.co.ke      / Admin@2026
# Dispatcher: dispatcher@courier.co.ke / Dispatch@2026
# Driver:     driver@courier.co.ke     / Driver@2026
# Customer:   customer@courier.co.ke   / Customer@2026
18. 👥 TEAM & ROLES
Name	Role	Responsibility
Presenter 1	Lead Developer & System Architect	Database design, Backend PHP, Deployment
Presenter 2	Frontend Developer & UI Designer	Landing page, Dashboard UI, Leaflet Maps
Enricah Wafula	Supervisor	Project guidance, Review & Approval
19. 📚 REFERENCES
Safaricom PLC — Daraja M-Pesa API Documentation: developer.safaricom.co.ke
Communications Authority of Kenya — Postal & Courier Licensing Guidelines
InfinityFree Hosting Documentation: infinityfree.com/support
Leaflet.js Documentation: leafletjs.com
Bootstrap 5 Documentation: getbootstrap.com
Chart.js Documentation: chartjs.org
PHP Manual: php.net/manual
OpenStreetMap: openstreetmap.org
OWASP Web Security Guidelines: owasp.org
