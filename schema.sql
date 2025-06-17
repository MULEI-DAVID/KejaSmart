CREATE DATABASE IF NOT EXISTS kejasmart;
USE kejasmart;

-- Admins Table
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ALTER TABLE users ADD locked_until DATETIME NULL DEFAULT NULL;
);

-- Landlords Table
CREATE TABLE landlords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    country VARCHAR(50),
    password VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ALTER TABLE users ADD locked_until DATETIME NULL DEFAULT NULL;
);

-- Properties Table
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    property_name VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    number_of_units INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
);

-- Units Table
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    unit_name VARCHAR(50) NOT NULL,
    is_occupied BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

-- Tenants Table
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    id_number VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    unit_id INT,
    lease_document VARCHAR(255), -- PDF or image path
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
    ALTER TABLE users ADD locked_until DATETIME NULL DEFAULT NULL;
);

-- Payments Table (M-Pesa Integration)
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    unit_id INT,
    amount DECIMAL(10,2) NOT NULL,
    mpesa_code VARCHAR(100) UNIQUE NOT NULL,
    payment_month VARCHAR(7), -- e.g. '2025-06'
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
);

-- Maintenance Requests
CREATE TABLE maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    subject VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'in_progress', 'resolved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Income Summary Table (Optional caching/reporting)
CREATE TABLE income_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    month VARCHAR(7) NOT NULL, -- e.g. '2025-06'
    total_amount DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
);

-- Lease Agreement Uploads Table (Optional, if multiple files supported)
CREATE TABLE lease_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- login attempts
CREATE TABLE login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255),
  ip_address VARCHAR(50),
  attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
  success BOOLEAN
);

-- For tracking login attempts
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255),
    ip_address VARCHAR(45),
    success BOOLEAN,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Landlords table
CREATE TABLE landlords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    status ENUM('pending', 'approved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Properties table
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(255),
    number_of_units INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE
);

-- Units table (optional if you want more control per unit)
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    unit_name VARCHAR(50),
    status ENUM('vacant', 'occupied') DEFAULT 'vacant',
    rent_amount DECIMAL(10,2),
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

-- Tenants table
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    unit_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    rent_status ENUM('paid', 'due') DEFAULT 'due',
    last_payment_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
);

-- Payments table
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    date DATE DEFAULT CURRENT_DATE,
    method ENUM('mpesa', 'cash', 'bank') DEFAULT 'mpesa',
    status ENUM('completed', 'pending', 'failed') DEFAULT 'completed',
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);

-- Service Requests table
CREATE TABLE service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    unit_id INT,
    issue TEXT NOT NULL,
    status ENUM('pending', 'in progress', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
);

-- Lease Agreements table
CREATE TABLE lease_agreements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    expiry_date DATE,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Messages & Notifications
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_role ENUM('admin', 'landlord', 'tenant'),
    receiver_role ENUM('admin', 'landlord', 'tenant'),
    receiver_id INT,
    subject VARCHAR(255),
    body TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

