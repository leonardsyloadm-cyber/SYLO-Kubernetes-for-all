-- ============================================================
-- BASE DE DATOS MAESTRA DE SYLO (KYLO Main DB)
-- ============================================================

CREATE DATABASE IF NOT EXISTS kylo_main_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kylo_main_db;

-- ------------------------------------------------------------
-- 1. Tabla de USUARIOS (Clientes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    company_name VARCHAR(100), -- Null si es particular
    full_name VARCHAR(100),
    role ENUM('admin', 'client') DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- 2. Tabla de PLANES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    price DECIMAL(10, 2) NOT NULL, -- Precio base
    cpu_cores INT DEFAULT 0,       -- 0 indica "Variable" en planes custom
    ram_gb INT DEFAULT 0,          -- 0 indica "Variable"
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

-- ------------------------------------------------------------
-- 3. Tabla de ÓRDENES (Pedidos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('pending', 'active', 'suspended', 'cancelled') DEFAULT 'pending',
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Relaciones
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- ------------------------------------------------------------
-- 4. Tabla de DETALLES CUSTOM (NUEVA)
-- Sirve para guardar qué eligió el cliente si el plan es Personalizado.
-- Aunque el JSON manda esto al orquestador, es bueno tener registro aquí.
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

-- ------------------------------------------------------------
-- 5. Tabla de CLÚSTERES (Infraestructura Desplegada)
-- Aquí es donde el orquestador podría escribir la IP final
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    cluster_name VARCHAR(100) NOT NULL,
    endpoint_url VARCHAR(255),
    ssh_user VARCHAR(50),      -- Nuevo: Para mostrar en panel
    ssh_port INT,              -- Nuevo
    status ENUM('provisioning', 'running', 'stopped', 'error') DEFAULT 'provisioning',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_cluster_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- ============================================================
-- DATOS INICIALES (SEMILLA)
-- ============================================================

-- Planes Fijos + EL NUEVO PLAN PERSONALIZADO
-- Es vital que exista 'Personalizado' para que el index.php no falle.
INSERT INTO plans (name, price, cpu_cores, ram_gb, description) VALUES
('Bronce', 5.00, 1, 1, 'Kubernetes Simple para desarrollo'),
('Plata', 15.00, 4, 4, 'K8s + MySQL HA Replicado'),
('Oro', 30.00, 6, 8, 'Full Stack HA (Web + DB)'),
('Personalizado', 0.00, 0, 0, 'Configuración a medida del cliente')
ON DUPLICATE KEY UPDATE price=VALUES(price);

-- Usuario Admin por defecto
INSERT INTO users (username, email, password_hash, company_name, full_name, role) VALUES
('admin_sylo', 'admin@sylobi.com', '$2y$10$E7...HashedPassword...', 'SYLO Corp', 'Administrador Sistema', 'admin');