<?php
// logic: sylo-web/public/php/auth.php
// backend logic for Landing/Login

// --- SECURITY CONFIG ---
ini_set('display_errors', 0); // 🛡️ Disable error display in production to prevent path leakage
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 🛡️ Secure Session Cookies (HttpOnly + Secure)
// Ensure this is set BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // Default current domain
        'secure' => true, // 🛡️ Require HTTPS
        'httponly' => true, // 🛡️ Prevent JS access to session cookie (XSS protection)
        'samesite' => 'Strict' // 🛡️ CSRF mitigation
    ]);
    session_start();
}
require_once __DIR__ . '/csrf.php';

define('API_URL', 'http://172.17.0.1:8001/api/clientes');

// --- 1. DB ---
$db_host = getenv('DB_HOST') ?: "kylo-main-db";
$db_user = getenv('DB_USER') ?: "sylo_app";
$db_pass = getenv('DB_PASS') ?: "sylo_app_pass";
$db_name = getenv('DB_NAME') ?: "sylo_admin_db";

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass); // Added charset
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // 🏗️ Default fetch mode
} catch(PDOException $e) {
    error_log("DB Error: " . $e->getMessage()); // 🛡️ Log to system logs, not file
    die(json_encode(["status" => "error", "mensaje" => "Error interno del servidor"])); // 🛡️ Generic error for user
}

// --- CHECK USER ---
$has_clusters = false;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM k8s_deployments WHERE user_id = ? AND status IN ('active', 'suspended', 'creating', 'pending')");
        $stmt->execute([$_SESSION['user_id']]);
        if ($stmt->fetchColumn() > 0) $has_clusters = true;
    } catch(Exception $e) {}
}

