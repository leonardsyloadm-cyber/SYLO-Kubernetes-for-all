-- ============================================================
-- BASE DE DATOS MAESTRA DE SYLO (KYLO Main DB)
-- ============================================================

CREATE DATABASE IF NOT EXISTS kylo_main_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kylo_main_db;

-- 2. Tabla de USUARIOS (Clientes)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    company_name VARCHAR(100),
    full_name VARCHAR(100),
    role ENUM('admin', 'client') DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. Tabla de PLANES
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    price DECIMAL(10, 2) NOT NULL,
    cpu_cores INT NOT NULL,
    ram_gb INT NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

-- 4. Tabla de ÓRDENES
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('pending', 'active', 'suspended', 'cancelled') DEFAULT 'pending',
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- 5. Tabla de CLÚSTERES
CREATE TABLE IF NOT EXISTS clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    cluster_name VARCHAR(100) NOT NULL,
    endpoint_url VARCHAR(255),
    status ENUM('provisioning', 'running', 'stopped', 'error') DEFAULT 'provisioning',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cluster_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- DATOS INICIALES
INSERT INTO plans (name, price, cpu_cores, ram_gb, description) VALUES
('Bronce', 5.00, 1, 1, 'Kubernetes Simple para desarrollo'),
('Plata', 15.00, 4, 4, 'K8s + MySQL HA Replicado'),
('Oro', 30.00, 6, 8, 'Full Stack HA (Web + DB)')
ON DUPLICATE KEY UPDATE price=VALUES(price);

-- Admin por defecto
INSERT INTO users (username, email, password_hash, company_name, role) VALUES
('admin_sylo', 'admin@sylobi.com', '$2y$10$DummyHash...', 'SYLO Corp', 'admin')
ON DUPLICATE KEY UPDATE role='admin';