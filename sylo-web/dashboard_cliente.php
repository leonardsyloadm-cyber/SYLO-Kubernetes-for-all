<?php
session_start();

// --- 0. LOGOUT ---
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// --- 1. SEGURIDAD ---
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("Error DB"); }

$buzon_path = "/buzon"; 
if (!is_dir($buzon_path) && is_dir("../buzon-pedidos")) { $buzon_path = "../buzon-pedidos"; }

// --- 2. GESTIÓN PERFIL ---
$user_id = $_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $sql = "UPDATE users SET full_name=?, email=?, dni=?, telefono=?, company_name=?, calle=? WHERE id=?";
    $conn->prepare($sql)->execute([$_POST['full_name'], $_POST['email'], $_POST['dni'], $_POST['telefono'], $_POST['company_name'], $_POST['calle'], $user_id]);
    header("Location: dashboard_cliente.php"); exit;
}
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);

// --- 3. HELPERS VISUALES ---
function getPlanStyle($planName) {
    return match($planName) {
        'Bronce' => 'background: #CD7F32; color: #fff; box-shadow: 0 0 10px rgba(205, 127, 50, 0.4);',
        'Plata'  => 'background: #94a3b8; color: #fff; box-shadow: 0 0 10px rgba(148, 163, 184, 0.4);',
        'Oro'    => 'background: #FFD700; color: #000; font-weight:bold; box-shadow: 0 0 15px rgba(255, 215, 0, 0.6);',
        'Personalizado' => 'background: linear-gradient(45deg, #CD7F32, #94a3b8, #FFD700); color: #fff; font-weight:bold; border:1px solid rgba(255,255,255,0.3);',
        default  => 'background: #334155; color: #fff;'
    };
}

function getSidebarStyle($planName) {
    $color = match($planName) {
        'Bronce' => '#CD7F32',
        'Plata'  => '#94a3b8',
        'Oro'    => '#FFD700',
        'Personalizado' => '#a855f7', 
        default  => '#3b82f6'
    };
    return "border-left: 3px solid $color; background: linear-gradient(90deg, " . $color . "11, transparent);";
}

// --- 4. HELPERS LÓGICOS ---
function calculateWeeklyPrice($row) {
    $price = match($row['plan_name']) { 'Bronce'=>5, 'Plata'=>15, 'Oro'=>30, default=>0 };
    if($row['plan_name'] == 'Personalizado') {
        $cpu = isset($row['custom_cpu']) ? intval($row['custom_cpu']) : 0;
        $ram = isset($row['custom_ram']) ? intval($row['custom_ram']) : 0;
        $price = ($cpu * 5) + ($ram * 5);
        if(!empty($row['db_enabled'])) $price += 10;
        if(!empty($row['web_enabled'])) $price += 10;
    }
    return $price / 4;
}

function getBackupLimit($row) {
    $monthly = match($row['plan_name']) { 'Bronce'=>5, 'Plata'=>15, 'Oro'=>30, default=>0 };
    if($row['plan_name'] == 'Personalizado') {
        $monthly = (intval($row['custom_cpu']) * 5) + (intval($row['custom_ram']) * 5);
        if(!empty($row['db_enabled'])) $monthly += 10;
        if(!empty($row['web_enabled'])) $monthly += 10;
    }
    if ($row['plan_name'] == 'Oro' || $monthly >= 30) return 5;
    if ($row['plan_name'] == 'Plata' || $monthly >= 15) return 3;
    return 2; 
}

function cleanPass($raw) {
    if (strpos($raw, 'Pass:') !== false) {
        if(preg_match('/Pass:\s*([^\s\[\]]+)/', $raw, $matches)) return $matches[1];
    }
    return $raw;
}

