-- ============================================================
-- COURIER TRUCK MANAGEMENT SYSTEM — DATABASE SCHEMA
-- Bungoma National Polytechnic 2026
-- Created: 29 April 2026 | Last Modified: 12 May 2026
-- ============================================================

CREATE DATABASE IF NOT EXISTS courier_system;
USE courier_system;

-- ============================================================
-- USERS TABLE (All system users)
-- ============================================================
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

-- ============================================================
-- TRUCKS TABLE
-- ============================================================
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

-- ============================================================
-- DRIVERS TABLE
-- ============================================================
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

-- ============================================================
-- CUSTOMERS TABLE
-- ============================================================
CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL UNIQUE,
    company     VARCHAR(100),
    address     TEXT,
    county      VARCHAR(50) DEFAULT 'Bungoma',
    total_orders INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============================================================
-- DELIVERIES TABLE
-- ============================================================
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

-- ============================================================
-- TRACKING LOGS (GPS History)
-- ============================================================
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

-- ============================================================
-- FUEL RECORDS
-- ============================================================
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

-- ============================================================
-- EMERGENCIES / SOS
-- ============================================================
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

-- ============================================================
-- PAYMENTS (M-Pesa Sandbox Logs)
-- ============================================================
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

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
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

-- ============================================================
-- SYSTEM SETTINGS
-- ============================================================
CREATE TABLE settings (
    setting_id  INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_val TEXT,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- DEFAULT ADMIN USER (Password: Admin@2026)
-- ============================================================
INSERT INTO users (full_name, email, phone, password, role, status) VALUES
('System Admin', 'admin@courier.co.ke', '254700000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');

-- Insert driver user for testing
INSERT INTO users (full_name, email, phone, password, role, status) VALUES
('John Driver', 'driver@courier.co.ke', '254700000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'driver', 'active');

-- Insert customer user for testing
INSERT INTO users (full_name, email, phone, password, role, status) VALUES
('Jane Customer', 'customer@courier.co.ke', '254700000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active');

-- Add some sample settings
INSERT INTO settings (setting_key, setting_val) VALUES
('site_name', 'Courier Truck Management System'),
('site_url', 'https://yourdomain.infinityfreeapp.com'),
('timezone', 'Africa/Nairobi'),
('currency', 'KES');

-- ============================================================
-- END OF SCHEMA
-- ============================================================