// --- 2. STATUS CHECK (DIRECT READ FIX) ---
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    $id = filter_var($_GET['check_status'], FILTER_VALIDATE_INT);
    
    // Ruta directa al buzón (Mejor rendimiento y fiabilidad que cURL loopback)
    $buzon_dir = __DIR__ . "/../../buzon-pedidos/";
    $status_file = $buzon_dir . "status_{$id}.json";
    
    if (file_exists($status_file)) {
        // Leer directamente del disco
        echo file_get_contents($status_file);
    } else {
        // Fallback: Si no existe, simulamos 'pending'
        echo json_encode(["percent" => 10, "message" => "Conectando al Núcleo...", "status" => "pending"]);
    }
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
            if ($input['password'] !== $input['password_confirm']) throw new Exception("Las contraseñas no coinciden");
            if (strlen($input['password']) < 6) throw new Exception("La contraseña debe tener al menos 6 caracteres");
            if (!preg_match('/[0-9]/', $input['password'])) throw new Exception("La contraseña debe incluir al menos un número");
            if (!preg_match('/[\W_]/', $input['password'])) throw new Exception("La contraseña debe incluir al menos un carácter especial");
            // 🛡️ Store RAW, Sanitize on OUTPUT. Prevents double-encoding and database corruption.
            $user = $input['username']; // Removed htmlspecialchars
            $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
            if (!$email || !checkdnsrr(explode('@', $email)[1], 'MX')) {
                 if (!$email || strpos($email, '.') === false) throw new Exception("Formato de email inválido");
            }
            // Typo Check
            $domain = strtolower(explode('@', $email)[1]);
            $typos = ['gmail.co', 'hotmail.co', 'yahoo.co', 'outlook.co', 'gmil.com', 'hotmil.com', 'gm.com'];
            if (in_array($domain, $typos)) throw new Exception("Parece que hay un error en el dominio del email ($domain). ¿Quiso decir .com?");
            
            // DNI Validation (Autonomo only)
            if ($input['tipo_usuario'] === 'autonomo') {
                $dni = strtoupper($input['dni']);
                if (!preg_match('/^[XYZ0-9][0-9]{7}[TRWAGMYFPDXBNJZSQVHLCKE]$/', $dni)) {
                    throw new Exception("Formato DNI/NIE incorrecto. (Ej: 12345678Z)");
                }
                $num = substr($dni, 0, 8);
                $num = str_replace(['X', 'Y', 'Z'], ['0', '1', '2'], $num);
                $letter = substr($dni, 8, 1);
                $letters = "TRWAGMYFPDXBNJZSQVHLCKE";
                if ($letter !== $letters[(int)$num % 23]) {
                    throw new Exception("Letra DNI/NIE incorrecta.");
                }
            }
            $pass = password_hash($input['password'], PASSWORD_BCRYPT);
            $tipo = $input['tipo_usuario'];
            $fn = ($tipo === 'autonomo') ? $input['full_name'] : $input['contact_name'];
            $dn = ($tipo === 'autonomo') ? $input['dni'] : $input['cif'];
            $cn = ($tipo === 'empresa') ? $input['company_name'] : null;
            $te = ($tipo === 'empresa') ? $input['tipo_empresa'] : null;
            // Map inputs to new schema: dni -> documento_identidad, calle -> direccion
            $sql = "INSERT INTO users (username, full_name, email, password_hash, role, tipo_usuario, documento_identidad, telefono, company_name, tipo_empresa, direccion, created_at) VALUES (?, ?, ?, ?, 'client', ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user, $fn, $email, $pass, $tipo, $dn, $input['telefono'], $cn, $te, $input['calle']]);
            $_SESSION['user_id'] = $conn->lastInsertId(); $_SESSION['username'] = $user; $_SESSION['company'] = $cn ?: 'Particular';
            echo json_encode(["status"=>"success"]);
        } catch (Exception $e) { 
            error_log("Register Error: " . $e->getMessage());
            echo json_encode(["status"=>"error", "mensaje"=>"Error al registrar. Verifique sus datos o intente más tarde."]); 
        }
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
        if (!isset($_SESSION['user_id'])) exit(json_encode(["status"=>"auth_required", "mensaje"=>"Sesión expirada. Por favor, loguéate de nuevo."]));
        $plan = $input['plan']; // 🛡️ Removed htmlspecialchars (Input Validation only)
        $s = $input['specs']; 
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

            // Insert into k8s_deployments (Unified Table)
            $sql = "INSERT INTO k8s_deployments 
                (user_id, plan_id, status, cluster_alias, subdomain, os_image, cpu_cores, ram_gb, storage_gb, 
                 web_enabled, web_type, web_custom_name, db_enabled, db_type, db_custom_name, ssh_user, created_at) 
                VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                $_SESSION['user_id'], 
                $plan_id,
                $s['cluster_alias'],
                $s['subdomain'] . '-' . rand(1000, 9999), // Force unique subdomain
                $s['os_image'],
                $s['cpu'],
                $s['ram'],
                $s['storage'],
                $s['web_enabled'] ? 1 : 0,
                $s['web_type'],
                $s['web_custom_name'],
                $s['db_enabled'] ? 1 : 0,
                $s['db_type'],
                $s['db_custom_name'],
                $s['ssh_user']
            ]);
            
            $oid = $conn->lastInsertId();
            
            // Handle Tools (Insert into k8s_tools)
            if (!empty($s['tools']) && is_array($s['tools'])) {
                $stmtTools = $conn->prepare("INSERT INTO k8s_tools (deployment_id, tool_name) VALUES (?, ?)");
                foreach ($s['tools'] as $tool) {
                    $stmtTools->execute([$oid, $tool]);
                }
            }
            
            $payload = ["id_cliente" => (int)$oid, "plan" => $plan, "cliente_nombre" => $_SESSION['username'], "specs" => $s, "id_usuario_real" => (string)$_SESSION['user_id']];
            $ch = curl_init(API_URL . "/crear");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_exec($ch); curl_close($ch);
            echo json_encode(["status"=>"success", "order_id"=>$oid]);
        } catch (Exception $e) { 
            error_log("Order Error: " . $e->getMessage());
            echo json_encode(["status"=>"error", "mensaje"=>"Error procesando el pedido. Contacte con soporte."]); 
        }
        exit;
    }
}
?>
