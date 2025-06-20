-- KejaSmart Database Schema
-- Version: 2.0
-- Last Updated: 2023-08-20

CREATE DATABASE IF NOT EXISTS kejasmart;
USE kejasmart;

-- Core Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('admin', 'landlord', 'tenant') NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(100),
    reset_token VARCHAR(100),
    reset_token_expires DATETIME,
    locked_until DATETIME NULL,
    login_attempts INT DEFAULT 0,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_user_type (user_type),
    INDEX idx_verified (is_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admins Table
CREATE TABLE admins (
    id INT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    last_accessed_at DATETIME NULL,
    FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Landlords Table
CREATE TABLE landlords (
    id INT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    national_id VARCHAR(20),
    kra_pin VARCHAR(20),
    address TEXT,
    county VARCHAR(50),
    status ENUM('pending', 'approved', 'rejected', 'suspended') DEFAULT 'pending',
    approval_notes TEXT,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    mpesa_consumer_key VARCHAR(255) NULL,
mpesa_consumer_secret VARCHAR(255) NULL,
mpesa_short_code VARCHAR(10) NULL,
mpesa_pass_key VARCHAR(255) NULL,
mpesa_environment ENUM('sandbox', 'production') DEFAULT 'sandbox',
    FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_county (county)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenants Table
CREATE TABLE tenants (
    id INT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    national_id VARCHAR(20) NOT NULL,
    emergency_contact VARCHAR(20),
    emergency_name VARCHAR(100),
    occupation VARCHAR(100),
    employer VARCHAR(100),
    FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_national_id (national_id),
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Property Categories
CREATE TABLE property_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Properties Table
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    category_id INT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    location VARCHAR(255) NOT NULL,
    county VARCHAR(50) NOT NULL,
    town VARCHAR(50) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    number_of_units INT NOT NULL DEFAULT 0,
    amenities TEXT,
    status ENUM('active', 'inactive', 'under_renovation') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES property_categories(id) ON DELETE SET NULL,
    INDEX idx_landlord (landlord_id),
    INDEX idx_location (county, town),
    SPATIAL INDEX idx_coordinates (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Property Photos
CREATE TABLE property_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    photo_url VARCHAR(255) NOT NULL,
    is_featured BOOLEAN DEFAULT FALSE,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unit Types
CREATE TABLE unit_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    base_rent DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Units Table
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    type_id INT NULL,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    rent_amount DECIMAL(10,2) NOT NULL,
    deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    square_footage INT,
    bedrooms TINYINT DEFAULT 1,
    bathrooms TINYINT DEFAULT 1,
    features TEXT,
    status ENUM('vacant', 'occupied', 'maintenance', 'reserved') DEFAULT 'vacant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id) REFERENCES unit_types(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_property_unit (property_id, name),
    INDEX idx_status (status),
    INDEX idx_rent (rent_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unit Photos
CREATE TABLE unit_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    photo_url VARCHAR(255) NOT NULL,
    is_featured BOOLEAN DEFAULT FALSE,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    INDEX idx_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lease Agreements
CREATE TABLE leases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    unit_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_rent DECIMAL(10,2) NOT NULL,
    deposit_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_due_day TINYINT DEFAULT 1,
    status ENUM('active', 'terminated', 'expired', 'pending') DEFAULT 'pending',
    terms TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id),
    INDEX idx_active_leases (status, end_date),
    INDEX idx_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lease Documents
CREATE TABLE lease_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    document_type ENUM('contract', 'id_copy', 'payment_receipt', 'other') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    INDEX idx_lease (lease_id),
    INDEX idx_type (document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment Transactions
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    transaction_id VARCHAR(100),
    payment_method ENUM('mpesa', 'bank', 'cash', 'cheque', 'other') NOT NULL,
    reference_number VARCHAR(100),
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    notes TEXT,
    recorded_by INT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_transaction (transaction_id),
    INDEX idx_lease (lease_id),
    INDEX idx_date (payment_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M-Pesa Transactions
CREATE TABLE mpesa_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NULL,
    checkout_request_id VARCHAR(100),
    merchant_request_id VARCHAR(100),
    phone VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    receipt_number VARCHAR(50),
    transaction_date DATETIME,
    account_reference VARCHAR(100),
    transaction_desc VARCHAR(255),
    status ENUM('requested', 'completed', 'failed', 'cancelled') DEFAULT 'requested',
    raw_response JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_checkout (checkout_request_id),
    UNIQUE KEY uniq_receipt (receipt_number),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance Requests
CREATE TABLE maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    unit_id INT NOT NULL,
    lease_id INT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    urgency ENUM('low', 'medium', 'high', 'emergency') DEFAULT 'medium',
    status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    assigned_to INT NULL,
    completed_at DATETIME NULL,
    tenant_rating TINYINT,
    tenant_feedback TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status),
    INDEX idx_urgency (urgency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maintenance Photos
CREATE TABLE maintenance_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    photo_url VARCHAR(255) NOT NULL,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'landlord', 'tenant') NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    notification_type ENUM('payment', 'maintenance', 'system', 'approval', 'alert') NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    related_id INT,
    related_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id, user_type),
    INDEX idx_read (is_read),
    INDEX idx_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login Attempts
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success BOOLEAN NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_agent TEXT,
    INDEX idx_email (email),
    INDEX idx_ip (ip_address),
    INDEX idx_time (attempt_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System Settings
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Log
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    user_type ENUM('admin', 'landlord', 'tenant', 'system', 'cron') NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_user (user_id, user_type),
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Data
INSERT INTO property_categories (name, description, base_rent) VALUES
('Apartment', 'Self-contained residential units within a building', 15000),
('Bedsitter', 'Single room with combined living/sleeping area', 8000),
('Studio', 'Single room with kitchenette and bathroom', 12000),
('Commercial', 'Business or retail spaces', 25000),
('House', 'Standalone residential house', 30000);

INSERT INTO unit_types (name, description, base_rent) VALUES
('1 Bedroom', 'One bedroom self-contained unit', 15000),
('2 Bedroom', 'Two bedroom self-contained unit', 22000),
('3 Bedroom', 'Three bedroom self-contained unit', 30000),
('Bedsitter', 'Single room unit with shared facilities', 8000),
('Commercial', 'Business or retail space', 25000);

INSERT INTO system_settings (setting_key, setting_value, description, is_public) VALUES
('company_name', 'KejaSmart', 'The display name for the application', TRUE),
('default_currency', 'KES', 'Default currency for payments', TRUE),
('mpesa_paybill', '123456', 'M-Pesa paybill number', FALSE),
('auto_approve_tenants', '1', 'Whether to auto-approve tenant registrations', FALSE),
('landlord_approval_email', 'approvals@kejasmart.com', 'Email to notify for landlord approvals', FALSE),
('min_rent_amount', '5000', 'Minimum allowed rent amount in KES', FALSE),
('late_fee_percentage', '5', 'Percentage charged for late payments', TRUE),
('grace_period_days', '5', 'Number of days before late fees apply', TRUE);
('mpesa_callback_base', 'https://yourdomain.com/mpesa_callback.php', 'Base URL for M-Pesa callbacks', FALSE);