<?php
session_start();

// --- 1. CONEXIÓN (Igual que admin.php) ---
$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error crítico de conexión: " . $e->getMessage());
}

// --- 2. SECURITY CHECK ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php"); exit;
}

// --- 3. ELIMINACIÓN DE FANTASMAS ---
try {
    // AQUÍ ESTÁ EL CAMBIO: Usamos DELETE en lugar de UPDATE
    // Borrará todo lo que esté cancelado, terminado o pendiente de borrado
    $sql_nuke = "DELETE FROM orders 
                 WHERE status IN ('cancelled', 'terminated', 'error', 'terminating')";
    
    $stmt = $conn->prepare($sql_nuke);
    $stmt->execute();
    
    // Opcional: Si también quieres borrar los que se quedaron en 'pending' viejos
    // Descomenta la siguiente línea si quieres ser muy agresivo:
    // $conn->exec("DELETE FROM orders WHERE status = 'pending'");

} catch(PDOException $e) {
    echo "Error al limpiar la base de datos: " . $e->getMessage();
    exit;
}

// --- 4. REDIRECCIÓN ---
header("Location: admin.php");
exit();
?>