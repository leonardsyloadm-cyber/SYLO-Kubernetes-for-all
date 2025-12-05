-- ============================================================
-- BASE DE DATOS MAESTRA DE SYLO (KYLO Main DB)
-- ============================================================

-- 1. Crear la base de datos y seleccionarla
CREATE DATABASE IF NOT EXISTS kylo_main_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kylo_main_db;

-- 2. Tabla de USUARIOS (Clientes)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, -- ¡NUNCA guardar texto plano!
    company_name VARCHAR(100),
    full_name VARCHAR(100),
    role ENUM('admin', 'client') DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 3. Tabla de PLANES (Catálogo de Productos)
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE, -- 'Bronce', 'Plata', 'Oro'
    price DECIMAL(10, 2) NOT NULL,
    cpu_cores INT NOT NULL,
    ram_gb INT NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

-- 4. Tabla de ÓRDENES (Relación Muchos a Muchos: Usuarios <-> Planes)
-- Un usuario puede tener muchas órdenes. Un plan está en muchas órdenes.
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('pending', 'active', 'suspended', 'cancelled') DEFAULT 'pending',
    purchase_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Relaciones (Foreign Keys)
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- 5. Tabla de CLÚSTERES (Infraestructura desplegada)
-- Vincula una orden con el clúster real creado por el Orquestador
CREATE TABLE IF NOT EXISTS clusters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE, -- Una orden = Un clúster
    cluster_name VARCHAR(100) NOT NULL, -- Ej: 'ClienteDB-17648...'
    endpoint_url VARCHAR(255),          -- Ej: 'http://192.168.49.2:30000'
    status ENUM('provisioning', 'running', 'stopped', 'error') DEFAULT 'provisioning',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_cluster_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- ============================================================
-- DATOS INICIALES (Seed Data)
-- ============================================================

-- Insertar los Planes de SYLO para que ya existan al arrancar
INSERT INTO plans (name, price, cpu_cores, ram_gb, description) VALUES
('Bronce', 5.00, 1, 1, 'Kubernetes Simple para desarrollo'),
('Plata', 15.00, 4, 4, 'K8s + MySQL HA Replicado'),
('Oro', 30.00, 6, 8, 'Full Stack HA (Web + DB)')
ON DUPLICATE KEY UPDATE price=VALUES(price); 
-- (El ON DUPLICATE evita errores si reinicias el contenedor muchas veces)

-- Crear un usuario Administrador por defecto (Password: 'admin123' hasheada - EJEMPLO)
-- En producción usaremos PHP password_hash()
INSERT INTO users (email, password_hash, company_name, role) VALUES
('admin@sylobi.com', '$2y$10$E9...', 'SYLO Corp', 'admin')
ON DUPLICATE KEY UPDATE role='admin';