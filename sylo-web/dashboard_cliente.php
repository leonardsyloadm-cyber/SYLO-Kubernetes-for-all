<?php
// =================================================================================
// 🛡️ SYLO DASHBOARD CLIENTE - V15 (VISUAL SYNC FIX)
// =================================================================================
session_start();
define('API_URL_BASE', 'http://host.docker.internal:8001/api/clientes');

// AUTH & DB
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header("Location: index.php"); exit; }
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";
try { $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db); } catch(PDOException $e) { die("Error DB"); }

$buzon_path = "../buzon-pedidos"; 
$user_id = $_SESSION['user_id'];

// UPDATE PROFILE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $sql = "UPDATE users SET full_name=?, email=?, dni=?, telefono=?, company_name=?, calle=? WHERE id=?";
    $conn->prepare($sql)->execute([$_POST['full_name'], $_POST['email'], $_POST['dni'], $_POST['telefono'], $_POST['company_name'], $_POST['calle'], $user_id]);
    header("Location: dashboard_cliente.php"); exit;
}
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$user_id]); $user_info = $stmt->fetch(PDO::FETCH_ASSOC);

// HELPERS
function calculateWeeklyPrice($r) { 
    $p=match($r['plan_name']??''){'Bronce'=>5,'Plata'=>15,'Oro'=>30,default=>0}; 
    if(($r['plan_name']??'')=='Personalizado'){
        $p=(intval($r['custom_cpu']??0)*5)+(intval($r['custom_ram']??0)*5); 
        if(!empty($r['db_enabled']))$p+=10; 
        if(!empty($r['web_enabled']))$p+=10;
    } 
    return $p/4; 
}
function getBackupLimit($r) { 
    $m=match($r['plan_name']??''){'Bronce'=>5,'Plata'=>15,'Oro'=>30,default=>0}; 
    if(($r['plan_name']??'')=='Personalizado'){
        $m=(intval($r['custom_cpu']??0)*5)+(intval($r['custom_ram']??0)*5); 
        if(!empty($r['db_enabled']))$m+=10; 
        if(!empty($r['web_enabled']))$m+=10;
    } 
    return (($r['plan_name']??'')=='Oro'||$m>=30)?5:((($r['plan_name']??'')=='Plata'||$m>=15)?3:2); 
}
function getPlanStyle($n) { return match($n) { 'Bronce'=>'background:#CD7F32;color:#fff;box-shadow:0 0 10px rgba(205,127,50,0.4);', 'Plata'=>'background:#94a3b8;color:#fff;box-shadow:0 0 10px rgba(148,163,184,0.4);', 'Oro'=>'background:#FFD700;color:#000;font-weight:bold;box-shadow:0 0 15px rgba(255,215,0,0.6);', 'Personalizado'=>'background:linear-gradient(45deg,#CD7F32,#94a3b8,#FFD700);color:#fff;font-weight:bold;border:1px solid rgba(255,255,255,0.3);', default=>'background:#334155;color:#fff;' }; }
function getSidebarStyle($n) { $c=match($n){'Bronce'=>'#CD7F32','Plata'=>'#94a3b8','Oro'=>'#FFD700','Personalizado'=>'#a855f7',default=>'#3b82f6'}; return "border-left:3px solid $c;background:linear-gradient(90deg,{$c}11,transparent);"; }
function getOSIconHtml($os) {
    $os = strtolower($os ?? 'ubuntu');
    if (strpos($os, 'alpine') !== false) return '<i class="bi bi-snow2 text-info fs-4"></i>';
    if (strpos($os, 'redhat') !== false) return '<i class="bi bi-motherboard text-danger fs-4"></i>'; 
    return '<i class="bi bi-ubuntu text-warning fs-4"></i>';
}
function getOSNamePretty($os) {
    $os = strtolower($os ?? 'ubuntu');
    if (strpos($os, 'alpine') !== false) return 'Alpine Linux';
    if (strpos($os, 'redhat') !== false) return 'Red Hat Enterprise';
    return 'Ubuntu Server';
}

