<?php
/**
 * clean_db.php — Total System Reset
 * Drops and recreates the kylo_core database to start from a fresh state.
 */

declare(strict_types=1);

$host = 'localhost';
$port = '3307';
$user = 'root';
$pass = '';

try {
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => true, 
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);

    echo "=== KYV DATABASE PURGE ===\n\n";

    // 1. Drop Database
    echo "[1] Dropping database 'kylo_core'...\n";
    $pdo->exec("DROP DATABASE IF EXISTS kylo_core");

    // 2. Recreate Database
    echo "[2] Recreating database 'kylo_core'...\n";
    $pdo->exec("CREATE DATABASE kylo_core");

    // 3. Create Security Logs table
    echo "[3] Recreating table 'kylo_core.security_logs'...\n";
    $sql_logs = "CREATE TABLE kylo_core.security_logs (
        id INT,
        event_uuid VARCHAR(255),
        discord_id VARCHAR(255),
        username VARCHAR(255),
        event_type VARCHAR(255),
        details TEXT
    )";
    $pdo->exec($sql_logs);

    // 4. Create Safe Vault table
    echo "[4] Recreating table 'kylo_core.safe_vault'...\n";
    $sql_vault = "CREATE TABLE kylo_core.safe_vault (
        id INT,
        vault_uuid VARCHAR(255),
        ropro_link TEXT
    )";
    $pdo->exec($sql_vault);

    echo "\n[✔] SYSTEM RESET COMPLETE. Database is now empty.\n";

} catch (PDOException $e) {
    echo "\n[✘] RESET FAILED: " . $e->getMessage() . "\n";
}
?>
