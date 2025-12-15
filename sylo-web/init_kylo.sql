-- ============================================================
-- BASE DE DATOS MAESTRA DE SYLO (KYLO Main DB)
-- ============================================================

-- 1. Configuración Inicial
CREATE DATABASE IF NOT EXISTS kylo_main_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kylo_main_db;

-- ------------------------------------------------------------
-- 2. Tabla de USUARIOS (Con datos fiscales extendidos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'client') DEFAULT 'client',
    
    -- Campos de Registro Detallado
    tipo_usuario ENUM('autonomo', 'empresa') DEFAULT 'autonomo',
    dni VARCHAR(20),            -- Autónomos
    telefono VARCHAR(20),       -- Ambos
    company_name VARCHAR(100),  -- Nombre comercial o Razón Social
    tipo_empresa VARCHAR(50),   -- SL, SA, etc.
    calle VARCHAR(255),         -- Dirección fiscal
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- 3. Tabla de PLANES (Catálogo Actualizado)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    price DECIMAL(10, 2) NOT NULL, 
    cpu_cores INT DEFAULT 0,       
    ram_gb INT DEFAULT 0,          
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

-- ------------------------------------------------------------
-- 4. Tabla de ÓRDENES (Pedidos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('pending', 'creating', 'active', 'suspended', 'cancelled', 'terminating', 'error') DEFAULT 'pending',
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- ------------------------------------------------------------
-- 5. Tabla de DETALLES CUSTOM (Hardware Specs)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_specs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    cpu_cores INT NOT NULL,
    ram_gb INT NOT NULL,
    storage_gb INT NOT NULL,
    db_enabled BOOLEAN DEFAULT FALSE,
    db_type VARCHAR(50),
    web_enabled BOOLEAN DEFAULT FALSE,
    web_type VARCHAR(50),
    
    CONSTRAINT fk_specs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- ============================================================
-- DATOS SEMILLA (INICIALES)
-- ============================================================

-- Planes con los RECURSOS REDUCIDOS que pediste:
-- Bronce: 1 CPU / 1 GB
-- Plata:  2 CPU / 2 GB
-- Oro:    3 CPU / 3 GB
INSERT INTO plans (name, price, cpu_cores, ram_gb, description) VALUES
('Bronce', 5.00, 1, 1, 'K8s Simple'),
('Plata', 15.00, 2, 2, 'K8s + DB HA'),
('Oro', 30.00, 3, 3, 'Full Stack HA'),
('Personalizado', 0.00, 0, 0, 'Configurable')
ON DUPLICATE KEY UPDATE 
    price=VALUES(price), 
    cpu_cores=VALUES(cpu_cores), 
    ram_gb=VALUES(ram_gb);

-- Usuario Admin por defecto (Pass: admin123)
INSERT IGNORE INTO users (username, email, password_hash, company_name, full_name, role) VALUES
('admin_sylo', 'admin@sylo.com', '$2y$10$E741.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4.gJz4', 'SYLO Corp', 'Super Admin', 'admin');