// --- 5. API AJAX ---
if (isset($_GET['ajax_data']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $oid = $_GET['id'];
    
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id=?");
    $stmt->execute([$oid]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$st || $st['status'] == 'cancelled') { echo json_encode(['terminated' => true]); exit; }
    
    $json = @file_get_contents("$buzon_path/status_$oid.json");
    $web_status = @file_get_contents("$buzon_path/web_status_$oid.json");
    
    $backups_list = [];
    $list_file = "$buzon_path/backups_list_$oid.json";
    if(file_exists($list_file)) {
        $decoded = json_decode(file_get_contents($list_file), true);
        if(is_array($decoded)) $backups_list = $decoded;
    }
    
    $backup_status = null;
    $prog_file = "$buzon_path/backup_status_$oid.json";
    if(file_exists($prog_file)) $backup_status = json_decode(file_get_contents($prog_file), true);

    // [IA] LEER RESPUESTA
    $chat_reply = null;
    $chat_file = "$buzon_path/chat_response_$oid.json";
    if(file_exists($chat_file)) {
        $chat_data = json_decode(file_get_contents($chat_file), true);
        if($chat_data && isset($chat_data['reply'])) {
            $chat_reply = $chat_data['reply'];
            @unlink($chat_file);
        }
    }

    // [NUEVO] LEER ESTADO REAL DE LA IA
    $chat_status = null;
    $status_file = "$buzon_path/chat_status_$oid.json";
    if(file_exists($status_file)) {
        $status_data = json_decode(file_get_contents($status_file), true);
        if ($status_data && isset($status_data['status'])) {
            $chat_status = $status_data['status'];
        }
    }

    $data = json_decode($json, true) ?? [];
    $clean_pass = isset($data['ssh_pass']) ? cleanPass($data['ssh_pass']) : '...';

    echo json_encode([
        'metrics' => $data['metrics'] ?? ['cpu' => 0, 'ram' => 0],
        'ssh_cmd' => $data['ssh_cmd'] ?? 'Conectando...',
        'ssh_pass' => $clean_pass,
        'web_url' => $data['web_url'] ?? null,
        'backups_list' => $backups_list,
        'backup_progress' => $backup_status,
        'web_progress' => json_decode($web_status, true),
        'chat_reply' => $chat_reply,
        'chat_status' => $chat_status // ENVIAMOS EL ESTADO REAL
    ]);
    exit;
}

// --- 6. PROCESAR ACCIONES ---
$is_ajax = isset($_GET['ajax_action']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] != 'update_profile') {
    $oid = $_POST['order_id'];
    $act = $_POST['action'];
    $data = ["id" => (int)$oid, "action" => strtoupper($act), "user" => $_SESSION['username']];
    
    // [IA] ENVIAR CHAT CON CONTEXTO
    if($act == 'send_chat') {
        $msg = $_POST['message'];
        $chat_req_file = "$buzon_path/chat_request_{$oid}.json";
        
        $sql_plan = "SELECT p.name as plan_name, os.db_enabled, os.web_enabled, os.db_type, os.web_type FROM orders o JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE o.id = ?";
        $stmt_chat = $conn->prepare($sql_plan);
        $stmt_chat->execute([$oid]);
        $plan_info = $stmt_chat->fetch(PDO::FETCH_ASSOC);
        
        $payload = [
            "msg" => $msg, "timestamp" => time(),
            "context_plan" => [
                "name" => $plan_info['plan_name'] ?? 'Estándar',
                "has_db" => ($plan_info['plan_name'] == 'Oro' || $plan_info['plan_name'] == 'Plata' || !empty($plan_info['db_enabled'])),
                "has_web" => ($plan_info['plan_name'] == 'Oro' || !empty($plan_info['web_enabled'])),
                "db_type" => $plan_info['db_type'] ?? 'MySQL',
                "web_type" => $plan_info['web_type'] ?? 'Apache'
            ]
        ];
        file_put_contents($chat_req_file, json_encode($payload));
        @chmod($chat_req_file, 0666);
        if($is_ajax) { echo json_encode(['status'=>'ok']); exit; }
    }

    if($act == 'backup') {
        $data['backup_type'] = $_POST['backup_type'] ?? 'full';
        $data['backup_name'] = $_POST['backup_name'] ?? 'Manual';
    }
    
    if($act == 'delete_backup') {
        $data['filename_to_delete'] = $_POST['filename'];
    }

    if($act == 'update_web' || ($act == 'upload_web' && isset($_FILES['html_file']))) {
        $html_content = ($act == 'upload_web') ? file_get_contents($_FILES['html_file']['tmp_name']) : $_POST['html_content'];
        $source_file = "$buzon_path/web_source_{$oid}.html";
        file_put_contents($source_file, $html_content);
        @chmod($source_file, 0666);
        if($act == 'upload_web') { 
            $data['action'] = "UPDATE_WEB"; 
            $data['html_content'] = $html_content; 
            $act = "update_web"; 
        } else { 
            $data['html_content'] = $html_content; 
        }
    }
    
    if ($act != 'send_chat') {
        $fname = match($act) { 'terminate'=>'terminate', 'backup'=>'backup', 'update_web'=>'update_web', 'delete_backup'=>'delete_backup', 'refresh_status'=>'refresh', default=>$act };
        $timestamp = microtime(true);
        file_put_contents("$buzon_path/accion_{$oid}_{$fname}_{$timestamp}.json", json_encode($data));
        @chmod("$buzon_path/accion_{$oid}_{$fname}_{$timestamp}.json", 0666);
        
        if($act == 'update_web') @unlink("$buzon_path/web_status_{$oid}.json");
    }
    
    if($is_ajax) { 
        header('Content-Type: application/json'); 
        echo json_encode(['status'=>'ok']); 
        exit; 
    }
    
    header("Location: dashboard_cliente.php?id=$oid"); exit;
}

// --- 7. CARGA INICIAL ---
$sql = "SELECT o.*, p.name as plan_name, p.cpu_cores as p_cpu, p.ram_gb as p_ram, os.cpu_cores as custom_cpu, os.ram_gb as custom_ram, os.db_enabled, os.web_enabled FROM orders o JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE user_id=? AND status!='cancelled' ORDER BY o.id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = null; $creds = ['ssh_cmd'=>'Esperando...', 'ssh_pass'=>'...'];
$web_url = null; $has_web = false; $has_db = false; $total_weekly = 0; $plan_cpus = 1; $backup_limit = 2;

foreach($clusters as $c) $total_weekly += calculateWeeklyPrice($c);