// --- AJAX API ---
if (isset($_GET['ajax_data']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $oid = $_GET['id'];
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id=?"); $stmt->execute([$oid]); $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$st || $st['status'] == 'cancelled') { echo json_encode(['terminated' => true]); exit; }
    
    $api_url = API_URL_BASE . "/estado/$oid";
    $ctx = stream_context_create(['http'=> ['timeout' => 2]]);
    $api_json = @file_get_contents($api_url, false, $ctx);
    
    $res = [
        'metrics' => ['cpu' => 0, 'ram' => 0],
        'ssh_cmd' => 'Conectando...',
        'ssh_pass' => '...',
        'web_url' => null,
        'backups_list' => [],
        'backup_progress' => null,
        'web_progress' => null,
        'chat_reply' => null
    ];

    if ($api_json) {
        $d = json_decode($api_json, true);
        if ($d) $res = array_merge($res, $d);
    }
    
    $chat_file = "$buzon_path/chat_response_$oid.json";
    if(file_exists($chat_file)) {
        $cd = json_decode(file_get_contents($chat_file), true);
        if($cd && isset($cd['reply'])) { $res['chat_reply'] = $cd['reply']; @unlink($chat_file); }
    }
    echo json_encode($res); exit;
}

// --- ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] != 'update_profile') {
    $oid = $_POST['order_id']; $act = $_POST['action'];
    
    $payload_api = [
        "id_cliente" => (int)$oid,
        "accion" => $act,
        "backup_type" => "full",
        "backup_name" => "Backup",
        "filename_to_restore" => "",
        "filename_to_delete" => "",
        "html_content" => ""
    ];

    if($act == 'send_chat') {
        $msg = $_POST['message'];
        $chat_payload = ["id_cliente" => (int)$oid, "mensaje" => $msg];
        $opts = ['http' => ['header' => "Content-type: application/json\r\n", 'method' => 'POST', 'content' => json_encode($chat_payload)]];
        @file_get_contents(API_URL_BASE . "/chat", false, stream_context_create($opts));
        if(isset($_GET['ajax_action'])) { echo json_encode(['status'=>'ok']); exit; }
        exit; 
    }

    if($act == 'backup') {
        $payload_api['backup_type'] = $_POST['backup_type'] ?? 'full';
        $payload_api['backup_name'] = $_POST['backup_name'] ?? 'Manual';
    }
    if($act == 'restore_backup') $payload_api['filename_to_restore'] = $_POST['filename'];
    if($act == 'delete_backup') $payload_api['filename_to_delete'] = $_POST['filename'];

    if($act == 'update_web' || ($act == 'upload_web' && isset($_FILES['html_file']))) {
        $html = ($act == 'upload_web') ? file_get_contents($_FILES['html_file']['tmp_name']) : $_POST['html_content'];
        $payload_api['html_content'] = $html;
        if($act=='upload_web') { $payload_api['accion'] = "update_web"; }
    }
    
    if ($act != 'send_chat') {
        $url = API_URL_BASE . "/accion";
        $options = ['http' => ['header' => "Content-type: application/json\r\n", 'method' => 'POST', 'content' => json_encode($payload_api)]];
        @file_get_contents($url, false, stream_context_create($options));
    }
    
    if(isset($_GET['ajax_action'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'ok']); exit; }
    header("Location: dashboard_cliente.php?id=$oid"); exit;
}

// --- INIT DATA ---
$sql = "SELECT o.*, p.name as plan_name, p.cpu_cores as p_cpu, p.ram_gb as p_ram, os.cpu_cores as custom_cpu, os.ram_gb as custom_ram, os.db_enabled, os.web_enabled, os.os_image FROM orders o JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE user_id=? AND status!='cancelled' ORDER BY o.id DESC";
$stmt = $conn->prepare($sql); $stmt->execute([$_SESSION['user_id']]); $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = null; 
$os_image = 'ubuntu'; 
$total_weekly = 0; 
$creds = ['ssh_cmd'=>'Esperando...', 'ssh_pass'=>'...'];
$web_url = null;
$html_code = "<!DOCTYPE html>\n<html>\n<body>\n<h1>Bienvenido a Sylo</h1>\n</body>\n</html>";

foreach($clusters as $c) $total_weekly += calculateWeeklyPrice($c);

