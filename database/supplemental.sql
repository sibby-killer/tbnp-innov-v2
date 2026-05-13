-- ============================================================
-- SUPPLEMENTAL TABLES FOR DASHBOARD
-- These tables are needed by admin/dashboard.php queries
-- ============================================================

-- ============================================================
-- COURIERS TABLE (Shipping Companies)
-- ============================================================
CREATE TABLE couriers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    contact_person  VARCHAR(100),
    email           VARCHAR(100),
    phone           VARCHAR(20),
    address         TEXT,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CLIENTS TABLE (Business Customers - alias for customers)
-- ============================================================
-- Note: We already have customers table, this can be a view or alias
-- For now, let's create a clients table with same structure
CREATE TABLE clients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,
    company_name    VARCHAR(100),
    contact_person  VARCHAR(100),
    email           VARCHAR(100),
    phone           VARCHAR(20),
    address         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- ORDER STATUS REFERENCE TABLE
-- ============================================================
CREATE TABLE order_status (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50) NOT NULL,
    color       VARCHAR(20) DEFAULT '#6c757d',
    sequence    INT DEFAULT 0,
    description VARCHAR(255)
);

-- Insert default order statuses
INSERT INTO order_status (name, color, sequence, description) VALUES
('pending', '#ffc107', 1, 'Order created, awaiting assignment'),
('confirmed', '#17a2b8', 2, 'Order confirmed by dispatcher'),
('assigned', '#007bff', 3, 'Driver assigned to order'),
('picked_up', '#6f42c1', 4, 'Package picked up from sender'),
('in_transit', '#20c997', 5, 'Package in transit to destination'),
('out_for_delivery', '#fd7e14', 6, 'Driver en route to delivery address'),
('delivered', '#28a745', 7, 'Package delivered successfully'),
('cancelled', '#dc3545', 8, 'Order cancelled'),
('returned', '#6c757d', 9, 'Package returned to sender');

-- ============================================================
-- ORDERS TABLE (Main order/delivery table - primary operations)
-- ============================================================
CREATE TABLE orders (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number     VARCHAR(20) UNIQUE NOT NULL,
    client_id           INT,
    driver_id           INT,
    courier_id          INT,
    pickup_location     VARCHAR(255),
    delivery_location   VARCHAR(255),
    pickup_lat          DECIMAL(10,8),
    pickup_lng          DECIMAL(11,8),
    delivery_lat        DECIMAL(10,8),
    delivery_lng        DECIMAL(11,8),
    description         TEXT,
    weight_kg           DECIMAL(10,2),
    amount_kes          DECIMAL(10,2) DEFAULT 0.00,
    status_id           INT DEFAULT 1,
    priority            ENUM('normal','urgent','express') DEFAULT 'normal',
    estimated_delivery  DATETIME,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (driver_id) REFERENCES drivers(driver_id),
    FOREIGN KEY (courier_id) REFERENCES couriers(id),
    FOREIGN KEY (status_id) REFERENCES order_status(id)
);

-- ============================================================
-- ACTIVITY LOGS (User activity tracking)
-- ============================================================
CREATE TABLE activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    action      VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- ============================================================
-- ORDER LOGS (Order status change history)
-- ============================================================
CREATE TABLE order_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NOT NULL,
    status_id   INT NOT NULL,
    changed_by  INT,
    notes       TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (status_id) REFERENCES order_status(id),
    FOREIGN KEY (changed_by) REFERENCES users(user_id)
);

-- ============================================================
-- INSERT SAMPLE DATA
-- ============================================================

-- Sample courier
INSERT INTO couriers (name, contact_person, email, phone, address, status) VALUES
('Bungoma Express', 'Main Office', 'info@bungomaexpress.co.ke', '254700000000', 'Bungoma Town', 'active');

-- Sample clients
INSERT INTO clients (company_name, contact_person, email, phone, address) VALUES
('Bungoma Supermarket', 'Manager', 'manager@bungomasupermarket.com', '254700000001', 'Bungoma CBD'),
('Western Kenya Traders', 'Owner', 'owner@wktaders.co.ke', '254700000002', 'Mumias Road');

-- Sample order for testing
INSERT INTO orders (tracking_number, client_id, status_id, pickup_location, delivery_location, amount_kes, created_at) VALUES
('TRK-001', 1, 1, 'Bungoma Town Hall', 'Mumias Market', 1500.00, NOW()),
('TRK-002', 2, 3, 'Webuye Town', 'Bungoma CBD', 2000.00, NOW());

-- ============================================================
-- END OF SUPPLEMENTAL TABLES
-- ============================================================