if($clusters) {
    $current = (isset($_GET['id'])) ? array_values(array_filter($clusters, fn($c)=>$c['id']==$_GET['id']))[0] ?? $clusters[0] : $clusters[0];
    if ($current['plan_name'] == 'Personalizado') $plan_cpus = intval($current['custom_cpu'] ?? 1); else $plan_cpus = intval($current['p_cpu'] ?? 1);
    if ($plan_cpus < 1) $plan_cpus = 1;
    $backup_limit = getBackupLimit($current);
    if ($current['plan_name'] === 'Oro' || (!empty($current['web_enabled']) && $current['web_enabled'] == 1)) { $has_web = true; }
    if ($current['plan_name'] === 'Oro' || (!empty($current['db_enabled']) && $current['db_enabled'] == 1)) { $has_db = true; }
    
    $file_path = "$buzon_path/status_{$current['id']}.json";
    if (file_exists($file_path)) {
        $status_data = json_decode(file_get_contents($file_path), true);
        if(is_array($status_data)) {
            $creds = array_merge($creds, $status_data);
            if(isset($creds['ssh_pass'])) $creds['ssh_pass'] = cleanPass($creds['ssh_pass']);
            if(isset($status_data['web_url'])) $web_url = $status_data['web_url'];
        }
    }
    $src_file = "$buzon_path/web_source_{$current['id']}.html";
    $html_code = file_exists($src_file) ? file_get_contents($src_file) : "<!DOCTYPE html>\n<html>\n<body>\n<h1>Bienvenido a Sylo</h1>\n</body>\n</html>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Panel SYLO | <?php echo htmlspecialchars($user_info['full_name'] ?: $_SESSION['username']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js"></script>
    <style>
        :root { --bg-dark: #020617; --bg-card: #0f172a; --text-main: #f8fafc; --text-light: #e2e8f0; --text-muted: #cbd5e1; --accent: #3b82f6; --accent-glow: rgba(59, 130, 246, 0.5); --border: #1e293b; --sidebar: #0f172a; }
        body { font-family: 'Outfit', sans-serif; background-color: var(--bg-dark); color: var(--text-main); overflow-x: hidden; }
        
        .sidebar { height: 100vh; background: var(--sidebar); border-right: 1px solid var(--border); padding-top: 25px; position: fixed; width: 260px; z-index: 1000; display: flex; flex-direction: column; }
        .sidebar .brand { font-size: 1.5rem; color: #fff; padding-left: 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; letter-spacing: 1px; text-shadow: 0 0 10px var(--accent-glow); }
        .sidebar .nav-link { color: var(--text-muted); padding: 12px 24px; margin: 4px 16px; border-radius: 8px; transition: all 0.2s; font-weight: 500; display: flex; align-items: center; text-decoration: none; border: 1px solid transparent; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.05); transform: translateX(5px); }
        .sidebar .nav-link.active { border-radius: 8px; color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        
        .main-content { margin-left: 260px; padding: 30px 40px; }
        .card-clean { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 1.5rem; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); position: relative; overflow: hidden; }
        
        .metric-card { background: #1e293b; border-radius: 16px; padding: 20px; border: 1px solid var(--border); position: relative; overflow: hidden; transition: transform 0.2s; }
        .metric-card:hover { transform: translateY(-2px); border-color: var(--accent); }
        .metric-value { font-size: 2.5rem; font-weight: 700; line-height: 1; letter-spacing: -1px; }
        .progress-thin { height: 6px; border-radius: 10px; background: #334155; margin-top: 15px; overflow: hidden; }
        .progress-bar { border-radius: 10px; transition: width 0.6s ease; height: 100%; box-shadow: 0 0 10px currentColor; }
        
        .terminal-container { background: #000; border: 1px solid #334155; border-radius: 8px; padding: 15px; font-family: 'Fira Code', monospace; font-size: 0.85rem; color: #4ade80; }
        .term-label { color: #94a3b8; font-weight: bold; margin-right: 10px; }
        .term-val { color: #e2e8f0; }
        
        .btn-action { width: 100%; text-align: left; margin-bottom: 10px; padding: 12px 16px; font-size: 0.9rem; border-radius: 8px; display: flex; align-items: center; border: 1px solid var(--border); background: #1e293b; color: #cbd5e1; transition: all 0.2s; }
        .btn-action:hover { background: #334155; color: #fff; border-color: var(--accent); }
        .btn-primary { background-color: var(--accent); border: none; box-shadow: 0 0 15px var(--accent-glow); }
        .btn-primary:hover { background-color: #2563eb; }
        
        .chat-widget { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background: var(--accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; box-shadow: 0 0 20px var(--accent-glow); cursor: pointer; transition: transform 0.3s; z-index: 9999; }
        .chat-widget:hover { transform: scale(1.1); }
        .chat-window { position: fixed; bottom: 100px; right: 30px; width: 320px; height: 400px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; display: none; flex-direction: column; z-index: 9999; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .chat-header { padding: 15px; background: #1e293b; border-bottom: 1px solid var(--border); border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .chat-body { flex: 1; padding: 15px; overflow-y: auto; font-size: 0.9rem; color: #cbd5e1; }
        .chat-input-area { padding: 10px; border-top: 1px solid var(--border); display: flex; gap: 5px; }
        .chat-msg { background: #334155; padding: 8px 12px; border-radius: 10px; margin-bottom: 8px; max-width: 80%; }
        .chat-msg.support { background: #1e293b; color: var(--accent); align-self: flex-start; }
        .chat-msg.me { background: var(--accent); color: white; align-self: flex-end; margin-left: auto; }

        /* NUEVO: ESTILOS PARA EL GLOBO DE "PENSANDO" */
        @keyframes pulse-text { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
        .thinking-bubble { font-style: italic; color: #94a3b8; background: rgba(30, 41, 59, 0.5) !important; border: 1px dashed #334155 !important; animation: pulse-text 1.5s infinite; display: flex; align-items: center; gap: 10px; }

        .modal-content { background-color: #1e293b; border: 1px solid #334155; color: white; }
        .modal-header, .modal-footer { border-color: #334155; }
        .btn-close { filter: invert(1); }
        .form-control, .form-select { background-color: #0f172a; border-color: #334155; color: white; }
        .form-control:focus { background-color: #1e293b; border-color: var(--accent); color: white; }
        .text-light-muted { color: #cbd5e1 !important; }
        .backup-option { border: 1px solid #334155; padding: 15px; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: #1e293b; color: #cbd5e1; }
        .backup-option:hover { border-color: var(--accent); background: #24304a; }
        .backup-option input[type="radio"]:checked + div { color: var(--accent); font-weight: bold; }
        
        #editor { width: 100%; height: 500px; font-size: 14px; border-radius: 0 0 8px 8px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .spin { animation: spin 1s linear infinite; display: inline-block; vertical-align: middle; }
        
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 10000; }
        .custom-toast { background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(10px); border-left: 4px solid var(--accent); color: white; padding: 15px 20px; margin-bottom: 10px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: slideIn 0.3s ease-out; min-width: 300px; display: flex; align-items: center; gap: 12px; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .log-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .log-table td { padding: 10px; border-bottom: 1px solid #334155; color: #e2e8f0; }
        .log-table tr:hover td { color: white; background: rgba(255,255,255,0.05); }
    </style>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<div class="sidebar">
    <div class="brand"><i class="bi bi-cpu-fill text-primary me-2"></i><strong>SYLO</strong>_OS</div>
    <div class="d-flex flex-column gap-1 p-2">
        <a href="index.php" class="nav-link"><i class="bi bi-plus-lg me-3"></i> Nuevo Servicio</a>
        <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#billingModal"><i class="bi bi-credit-card me-3"></i> Facturación</a>
        <div class="mt-4 px-4 mb-2 text-light-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 1px; opacity: 0.6;">MIS CLÚSTERES</div>
        <?php foreach($clusters as $c): 
            $cls = ($current && $c['id']==$current['id'])?'active':'';
            $pstyle = getSidebarStyle($c['plan_name']); 
        ?>
            <a href="?id=<?=$c['id']?>" class="nav-link <?=$cls?>" style="<?=$cls ? $pstyle : ''?>">
                <i class="bi bi-hdd-rack me-3"></i> <span>ID: <?=$c['id']?> (<?=$c['plan_name']?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:auto; padding:20px; border-top:1px solid #1e293b;">
        <a href="?action=logout" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-power me-2"></i> Desconectar</a>
    </div>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h3 class="fw-bold mb-0 text-white">Panel de Control</h3>
            <small class="text-light-muted">Bienvenido, <?=htmlspecialchars($user_info['username'])?></small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-dark border border-secondary position-relative" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-person-circle"></i>
            </button>
        </div>
    </div>

    <?php if (!$current): ?>
        <div class="text-center py-5"><i class="bi bi-cloud-slash display-1 text-muted opacity-25"></i><h3 class="mt-3 text-muted">Sin servicios activos</h3><a href="index.php" class="btn btn-primary mt-2">Desplegar Infraestructura</a></div>
    <?php else: ?>
    
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex align-items-center mb-4 gap-3">
                <h4 class="fw-bold mb-0 text-white">Servidor #<?=$current['id']?></h4>
                <div><span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2 rounded-pill">ONLINE</span></div>
                <div><span class="badge px-3 py-2 rounded-pill" style="<?=getPlanStyle($current['plan_name'])?>">PLAN <?=strtoupper($current['plan_name'])?></span></div>
            </div>
            
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="metric-card">
                        <div class="d-flex justify-content-between"><span class="text-light-muted text-uppercase small fw-bold">Uso CPU</span><i class="bi bi-cpu text-primary"></i></div>
                        <div class="metric-value text-white mt-2"><span id="cpu-val">0</span>%</div>
                        <div class="progress-thin"><div id="cpu-bar" class="progress-bar bg-primary" style="width:0%"></div></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="metric-card">
                        <div class="d-flex justify-content-between"><span class="text-light-muted text-uppercase small fw-bold">Memoria RAM</span><i class="bi bi-memory text-success"></i></div>
                        <div class="metric-value text-white mt-2"><span id="ram-val">0</span>%</div>
                        <div class="progress-thin"><div id="ram-bar" class="progress-bar bg-success" style="width:0%"></div></div>
                    </div>
                </div>
            </div>
            
            <div class="card-clean">
                <div class="d-flex justify-content-between mb-3 align-items-center">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-terminal me-2 text-warning"></i>Accesos de Sistema</h6>
                    <button onclick="copyAllCreds()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-copy me-1"></i> Copiar</button>
                </div>
                <div class="terminal-container" id="all-creds-box">
                    <?php if($has_web): ?><div><span class="term-label">WEB:</span> <a href="<?=htmlspecialchars($web_url)?>" target="_blank" class="term-val text-decoration-none hover-white" id="disp-web-url"><?=htmlspecialchars($web_url)?></a></div><?php endif; ?>
                    <?php if($has_db): ?>
                        <div class="mt-2 text-light-muted small">// DATABASE CLUSTER</div>
                        <div><span class="term-label">MASTER:</span> <span class="term-val">mysql-master-0 (Write)</span></div>
                        <div><span class="term-label">SLAVE:</span>  <span class="term-val">mysql-slave-0 (Read)</span></div>
                    <?php endif; ?>
                    <div class="mt-2 text-light-muted small">// SSH ROOT ACCESS</div>
                    <div><span class="term-label">CMD:</span>  <span class="term-val text-success" id="disp-ssh-cmd"><?=htmlspecialchars($creds['ssh_cmd'] ?? 'Connecting...')?></span></div>
                    <div><span class="term-label">PASS:</span> <span class="term-val text-warning" id="disp-ssh-pass"><?=htmlspecialchars($creds['ssh_pass'] ?? 'sylo1234')?></span></div>
                </div>
            </div>

            <div class="card-clean mt-4">
                <h6 class="fw-bold mb-3 text-white"><i class="bi bi-clock-history me-2 text-info"></i>Historial de Actividad (Sesión)</h6>
                <table class="log-table">
                    <tbody id="activity-log-body">
                        <tr><td class="text-light-muted text-center">Esperando eventos...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card-clean">
                <div class="d-flex justify-content-between mb-3 align-items-center">
                    <h6 class="fw-bold m-0 text-white">Centro de Mando</h6>
                    <button onclick="manualRefresh()" id="btn-refresh" class="btn btn-sm btn-dark border border-secondary text-light-muted"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                
                <?php if($has_web): ?>
                    <label class="small text-light-muted mb-2 d-block">Despliegue Web</label>
                    <a href="#" target="_blank" id="btn-ver-web" class="btn btn-primary w-100 mb-2 fw-bold position-relative overflow-hidden <?=$web_url?'':'d-none'?>">
                        <div id="web-loader-fill" style="position:absolute;top:0;left:0;height:100%;width:0%;background:rgba(255,255,255,0.2);transition:width 0.5s;"></div>
                        <span id="web-btn-text"><i class="bi bi-box-arrow-up-right me-2"></i>Ver Sitio Web</span>
                    </a>
                    <div class="d-flex gap-2 mb-4">
                        <button class="btn btn-dark border border-secondary flex-fill text-light-muted" onclick="showEditor()"><i class="bi bi-code-slash"></i> Editar</button>
                        <button class="btn btn-dark border border-secondary flex-fill text-light-muted" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload"></i> Subir</button>
                    </div>
                <?php endif; ?>

                <label class="small text-light-muted mb-2 d-block">Control de Energía</label>
                <form method="POST" id="energyForm">
                    <input type="hidden" name="order_id" value="<?=$current['id']?>">
                    <?php if($current['status']=='active'): ?>
                        <button type="submit" name="action" value="restart" class="btn-action" onclick="showToast('Reiniciando sistema...', 'info')"><i class="bi bi-arrow-repeat text-warning me-3 fs-5"></i><div><div class="fw-bold text-white">Reiniciar</div><small>Aplicar cambios</small></div></button>
                        <button type="submit" name="action" value="stop" class="btn-action" onclick="showToast('Deteniendo sistema...', 'warning')"><i class="bi bi-stop-circle text-danger me-3 fs-5"></i><div><div class="fw-bold text-white">Apagar</div><small>Modo hibernación</small></div></button>
                    <?php else: ?>
                        <button type="submit" name="action" value="start" class="btn-action" onclick="showToast('Iniciando sistema...', 'success')"><i class="bi bi-play-circle text-success me-3 fs-5"></i><div><div class="fw-bold text-white">Encender</div><small>Volver a online</small></div></button>
                    <?php endif; ?>
                </form>
                
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="small text-light-muted">Snapshots</label>
                        <span class="badge bg-dark border border-secondary" id="backup-count">0/<?=$backup_limit?></span>
                    </div>
                    <button class="btn-action justify-content-center text-center py-2" onclick="showBackupModal()"><i class="bi bi-camera me-2"></i>Crear Snapshot</button>
                    
                    <div id="backup-ui" style="display:none" class="mt-3"><div class="progress" style="height:4px"><div id="backup-bar" class="progress-bar bg-info" style="width:0%"></div></div><small class="text-info d-block mt-1">Creando backup...</small></div>
                    
                    <div id="delete-ui" style="display:none" class="mt-3"><div class="progress" style="height:4px"><div id="delete-bar" class="progress-bar bg-danger" style="width:0%"></div></div><small class="text-danger d-block mt-1">Eliminando...</small></div>

                    <div id="backups-list-container" class="mt-3"></div>
                </div>
                
                <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 text-center">
                    <button class="btn btn-link text-danger btn-sm text-decoration-none opacity-50 hover-opacity-100" onclick="confirmTerminate()">Eliminar Servicio</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="chat-widget" onclick="toggleChat()"><i class="bi bi-chat-fill"></i></div>
<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <div class="d-flex align-items-center gap-2"><div style="width:10px;height:10px;background:#10b981;border-radius:50%"></div><span class="fw-bold text-white">Soporte Sylo</span></div>
        <i class="bi bi-x-lg cursor-pointer" onclick="toggleChat()"></i>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="chat-msg support">
            Hola <?=htmlspecialchars($_SESSION['username'])?>, si tienes preguntas, estas son las más frecuentes:
            <br><br>
            1️⃣ ¿Cómo entro a mi servidor? (SSH)<br>
            2️⃣ ¿Cuál es mi página web?<br>
            3️⃣ Datos de Base de Datos<br>
            4️⃣ ¿Cuántas copias puedo hacer?<br>
            5️⃣ Estado de Salud (CPU/RAM)<br><br>
            Escribe el número para ver la respuesta.
        </div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="chatInput" class="form-control bg-dark border-secondary text-white" placeholder="Escribe..." onkeypress="handleChat(event)">
        <button class="btn btn-primary btn-sm" onclick="sendChat()"><i class="bi bi-send"></i></button>
    </div>
</div>

<div class="modal fade" id="backupTypeModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-hdd-fill me-2"></i>Nueva Snapshot</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="form-label small fw-bold text-light-muted">Nombre de la copia</label><input type="text" id="backup_name_input" class="form-control" placeholder="Ej: Antes de cambios..." maxlength="20"></div><div class="d-flex flex-column gap-2"><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="full" checked class="form-check-input mt-0"><div><div class="fw-bold text-white">Completa (Full)</div><div class="small text-muted" style="font-size:0.75rem">Copia total del disco.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="diff" class="form-check-input mt-0"><div><div class="fw-bold text-white">Diferencial</div><div class="small text-muted" style="font-size:0.75rem">Cambios desde última Full.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="incr" class="form-check-input mt-0"><div><div class="fw-bold text-white">Incremental</div><div class="small text-muted" style="font-size:0.75rem">Solo lo nuevo hoy.</div></div></label></div><div id="limit-alert" class="alert alert-danger small mt-3 mb-0" style="display:none"><i class="bi bi-exclamation-octagon-fill me-1"></i> <strong>Límite alcanzado.</strong><br>Elimina una copia para continuar.</div><div id="normal-alert" class="alert alert-warning small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i> Límite: <strong><?=$backup_limit?> copias</strong>.</div></div><div class="modal-footer border-0 px-4 pb-4"><button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button><button type="button" id="btn-start-backup" onclick="doBackup()" class="btn btn-primary rounded-pill px-4 fw-bold">Iniciar Copia</button></div></div></div></div>
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header bg-dark text-white border-bottom border-secondary"><h5 class="modal-title">Editor HTML</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div id="editor"></div></div><div class="modal-footer bg-dark border-top border-secondary"><button class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-primary rounded-pill fw-bold" onclick="saveWeb()"><i class="bi bi-save me-2"></i>Publicar</button></div></div></div></div>
<div class="modal fade" id="uploadModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0"><h5 class="modal-title">Subir Web</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="uploadForm" enctype="multipart/form-data"><input type="file" id="htmlFile" name="html_file" class="form-control mb-3" required><button type="submit" class="btn btn-success w-100 rounded-pill">Subir</button></form></div></div></div></div>
<div class="modal fade" id="profileModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Perfil</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><input type="hidden" name="action" value="update_profile"><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="small text-light-muted">Nombre</label><input type="text" name="full_name" class="form-control" value="<?=htmlspecialchars($user_info['full_name']??'')?>"></div><div class="mb-3"><label class="small text-light-muted">Email</label><input type="email" name="email" class="form-control" value="<?=htmlspecialchars($user_info['email']??'')?>" required></div><button type="submit" class="btn btn-primary w-100 rounded-pill">Guardar</button></div></form></div></div></div>
<div class="modal fade" id="billingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0"><h5 class="modal-title">Facturación</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php foreach($clusters as $c): ?><div class="d-flex justify-content-between mb-2"><span>#<?=$c['id']?> <?=$c['plan_name']?></span><span class="text-success"><?=number_format(calculateWeeklyPrice($c),2)?>€</span></div><?php endforeach; ?><hr><div class="d-flex justify-content-between fs-5 text-white"><strong>Total</strong><strong class="text-primary"><?=number_format($total_weekly,2)?>€</strong></div></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const oid = <?=$current['id']??0?>; 
    const planCpus = <?=$plan_cpus?>; 
    const backupLimit = <?=$backup_limit?>;
    
    // --- UI HELPERS ---
    function showToast(msg, type='info') {
        const icon = type==='success'?'check-circle':(type==='error'?'exclamation-triangle':'info-circle');
        const color = type==='success'?'#10b981':(type==='error'?'#ef4444':'#3b82f6');
        const html = `<div class="custom-toast" style="border-left-color:${color}"><i class="bi bi-${icon}" style="color:${color};font-size:1.2rem"></i><div><strong>Notificación</strong><br><small>${msg}</small></div></div>`;
        const container = document.getElementById('toastContainer');
        const el = document.createElement('div'); el.innerHTML = html;
        container.appendChild(el);
        setTimeout(() => el.remove(), 4000);
        addLog(msg);
    }

    function addLog(msg) {
        const tbody = document.getElementById('activity-log-body');
        const time = new Date().toLocaleTimeString();
        const row = `<tr><td><span class="text-light-muted small me-2">[${time}]</span> <span class="text-white">${msg}</span></td></tr>`;
        if(tbody.innerHTML.includes('Esperando')) tbody.innerHTML = '';
        tbody.innerHTML = row + tbody.innerHTML;
    }

    // --- CHAT LOGIC MODIFICADA ---
    function toggleChat() { 
        const win = document.getElementById('chatWindow'); 
        win.style.display = win.style.display==='flex'?'none':'flex'; 
    }
    
    function handleChat(e) { if(e.key==='Enter') sendChat(); }
    
    function sendChat() {
        const inp = document.getElementById('chatInput');
        const txt = inp.value.trim();
        if(!txt) return;
        
        const body = document.getElementById('chatBody');
        body.innerHTML += `<div class="chat-msg me">${txt}</div>`;
        inp.value = '';
        body.scrollTop = body.scrollHeight;
        
        // MOSTRAR GLOBO DE PENSANDO INICIAL
        const thinkingHTML = `
            <div id="sylo-thinking" class="chat-msg support thinking-bubble">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span id="thinking-text">Enviando...</span>
            </div>`;
        body.innerHTML += thinkingHTML;
        body.scrollTop = body.scrollHeight;

        const formData = new FormData();
        formData.append('action', 'send_chat');
        formData.append('order_id', oid);
        formData.append('message', txt);
        
        fetch('dashboard_cliente.php?ajax_action=1', { method: 'POST', body: formData });
    }

    // --- LOGICA ORIGINAL RECUPERADA (IDENTICA A TU DASHBOARD ANTIGUO) ---
    const editorModal = new bootstrap.Modal(document.getElementById('editorModal')); 
    const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal')); 
    const backupModal = new bootstrap.Modal(document.getElementById('backupTypeModal'));
    
    let aceEditor = null;
    const initialCode = <?php echo json_encode($html_code); ?>;
    
    document.addEventListener("DOMContentLoaded", function() { 
        aceEditor = ace.edit("editor"); 
        aceEditor.setTheme("ace/theme/twilight"); 
        aceEditor.session.setMode("ace/mode/html"); 
        aceEditor.setOptions({fontSize: "14pt"});
        aceEditor.setValue(initialCode, -1); 
    });
    
    document.getElementById('editorModal').addEventListener('shown.bs.modal', function () { aceEditor.resize(); });

    function showEditor() { editorModal.show(); }
    function copyAllCreds() { navigator.clipboard.writeText(document.getElementById('all-creds-box').innerText); showToast("Copiado!", "success"); }
    
    function confirmTerminate() {
        const check = prompt("⚠️ ZONA DE PELIGRO ⚠️\n\nEsta acción borrará PERMANENTEMENTE tu servidor y todos sus datos.\nPara confirmar, escribe: eliminar");
        if (check === "eliminar") {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="terminate"><input type="hidden" name="order_id" value="${oid}">`;
            document.body.appendChild(form);
            form.submit();
        } else {
            alert("Operación cancelada. El texto no coincide.");
        }
    }

    function showBackupModal() {
        const countText = document.getElementById('backup-count').innerText;
        const [current, limit] = countText.split('/').map(Number);
        if (current >= limit) {
            document.getElementById('limit-alert').style.display = 'block';
            document.getElementById('normal-alert').style.display = 'none';
            document.getElementById('btn-start-backup').disabled = true;
        } else {
            document.getElementById('limit-alert').style.display = 'none';
            document.getElementById('normal-alert').style.display = 'block';
            document.getElementById('btn-start-backup').disabled = false;
        }
        backupModal.show();
    }

    function doBackup() {
        const typeEl = document.querySelector('input[name="backup_type"]:checked');
        const type = typeEl ? typeEl.value : 'full';
        const name = document.getElementById('backup_name_input').value || "Backup";
        let prettyType = (type === 'diff') ? "Diferencial" : ((type === 'incr') ? "Incremental" : "Completa");
        
        backupModal.hide();
        document.getElementById('backup_name_input').value = ""; 
        
        const ui = document.getElementById('backup-ui');
        const bar = document.getElementById('backup-bar');
        const listDiv = document.getElementById('backups-list-container');
        
        if(ui) ui.style.display='block';
        if(listDiv) listDiv.style.display='none';
        if(bar) bar.style.width='5%';
        
        fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'backup', order_id:oid, backup_type:type, backup_name:name})}); 
        showToast(`Iniciando Backup ${prettyType}: "${name}"`, "info");
    }
    
    function deleteBackup(file, type, name) { 
        if(confirm(`¿Borrar copia de seguridad: ${name}?`)) { 
            const dui = document.getElementById('delete-ui');
            const dbar = document.getElementById('delete-bar');
            const list = document.getElementById('backups-list-container');
            
            if(list) list.style.display = 'none';
            if(dui) dui.style.display = 'block';
            if(dbar) dbar.style.width = '100%';
            
            fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'delete_backup', order_id:oid, filename:file})});
            showToast(`Eliminando Backup: "${name}"`, "warning");
        } 
    }
    
    function manualRefresh() {
        const btn = document.getElementById('btn-refresh');
        if(btn) btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';
        fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'refresh_status', order_id:oid})})
        .then(() => { loadData(); showToast("Datos actualizados", "success"); });
    }
    
    function saveWeb() { 
        const wbtn = document.getElementById('btn-ver-web');
        if(wbtn) { wbtn.classList.add('disabled'); document.getElementById('web-loader-fill').style.width = '5%'; document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>Iniciando...'; }
        editorModal.hide(); 
        fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'update_web', order_id:oid, html_content:aceEditor.getValue()})});
        showToast("Publicando web...", "info");
    }

    document.getElementById('uploadForm').addEventListener('submit', function(e) { 
        e.preventDefault(); 
        const fileInput = this.querySelector('input[type="file"]');
        const file = fileInput.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) { aceEditor.setValue(e.target.result); };
            reader.readAsText(file);
        }
        uploadModal.hide(); 
        const wbtn = document.getElementById('btn-ver-web'); 
        if(wbtn) { wbtn.classList.add('disabled'); document.getElementById('web-loader-fill').style.width = '5%'; document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>Iniciando...'; }
        const formData = new FormData(this); 
        formData.append('order_id', oid); 
        formData.append('action', 'upload_web');
        fetch('dashboard_cliente.php?ajax_action=1', { method: 'POST', body: formData });
        showToast("Archivo subido", "success");
    });

    function loadData() {
        if(!oid) return;
        fetch(`dashboard_cliente.php?ajax_data=1&id=${oid}&t=${new Date().getTime()}`)
        .then(r => r.ok ? r.json() : null)
        .then(d => {
            const rBtn = document.getElementById('btn-refresh');
            if(rBtn) rBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
            if(d.terminated) { window.location.href = 'dashboard_cliente.php'; return; }
            if(!d) return;
            
            // METRICAS
            if(d.metrics) { 
                let rawCpu = parseFloat(d.metrics.cpu); 
                let visualCpu = (rawCpu / planCpus); 
                if(visualCpu > 100) visualCpu = 100;
                const cVal = document.getElementById('cpu-val'); if(cVal) cVal.innerText = visualCpu.toFixed(1);
                const cBar = document.getElementById('cpu-bar'); if(cBar) cBar.style.width = visualCpu+'%';
                const rVal = document.getElementById('ram-val'); if(rVal) rVal.innerText = parseFloat(d.metrics.ram).toFixed(1);
                const rBar = document.getElementById('ram-bar'); if(rBar) rBar.style.width = parseFloat(d.metrics.ram)+'%'; 
            }
            
            const cmd = document.getElementById('disp-ssh-cmd'); if(cmd) cmd.innerText = d.ssh_cmd || '...';
            const pass = document.getElementById('disp-ssh-pass'); if(pass) pass.innerText = d.ssh_pass || '...';
            const wurl = document.getElementById('disp-web-url'); 
            const btnw = document.getElementById('btn-ver-web');
            if(d.web_url) { 
                if(wurl) { wurl.innerText = d.web_url; wurl.href = d.web_url; }
                if(btnw) { btnw.href = d.web_url; btnw.classList.remove('d-none'); }
            } else {
                if(wurl) wurl.innerText = "Esperando IP...";
            }
            
            // BACKUPS & PROGRESS
            const bUi = document.getElementById('backup-ui');
            const bBar = document.getElementById('backup-bar');
            const dUi = document.getElementById('delete-ui');
            const dBar = document.getElementById('delete-bar');
            const list = document.getElementById('backups-list-container');

            if(d.backup_progress) {
                if(d.backup_progress.status === 'creating') {
                    if(bUi) bUi.style.display='block'; if(dUi) dUi.style.display='none'; if(list) list.style.display='none'; 
                    if(bBar) bBar.style.width=d.backup_progress.progress+'%'; 
                } else if(d.backup_progress.status === 'deleting') {
                    if(bUi) bUi.style.display='none'; if(dUi) dUi.style.display='block'; if(list) list.style.display='none';
                    if(dBar) dBar.style.width=d.backup_progress.progress+'%'; 
                }
            } else {
                if(bUi) bUi.style.display='none'; if(dUi) dUi.style.display='none'; if(list) list.style.display='block';
                document.getElementById('backup-count').innerText = `${d.backups_list.length}/${backupLimit}`;
                let html = '';
                [...d.backups_list].reverse().forEach(b => {
                    let typeClass = b.type == 'full' ? 'bg-primary' : (b.type == 'diff' ? 'bg-warning text-dark' : 'bg-info text-dark');
                    let typeName = b.type == 'full' ? 'FULL' : (b.type == 'diff' ? 'DIFF' : 'INCR');
                    html += `<div class="backup-item"><div class="text-white"><span class="fw-bold">${b.name}</span> <span class="badge ${typeClass} ms-2">${typeName}</span><div class="small text-light-muted">${b.date}</div></div><button onclick="deleteBackup('${b.file}', '${b.type}', '${b.name}')" class="btn btn-sm text-danger opacity-50 hover-opacity-100"><i class="bi bi-trash"></i></button></div>`;
                });
                list.innerHTML = html || '<small class="text-light-muted d-block text-center py-2">Sin copias disponibles.</small>';
            }
            
            // [MODIFICADO] CHAT IA RESPONSE & STATUS REAL
            const chatBubble = document.getElementById('sylo-thinking');
            const chatText = document.getElementById('thinking-text');

            // 1. ACTUALIZAR ESTADO DE PENSAMIENTO (SI EXISTE)
            if (chatBubble && d.chat_status) {
                chatText.innerText = d.chat_status;
            }

            // 2. MOSTRAR RESPUESTA FINAL
            if(d.chat_reply) {
                if(chatBubble) chatBubble.remove(); // Borrar globo de pensar

                const body = document.getElementById('chatBody');
                body.innerHTML += `<div class="chat-msg support">${d.chat_reply}</div>`;
                body.scrollTop = body.scrollHeight;
                showToast("Mensaje de soporte recibido", "info");
            }
            
            // WEB PROGRESS
            if(btnw && d.web_progress) {
                const loader = document.getElementById('web-loader-fill');
                const txt = document.getElementById('web-btn-text');
                if(d.web_progress.progress < 100) { 
                    btnw.classList.add('disabled'); 
                    if(loader) loader.style.width = d.web_progress.progress + '%'; 
                    if(txt) txt.innerHTML = `<i class="bi bi-arrow-repeat spin me-2"></i>${d.web_progress.msg}`; 
                } else {
                    btnw.classList.remove('disabled'); 
                    if(loader) loader.style.width = '0%'; 
                    if(txt) txt.innerHTML = '<i class="bi bi-box-arrow-up-right me-2"></i>Ver Sitio Web';
                }
            }
        }).catch(err => { console.log("Esperando datos...", err); });
    }

    setInterval(loadData, 1500);
</script>
</body>
</html>