<?php
// logic: sylo-web/public/php/auth.php
// backend logic for Landing/Login

ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/csrf.php';

define('API_URL', 'http://172.17.0.1:8001/api/clientes');

// --- 1. DB ---
$db_host = getenv('DB_HOST') ?: "kylo-main-db";
$db_user = getenv('DB_USER') ?: "sylo_app";
$db_pass = getenv('DB_PASS') ?: "sylo_app_pass";
$db_name = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { if($_SERVER['REQUEST_METHOD']=='POST') die(json_encode(["status"=>"error"])); }

// --- CHECK USER ---
$has_clusters = false;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('active', 'suspended', 'creating', 'pending')");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetchColumn() > 0) $has_clusters = true;
    } catch(Exception $e) {}
}

// --- 2. STATUS CHECK ---
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    $id = filter_var($_GET['check_status'], FILTER_VALIDATE_INT);
    $ch = curl_init(API_URL . "/estado/" . $id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $res) echo $res;
    else echo json_encode(["percent" => 10, "message" => "Conectando al Núcleo...", "status" => "pending"]);
    exit;
}

// --- 3. POST HANDLER ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // CSRF Check
    $headers = getallheaders();
    $token = $headers['X-CSRF-Token'] ?? ($input['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(["status"=>"error", "mensaje"=>"CSRF Token Invalid"]);
        exit;
    }

    $action = $input['action'] ?? '';
    header('Content-Type: application/json');

    if ($action === 'register') {
        try {
            if ($input['password'] !== $input['password_confirm']) throw new Exception("Pass mismatch");
            $user = htmlspecialchars($input['username']);
            $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
            $pass = password_hash($input['password'], PASSWORD_BCRYPT);
            $tipo = $input['tipo_usuario'];
            $fn = ($tipo === 'autonomo') ? $input['full_name'] : $input['contact_name'];
            $dn = ($tipo === 'autonomo') ? $input['dni'] : $input['cif'];
            $cn = ($tipo === 'empresa') ? $input['company_name'] : null;
            $te = ($tipo === 'empresa') ? $input['tipo_empresa'] : null;
            $sql = "INSERT INTO users (username, full_name, email, password_hash, role, tipo_usuario, dni, telefono, company_name, tipo_empresa, calle, created_at) VALUES (?, ?, ?, ?, 'client', ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user, $fn, $email, $pass, $tipo, $dn, $input['telefono'], $cn, $te, $input['calle']]);
            $_SESSION['user_id'] = $conn->lastInsertId(); $_SESSION['username'] = $user; $_SESSION['company'] = $cn ?: 'Particular';
            echo json_encode(["status"=>"success"]);
        } catch (Exception $e) { echo json_encode(["status"=>"error", "mensaje"=>$e->getMessage()]); }
        exit;
    }
    if ($action === 'login') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$input['email_user'], $input['email_user']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($input['password'], $u['password_hash'])) {
            session_regenerate_id(true); // Security: Prevent Session Fixation
            $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['username']; $_SESSION['company'] = $u['company_name']; 
            $_SESSION['role'] = $u['role']; // CRITICAL: PRESERVED ROLE
            echo json_encode(["status"=>"success"]);
        } else echo json_encode(["status"=>"error", "mensaje"=>"Credenciales inválidas"]);
        exit;
    }
    if ($action === 'logout') { session_destroy(); echo json_encode(["status"=>"success"]); exit; }
    if ($action === 'comprar') {
        if (!isset($_SESSION['user_id'])) exit(json_encode(["status"=>"auth_required"]));
        $plan = htmlspecialchars($input['plan']); $s = $input['specs']; 
        try {
            // -- FIX: Obtener ID dinámico del plan --
            $stmtP = $conn->prepare("SELECT id FROM plans WHERE name = ?");
            $stmtP->execute([$plan]);
            $plan_id = $stmtP->fetchColumn();

            // Lógica de fallback
            if (!$plan_id) {
                if ($plan === 'Personalizado') $plan_id = 4; // Intento de ID para Personalizado
                else $plan_id = 1; // Fallback general a Bronce
            }

            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (?, ?, 'pending')");
            $stmt->execute([$_SESSION['user_id'], $plan_id]);
            $oid = $conn->lastInsertId();
            
            $tools_str = json_encode($s['tools'] ?? []);

            $sqlS = "INSERT INTO order_specs (order_id, cpu_cores, ram_gb, storage_gb, db_enabled, db_type, web_enabled, web_type, cluster_alias, subdomain, ssh_user, os_image, db_custom_name, web_custom_name, tools) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtS = $conn->prepare($sqlS);
            $stmtS->execute([$oid, $s['cpu'], $s['ram'], $s['storage'], $s['db_enabled']?1:0, $s['db_type'], $s['web_enabled']?1:0, $s['web_type'], $s['cluster_alias'], $s['subdomain'], $s['ssh_user'], $s['os_image'], $s['db_custom_name'], $s['web_custom_name'], $tools_str]);
            
            $payload = ["id_cliente" => (int)$oid, "plan" => $plan, "cliente_nombre" => $_SESSION['username'], "specs" => $s, "id_usuario_real" => (string)$_SESSION['user_id']];
            $ch = curl_init(API_URL . "/crear");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_exec($ch); curl_close($ch);
            echo json_encode(["status"=>"success", "order_id"=>$oid]);
        } catch (Exception $e) { echo json_encode(["status"=>"error", "mensaje"=>$e->getMessage()]); }
        exit;
    }
}
?>
