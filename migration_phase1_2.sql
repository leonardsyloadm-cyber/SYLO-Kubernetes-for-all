-- FASE 1: CREACIÓN DEL ESQUEMA (DDL)
DROP DATABASE IF EXISTS sylo_admin_db;
CREATE DATABASE sylo_admin_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sylo_admin_db;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'client') DEFAULT 'client',
    tipo_usuario ENUM('autonomo', 'empresa') DEFAULT 'autonomo',
    documento_identidad VARCHAR(20),
    telefono VARCHAR(20),
    company_name VARCHAR(100),
    tipo_empresa ENUM('SL', 'SA', 'Cooperativa', 'Otro') DEFAULT NULL,
    direccion VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    base_price DECIMAL(10, 2) NOT NULL, 
    base_cpu INT DEFAULT 1,       
    base_ram INT DEFAULT 1,
    base_storage INT DEFAULT 20,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE k8s_deployments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    status ENUM('pending', 'creating', 'active', 'suspended', 'cancelled', 'terminating', 'error', 'stopped') DEFAULT 'pending',
    cluster_alias VARCHAR(100) DEFAULT 'Mi Servidor',
    subdomain VARCHAR(63) NOT NULL UNIQUE,
    ip_address VARCHAR(45) DEFAULT NULL,
    os_image ENUM('alpine', 'ubuntu', 'redhat') DEFAULT 'ubuntu',
    cpu_cores INT NOT NULL,
    ram_gb INT NOT NULL,
    storage_gb INT NOT NULL,
    web_enabled BOOLEAN DEFAULT FALSE,
    web_type ENUM('nginx', 'apache', 'ninguno') DEFAULT 'ninguno',
    web_custom_name VARCHAR(63),
    db_enabled BOOLEAN DEFAULT FALSE,
    db_type ENUM('mysql', 'postgresql', 'mongodb', 'ninguno') DEFAULT 'ninguno',
    db_custom_name VARCHAR(63),
    ssh_user VARCHAR(32) DEFAULT 'root',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_k8s_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_k8s_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
);

CREATE TABLE cluster_secrets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL UNIQUE,
    ssh_password_enc TEXT NOT NULL,
    db_root_password_enc TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_secrets_deployment FOREIGN KEY (deployment_id) REFERENCES k8s_deployments(id) ON DELETE CASCADE
);

CREATE TABLE k8s_tools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    tool_name VARCHAR(50) NOT NULL,
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tool_deployment FOREIGN KEY (deployment_id) REFERENCES k8s_deployments(id) ON DELETE CASCADE
);

CREATE TABLE sylo_drive_buckets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bucket_name VARCHAR(100) NOT NULL UNIQUE,
    quota_gb INT NOT NULL,
    access_key_enc TEXT NOT NULL,
    secret_key_enc TEXT NOT NULL,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_drive_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- FASE 2: MIGRACIÓN DE DATOS RELACIONALES (SQL)

-- 1. Migrar Usuarios
INSERT INTO sylo_admin_db.users (id, username, email, password_hash, full_name, role, tipo_usuario, documento_identidad, telefono, company_name, direccion, created_at)
SELECT id, username, email, password_hash, full_name, role, tipo_usuario, dni, telefono, company_name, calle, created_at 
FROM kylo_main_db.users;

-- 2. Migrar Planes
INSERT INTO sylo_admin_db.plans (id, name, base_price, base_cpu, base_ram, base_storage, description)
SELECT id, name, price, cpu_cores, ram_gb, 20, description 
FROM kylo_main_db.plans;

-- 3. Migrar y Fusionar Orders + Specs -> k8s_deployments
INSERT INTO sylo_admin_db.k8s_deployments (
    id, user_id, plan_id, status, cluster_alias, subdomain, ip_address, 
    os_image, cpu_cores, ram_gb, storage_gb, 
    web_enabled, web_type, web_custom_name, 
    db_enabled, db_type, db_custom_name, ssh_user, created_at
)
SELECT 
    o.id, o.user_id, o.plan_id, o.status, os.cluster_alias, CONCAT(os.subdomain, '-', o.id), o.ip_address, 
    os.os_image, os.cpu_cores, os.ram_gb, os.storage_gb, 
    IF(os.web_enabled, 1, 0), IF(os.web_type IS NULL OR os.web_type = '', 'ninguno', os.web_type), os.web_custom_name, 
    IF(os.db_enabled, 1, 0), IF(os.db_type IS NULL OR os.db_type = '', 'ninguno', os.db_type), os.db_custom_name, os.ssh_user, o.purchase_date
FROM kylo_main_db.orders o
JOIN kylo_main_db.order_specs os ON o.id = os.order_id;