if($clusters) {
    $current = (isset($_GET['id'])) ? array_values(array_filter($clusters, fn($c)=>$c['id']==$_GET['id']))[0] ?? $clusters[0] : $clusters[0];
    if ($current['plan_name'] == 'Personalizado') $plan_cpus = intval($current['custom_cpu'] ?? 1); else $plan_cpus = intval($current['p_cpu'] ?? 1); 
    if ($plan_cpus < 1) $plan_cpus = 1;
    $backup_limit = getBackupLimit($current);
    $has_web = ($current['plan_name'] === 'Oro' || (!empty($current['web_enabled']) && $current['web_enabled'] == 1));
    $has_db = ($current['plan_name'] === 'Oro' || (!empty($current['db_enabled']) && $current['db_enabled'] == 1));
    $os_image = $current['os_image'] ?? 'ubuntu';
    
    $ctx = stream_context_create(['http'=> ['timeout' => 1]]);
    $api_init = @file_get_contents(API_URL_BASE . "/estado/{$current['id']}", false, $ctx);
    
    if($api_init) { 
        $d = json_decode($api_init, true); 
        if($d) {
            $creds['ssh_cmd'] = $d['ssh_cmd'] ?? 'Esperando...';
            $creds['ssh_pass'] = $d['ssh_pass'] ?? '...';
            $web_url = $d['web_url'] ?? null;
            if(isset($d['html_source']) && !empty($d['html_source'])) {
                $html_code = $d['html_source'];
            }
        }
    }
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
            <small class="text-light-muted">Bienvenido, <?=htmlspecialchars($user_info['username']??'Usuario')?></small>
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
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-black bg-opacity-25 p-3 rounded-circle border border-secondary">
                        <?=getOSIconHtml($os_image)?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-white">Servidor #<?=$current['id']?></h4>
                        <small class="text-light-muted font-monospace"><i class="bi bi-hdd-network me-1"></i> <?=getOSNamePretty($os_image)?></small>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2 rounded-pill">ONLINE</span>
                    <span class="badge px-3 py-2 rounded-pill" style="<?=getPlanStyle($current['plan_name'])?>">
                        PLAN <?=strtoupper($current['plan_name'])?>
                    </span>
                </div>
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
                    <?php if($has_web): ?><div><span class="term-label">WEB:</span> <a href="<?=htmlspecialchars($web_url??'#')?>" target="_blank" class="term-val text-decoration-none hover-white" id="disp-web-url"><?=htmlspecialchars($web_url??'Esperando IP...')?></a></div><?php endif; ?>
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
                    <a href="#" target="_blank" id="btn-ver-web" class="btn btn-primary w-100 mb-2 fw-bold position-relative overflow-hidden <?=$web_url?'':'disabled'?>">
                        <div id="web-loader-fill" style="position:absolute;top:0;left:0;height:100%;width:0%;background:rgba(255,255,255,0.2);transition:width 0.5s;"></div>
                        <span id="web-btn-text"><i class="bi bi-box-arrow-up-right me-2"></i><?=$web_url?'Ver Sitio Web':'Esperando Web...'?></span>
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

                    <div id="restore-ui" style="display:none" class="mt-3">
                        <div class="progress" style="height:4px">
                            <div id="restore-bar" class="progress-bar bg-primary" style="width:0%"></div>
                        </div>
                        <small id="restore-text" class="text-primary d-block mt-1">Restaurando...</small>
                    </div>

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
            Hola <?=htmlspecialchars($_SESSION['username']??'Usuario')?>, si tienes preguntas, estas son las más frecuentes:
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

    function toggleChat() { const win = document.getElementById('chatWindow'); win.style.display = win.style.display==='flex'?'none':'flex'; }
    function handleChat(e) { if(e.key==='Enter') sendChat(); }
    
    function sendChat() {
        const inp = document.getElementById('chatInput');
        const txt = inp.value.trim();
        if(!txt) return;
        const body = document.getElementById('chatBody');
        body.innerHTML += `<div class="chat-msg me">${txt}</div>`;
        inp.value = '';
        body.scrollTop = body.scrollHeight;
        const thinkingHTML = `<div id="sylo-thinking" class="chat-msg support thinking-bubble"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span id="thinking-text">Enviando...</span></div>`;
        body.innerHTML += thinkingHTML;
        body.scrollTop = body.scrollHeight;
        const formData = new FormData();
        formData.append('action', 'send_chat');
        formData.append('order_id', oid);
        formData.append('message', txt);
        fetch('dashboard_cliente.php?ajax_action=1', { method: 'POST', body: formData });
    }

    const editorModal = new bootstrap.Modal(document.getElementById('editorModal')); 
    const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal')); 
    const backupModal = new bootstrap.Modal(document.getElementById('backupTypeModal'));
    let aceEditor = null;
    const initialCode = <?php echo json_encode($html_code); ?>;
    
    document.addEventListener("DOMContentLoaded", function() { 
        aceEditor = ace.edit("editor"); aceEditor.setTheme("ace/theme/twilight"); aceEditor.session.setMode("ace/mode/html"); aceEditor.setOptions({fontSize: "14pt"}); aceEditor.setValue(initialCode, -1); 
    });
    
    document.getElementById('editorModal').addEventListener('shown.bs.modal', function () { aceEditor.resize(); });
    function showEditor() { editorModal.show(); }
    function copyAllCreds() { navigator.clipboard.writeText(document.getElementById('all-creds-box').innerText); showToast("Copiado!", "success"); }
    
    function confirmTerminate() {
        if (prompt("⚠️ ZONA DE PELIGRO ⚠️\n\nEscribe: eliminar") === "eliminar") {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="terminate"><input type="hidden" name="order_id" value="${oid}">`;
            document.body.appendChild(form); form.submit();
        } else alert("Cancelado.");
    }

    function showBackupModal() {
        const [current, limit] = document.getElementById('backup-count').innerText.split('/').map(Number);
        document.getElementById('limit-alert').style.display = (current >= limit) ? 'block' : 'none';
        document.getElementById('normal-alert').style.display = (current >= limit) ? 'none' : 'block';
        document.getElementById('btn-start-backup').disabled = (current >= limit);
        backupModal.show();
    }

    function doBackup() {
        const type = document.querySelector('input[name="backup_type"]:checked').value;
        const name = document.getElementById('backup_name_input').value || "Backup";
        backupModal.hide(); document.getElementById('backup_name_input').value = ""; 
        document.getElementById('backup-ui').style.display='block';
        document.getElementById('backups-list-container').style.display='none';
        document.getElementById('backup-bar').style.width='5%';
        fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'backup', order_id:oid, backup_type:type, backup_name:name})}); 
        showToast(`Iniciando Backup...`, "info");
    }
    
    function restoreBackup(file, name) {
        if(prompt(`⚠️ RESTAURAR "${name}"?\nEscribe: RESTAURAR`) === "RESTAURAR") {
            document.getElementById('backups-list-container').style.display = 'none';
            document.getElementById('restore-ui').style.display = 'block';
            document.getElementById('restore-bar').style.width = '10%';
            fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'restore_backup', order_id:oid, filename:file})});
            showToast(`Restaurando...`, "warning");
        } else alert("Cancelado.");
    }

    function deleteBackup(file, type, name) { 
        if(confirm(`¿Borrar copia: ${name}?`)) { 
            document.getElementById('backups-list-container').style.display = 'none';
            document.getElementById('delete-ui').style.display = 'block';
            document.getElementById('delete-bar').style.width = '100%';
            fetch('dashboard_cliente.php?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'delete_backup', order_id:oid, filename:file})});
            showToast(`Eliminando...`, "warning");
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
        const file = this.querySelector('input[type="file"]').files[0];
        if (file) { const reader = new FileReader(); reader.onload = function(e) { aceEditor.setValue(e.target.result); }; reader.readAsText(file); }
        uploadModal.hide(); 
        const wbtn = document.getElementById('btn-ver-web'); 
        if(wbtn) { wbtn.classList.add('disabled'); document.getElementById('web-loader-fill').style.width = '5%'; document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>Iniciando...'; }
        const formData = new FormData(this); formData.append('order_id', oid); formData.append('action', 'upload_web');
        fetch('dashboard_cliente.php?ajax_action=1', { method: 'POST', body: formData });
        showToast("Archivo subido", "success");
    });

    function loadData() {
        if(!oid) return;
        fetch(`dashboard_cliente.php?ajax_data=1&id=${oid}&t=${new Date().getTime()}`)
        .then(r => r.ok ? r.json() : null)
        .then(d => {
            const rBtn = document.getElementById('btn-refresh'); if(rBtn) rBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
            if(d.terminated) { window.location.href = 'dashboard_cliente.php'; return; }
            if(!d) return;
            
            try { if(d.metrics) { 
                let rawCpu = parseFloat(d.metrics.cpu); let visualCpu = (rawCpu / planCpus); if(visualCpu > 100) visualCpu = 100;
                const cVal = document.getElementById('cpu-val'); if(cVal) cVal.innerText = visualCpu.toFixed(1);
                const cBar = document.getElementById('cpu-bar'); if(cBar) cBar.style.width = visualCpu+'%';
                const rVal = document.getElementById('ram-val'); if(rVal) rVal.innerText = parseFloat(d.metrics.ram).toFixed(1);
                const rBar = document.getElementById('ram-bar'); if(rBar) rBar.style.width = parseFloat(d.metrics.ram)+'%'; 
            }} catch(e) {}
            
            const cmd = document.getElementById('disp-ssh-cmd'); if(cmd) cmd.innerText = d.ssh_cmd || '...';
            const pass = document.getElementById('disp-ssh-pass'); if(pass) pass.innerText = d.ssh_pass || '...';
            const wurl = document.getElementById('disp-web-url'); const btnw = document.getElementById('btn-ver-web');
            try { if(d.web_url) { if(wurl) { wurl.innerText = d.web_url; wurl.href = d.web_url; } if(btnw) { btnw.href = d.web_url; btnw.classList.remove('disabled'); if(btnw.querySelector('span')) btnw.querySelector('span').innerHTML = '<i class="bi bi-box-arrow-up-right me-2"></i>Ver Sitio Web'; } } else { if(wurl) wurl.innerText = "Esperando IP..."; } } catch(e) {}
            
            const bUi = document.getElementById('backup-ui'); const bBar = document.getElementById('backup-bar');
            const dUi = document.getElementById('delete-ui'); const dBar = document.getElementById('delete-bar');
            const rUi = document.getElementById('restore-ui'); const rBar = document.getElementById('restore-bar');
            const list = document.getElementById('backups-list-container');

            try {
                if(d.backup_progress && d.backup_progress.status !== 'completed') {
                    if(d.backup_progress.status === 'creating') {
                        if(bUi) bUi.style.display='block'; if(dUi) dUi.style.display='none'; if(rUi) rUi.style.display='none'; if(list) list.style.display='none'; 
                        if(bBar) bBar.style.width=d.backup_progress.progress+'%'; 
                    } else if(d.backup_progress.status === 'deleting') {
                        if(bUi) bUi.style.display='none'; if(dUi) dUi.style.display='block'; if(rUi) rUi.style.display='none'; if(list) list.style.display='none';
                        if(dBar) dBar.style.width=d.backup_progress.progress+'%'; 
                    } else if(d.backup_progress.status === 'restoring') {
                        if(bUi) bUi.style.display='none'; if(dUi) dUi.style.display='none'; if(rUi) rUi.style.display='block'; if(list) list.style.display='none';
                        if(rBar) rBar.style.width=d.backup_progress.progress+'%';
                        const rText = document.getElementById('restore-text'); if(rText && d.backup_progress.msg) rText.innerText = d.backup_progress.msg;
                    }
                } else {
                    if(bUi) bUi.style.display='none'; if(dUi) dUi.style.display='none'; if(rUi) rUi.style.display='none'; if(list) list.style.display='block';
                    document.getElementById('backup-count').innerText = `${d.backups_list.length}/${backupLimit}`;
                    let html = '';
                    [...d.backups_list].reverse().forEach(b => {
                        let typeClass = b.type == 'full' ? 'bg-primary' : (b.type == 'diff' ? 'bg-warning text-dark' : 'bg-info text-dark');
                        html += `<div class="backup-item d-flex justify-content-between align-items-center mb-2 p-2 rounded" style="background:rgba(255,255,255,0.05)"><div class="text-white"><span class="fw-bold">${b.name.replace(/'/g, "")}</span> <span class="badge ${typeClass} ms-2">${b.type.toUpperCase()}</span><div class="small text-light-muted">${b.date}</div></div><div class="d-flex gap-2"><button onclick="restoreBackup('${b.file}', '${b.name.replace(/'/g, "")}')" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i></button><button onclick="deleteBackup('${b.file}', '${b.type}', '${b.name.replace(/'/g, "")}')" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></div></div>`;
                    });
                    list.innerHTML = html || '<small class="text-light-muted d-block text-center py-2">Sin copias disponibles.</small>';
                }
            } catch(e) {}
            
            try {
                const chatBubble = document.getElementById('sylo-thinking'); const chatText = document.getElementById('thinking-text');
                if (chatBubble && d.chat_status) chatText.innerText = d.chat_status;
                if(d.chat_reply) { if(chatBubble) chatBubble.remove(); const body = document.getElementById('chatBody'); body.innerHTML += `<div class="chat-msg support">${d.chat_reply}</div>`; body.scrollTop = body.scrollHeight; showToast("Mensaje de soporte recibido", "info"); }
            } catch(e) {}
            
            try { if(btnw && d.web_progress) {
                const loader = document.getElementById('web-loader-fill'); const txt = document.getElementById('web-btn-text');
                if(d.web_progress.progress < 100) { btnw.classList.add('disabled'); if(loader) loader.style.width = d.web_progress.progress + '%'; if(txt) txt.innerHTML = `<i class="bi bi-arrow-repeat spin me-2"></i>${d.web_progress.msg}`; } else { btnw.classList.remove('disabled'); if(loader) loader.style.width = '0%'; if(txt) txt.innerHTML = '<i class="bi bi-box-arrow-up-right me-2"></i>Ver Sitio Web'; }
            }} catch(e) {}

        }).catch(err => { console.log("Esperando datos...", err); });
    }
    setInterval(loadData, 1500);
</script>
</body>
</html>