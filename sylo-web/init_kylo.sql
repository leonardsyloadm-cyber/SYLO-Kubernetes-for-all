-- ============================================================
-- BASE DE DATOS MAESTRA DE SYLO (RESET TOTAL)
-- ============================================================
DROP DATABASE IF EXISTS kylo_main_db;
CREATE DATABASE kylo_main_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kylo_main_db;

-- 1. USUARIOS
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'client') DEFAULT 'client',
    tipo_usuario ENUM('autonomo', 'empresa') DEFAULT 'autonomo',
    dni VARCHAR(20),
    telefono VARCHAR(20),
    company_name VARCHAR(100),
    tipo_empresa VARCHAR(50),
    calle VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. PLANES
CREATE TABLE plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    price DECIMAL(10, 2) NOT NULL, 
    cpu_cores INT DEFAULT 0,       
    ram_gb INT DEFAULT 0,          
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

-- 3. ÓRDENES (CON LA COLUMNA IP_ADDRESS)
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('pending', 'creating', 'active', 'suspended', 'cancelled', 'terminating', 'error') DEFAULT 'pending',
    ip_address VARCHAR(45) DEFAULT NULL,
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- 4. ESPECIFICACIONES
CREATE TABLE order_specs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    cpu_cores INT NOT NULL,
    ram_gb INT NOT NULL,
    storage_gb INT NOT NULL,
    db_enabled BOOLEAN DEFAULT FALSE,
    db_type VARCHAR(50),
    db_custom_name VARCHAR(63),
    web_enabled BOOLEAN DEFAULT FALSE,
    web_type VARCHAR(50),
    web_custom_name VARCHAR(63),
    cluster_alias VARCHAR(100) DEFAULT 'Mi Cluster',
    cluster_description TEXT,
    subdomain VARCHAR(63) NOT NULL DEFAULT 'demo',
    ssh_user VARCHAR(32) DEFAULT 'usuario',
    os_image ENUM('alpine', 'ubuntu', 'redhat') DEFAULT 'ubuntu',
    tools TEXT,
    CONSTRAINT fk_specs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- DATOS INICIALES
INSERT INTO plans (name, price, cpu_cores, ram_gb, description) VALUES
('Bronce', 5.00, 1, 1, 'K8s Simple'),
('Plata', 15.00, 2, 2, 'K8s + DB HA'),
('Oro', 30.00, 3, 3, 'Full Stack HA'),
('Personalizado', 0.00, 0, 0, 'Configurable');

INSERT INTO users (username, email, password_hash, company_name, full_name, role) VALUES
('admin_sylo', 'admin@sylo.com', '$2y$10$E741.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4', 'SYLO Corp', 'Super Admin', 'admin');