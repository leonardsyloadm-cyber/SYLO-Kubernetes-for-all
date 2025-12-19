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

// --- 3. HELPERS ---
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

// --- 4. API AJAX ---
if (isset($_GET['ajax_data']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $oid = $_GET['id'];
    
    // Verificar si sigue activo en DB (por si el Terminator lo borró)
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id=?");
    $stmt->execute([$oid]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$st || $st['status'] == 'cancelled') {
        echo json_encode(['terminated' => true]); // Señal para redirigir
        exit;
    }
    
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

    $data = json_decode($json, true) ?? [];
    $clean_pass = isset($data['ssh_pass']) ? cleanPass($data['ssh_pass']) : '...';

    echo json_encode([
        'metrics' => $data['metrics'] ?? ['cpu' => 0, 'ram' => 0],
        'ssh_cmd' => $data['ssh_cmd'] ?? 'Conectando...',
        'ssh_pass' => $clean_pass,
        'web_url' => $data['web_url'] ?? null,
        'db_host' => $data['db_host'] ?? null,
        'backups_list' => $backups_list,
        'backup_progress' => $backup_status,
        'web_progress' => json_decode($web_status, true)
    ]);
    exit;
}

// --- 5. PROCESAR ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] != 'update_profile') {
    $oid = $_POST['order_id'];
    $act = $_POST['action'];
    $data = ["id" => (int)$oid, "action" => strtoupper($act), "user" => $_SESSION['username']];
    
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
    
    $fname = match($act) { 'terminate'=>'terminate', 'backup'=>'backup', 'update_web'=>'update_web', 'delete_backup'=>'delete_backup', 'refresh_status'=>'refresh', default=>$act };
    
    $timestamp = microtime(true);
    file_put_contents("$buzon_path/accion_{$oid}_{$fname}_{$timestamp}.json", json_encode($data));
    @chmod("$buzon_path/accion_{$oid}_{$fname}_{$timestamp}.json", 0666);
    
    if($act == 'update_web') @unlink("$buzon_path/web_status_{$oid}.json");
    
    if(isset($_GET['ajax_action'])) { 
        header('Content-Type: application/json'); 
        echo json_encode(['status'=>'ok']); 
        exit; 
    }
    header("Location: dashboard_cliente.php?id=$oid"); exit;
}

// --- 6. CARGA DATOS INICIALES ---
$sql = "SELECT o.*, p.name as plan_name, p.cpu_cores as p_cpu, p.ram_gb as p_ram, os.cpu_cores as custom_cpu, os.ram_gb as custom_ram, os.db_enabled, os.web_enabled FROM orders o JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE user_id=? AND status!='cancelled' ORDER BY o.id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = null; $creds = ['ssh_cmd'=>'Esperando...', 'ssh_pass'=>'...'];
$web_url = null; $has_web = false; $has_db = false; $total_weekly = 0;
$plan_cpus = 1; $backup_limit = 2;

foreach($clusters as $c) $total_weekly += calculateWeeklyPrice($c);

if($clusters) {
    $current = (isset($_GET['id'])) ? array_values(array_filter($clusters, fn($c)=>$c['id']==$_GET['id']))[0] ?? $clusters[0] : $clusters[0];
    
    if ($current['plan_name'] == 'Personalizado') {
        $plan_cpus = intval($current['custom_cpu'] ?? 1);
    } else {
        $plan_cpus = intval($current['p_cpu'] ?? 1);
    }
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
    $html_code = file_exists($src_file) ? file_get_contents($src_file) : "";
    if (empty($html_code)) $html_code = "<!DOCTYPE html>\n<html lang=\"es\">\n<head>\n<title>Mi Web</title>\n</head>\n<body>\n<h1>Bienvenido</h1>\n</body>\n</html>";
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
        :root { --sylo-blue: #2563eb; --sylo-dark: #0f172a; --sylo-bg: #f8fafc; --sylo-sidebar: #ffffff; }
        body { font-family: 'Outfit', sans-serif; background-color: var(--sylo-bg); color: #334155; overflow-x: hidden; }
        .sidebar { height: 100vh; background: var(--sylo-sidebar); border-right: 1px solid #e2e8f0; padding-top: 25px; position: fixed; width: 260px; z-index: 1000; box-shadow: 4px 0 24px rgba(0,0,0,0.02); display: flex; flex-direction: column; }
        .sidebar .brand { font-size: 1.4rem; color: #1e293b; padding-left: 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; }
        .sidebar .nav-link { color: #64748b; padding: 12px 24px; margin: 4px 16px; border-radius: 12px; transition: all 0.2s; font-weight: 500; display: flex; align-items: center; text-decoration: none; }
        .sidebar .nav-link:hover { color: var(--sylo-blue); background: #eff6ff; transform: translateX(3px); }
        .sidebar .nav-link.active { background: linear-gradient(135deg, var(--sylo-blue), #3b82f6); color: white; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }
        .sidebar-footer { margin-top: auto; padding: 20px; border-top: 1px solid #f1f5f9; }
        .main-content { margin-left: 260px; padding: 30px 40px; animation: fadeInUp 0.5s ease-out; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .user-profile-pill { background: white; padding: 8px 20px; border-radius: 30px; display: flex; align-items: center; gap: 12px; cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; transition: all 0.2s; }
        .user-profile-pill:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-color: var(--sylo-blue); }
        .avatar-icon { font-size: 1.8rem; color: var(--sylo-blue); line-height: 1; }
        .card-clean { background: white; border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); padding: 1.8rem; height: auto; margin-bottom: 20px; transition: transform 0.3s ease; position: relative; overflow: hidden; }
        .card-clean:hover { transform: translateY(-3px); }
        .metric-card { background: white; border-radius: 20px; padding: 25px; position: relative; overflow: hidden; box-shadow: 0 10px 20px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.03); transition: transform 0.2s; }
        .metric-card.cpu { border-left: 6px solid #2563eb; background: linear-gradient(145deg, #ffffff 40%, #eff6ff 100%); }
        .metric-card.ram { border-left: 6px solid #10b981; background: linear-gradient(145deg, #ffffff 40%, #f0fdf4 100%); }
        .metric-value { font-size: 2.8rem; font-weight: 800; line-height: 1; letter-spacing: -1.5px; }
        .progress-thin { height: 10px; border-radius: 20px; background: #e2e8f0; margin-top: 18px; overflow: hidden; }
        .progress-bar { border-radius: 20px; transition: width 0.6s ease; height: 100%; }
        #cpu-bar { background-color: #2563eb; } #ram-bar { background-color: #10b981; }
        .terminal-container { background: #0f172a; border-radius: 12px; padding: 0; overflow: hidden; margin-top: 15px; box-shadow: inset 0 0 20px rgba(0,0,0,0.5); }
        .terminal-body { padding: 20px; font-family: 'Fira Code', monospace; font-size: 0.85rem; color: #e2e8f0; line-height: 1.7; }
        .term-label { color: #64748b; font-weight: bold; letter-spacing: 0.5px; margin-right: 10px; user-select: none; }
        .term-val { color: #a5b4fc; }
        .term-section { margin-bottom: 15px; border-bottom: 1px dashed #334155; padding-bottom: 10px; }
        .term-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .term-header { color: #22d3ee; font-weight: bold; margin-bottom: 5px; display: block; text-transform: uppercase; font-size: 0.75rem; }
        .btn-action { width: 100%; text-align: left; margin-bottom: 8px; padding: 12px 16px; font-size: 0.95rem; border-radius: 10px; display: flex; align-items: center; border: 1px solid #f1f5f9; background: white; color: #475569; transition: all 0.2s; font-weight: 600; }
        .btn-action:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--sylo-blue); transform: translateX(2px); }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .spin { animation: spin 1s linear infinite; display: inline-block; vertical-align: middle; }
        #editor { width: 100%; height: 500px; font-size: 14px; border-radius: 0 0 8px 8px; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* BACKUP STYLES */
        .backup-option { border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .backup-option:hover { border-color: var(--sylo-blue); background: #f8fafc; }
        .backup-option input[type="radio"]:checked + div { color: var(--sylo-blue); font-weight: bold; }
        .backup-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .backup-item:last-child { border-bottom: none; }
        .badge-type { width: 45px; text-align: center; display: inline-block; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="brand"><i class="bi bi-hdd-network-fill text-primary me-2"></i><strong>SYLO</strong> Cloud</div>
    <div class="d-flex flex-column gap-1">
        <a href="index.php" class="nav-link"><i class="bi bi-plus-circle me-2"></i> Nuevo Servicio</a>
        <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#billingModal"><i class="bi bi-receipt me-2"></i> Facturación</a>
        <div class="mt-4 px-4 mb-2 text-muted fw-bold" style="font-size: 0.75rem; letter-spacing: 1px;">SERVIDORES</div>
        <?php foreach($clusters as $c): $cls = ($current && $c['id']==$current['id'])?'active':''; ?>
            <a href="?id=<?=$c['id']?>" class="nav-link <?=$cls?>"><i class="bi bi-server me-2"></i> <span>#<?=$c['id']?></span><span class="badge bg-light text-dark ms-auto border"><?=substr($c['plan_name'],0,3)?></span></a>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-footer"><a href="?action=logout" class="btn btn-outline-danger w-100 border-0 bg-danger bg-opacity-10 text-danger fw-bold"><i class="bi bi-box-arrow-left me-2"></i> Cerrar Sesión</a></div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div><h4 class="fw-bold mb-0">Panel de Control</h4><small class="text-muted">Gestiona tus recursos de alto rendimiento</small></div>
        <div class="user-profile-pill" data-bs-toggle="modal" data-bs-target="#profileModal">
            <i class="bi bi-person-circle avatar-icon"></i>
            <div class="d-flex flex-column pe-2"><span class="fw-bold small"><?=htmlspecialchars($user_info['username'])?></span><span class="text-muted" style="font-size: 0.7rem;">Configuración <i class="bi bi-gear-fill ms-1"></i></span></div>
        </div>
    </div>

    <?php if (!$current): ?>
        <div class="text-center py-5"><i class="bi bi-cloud-slash display-1 text-muted opacity-25"></i><h3 class="mt-3 text-muted">No tienes servicios activos</h3><a href="index.php" class="btn btn-primary mt-2">Contratar Ahora</a></div>
    <?php else: ?>
    
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex align-items-center mb-4"><span class="badge bg-success me-2 px-3 py-2">ACTIVO</span><h3 class="fw-bold mb-0 text-dark">Servidor #<?=$current['id']?> <span class="text-muted fw-light">| <?=$current['plan_name']?></span></h3></div>
            
            <div class="row g-4 mb-4">
                <div class="col-md-6"><div class="metric-card cpu"><div class="d-flex justify-content-between"><span class="text-primary fw-bold">CPU</span><i class="bi bi-cpu text-primary opacity-50"></i></div><div class="metric-value text-primary mt-2"><span id="cpu-val">0</span>%</div><div class="progress-thin"><div id="cpu-bar" class="progress-bar" style="width:0%"></div></div></div></div>
                <div class="col-md-6"><div class="metric-card ram"><div class="d-flex justify-content-between"><span class="text-success fw-bold">RAM</span><i class="bi bi-memory text-success opacity-50"></i></div><div class="metric-value text-success mt-2"><span id="ram-val">0</span>%</div><div class="progress-thin"><div id="ram-bar" class="progress-bar" style="width:0%"></div></div></div></div>
            </div>
            
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between mb-3 align-items-center"><h6 class="fw-bold m-0 text-uppercase text-muted small"><i class="bi bi-key-fill me-2"></i>Accesos</h6><button onclick="copyAllCreds()" class="btn btn-sm btn-light border rounded-pill px-3"><i class="bi bi-clipboard me-1"></i> Copiar</button></div>
                <div class="terminal-container" id="all-creds-box">
                    <div class="terminal-body">
                        <?php if($has_web): ?><div class="term-section"><span class="term-header">// WEB ENDPOINT</span><div><span class="term-label">URL:</span> <span class="term-val" id="disp-web-url"><?=htmlspecialchars($web_url)?></span></div></div><?php endif; ?>
                        <?php if($has_db): ?><div class="term-section"><span class="term-header">// MYSQL CLUSTER</span><div><span class="term-label">MASTER:</span> <span class="term-val text-warning">mysql-master-0</span> <small class="text-muted">(Write)</small></div><div><span class="term-label">SLAVE:</span>  <span class="term-val text-warning">mysql-slave-0</span> <small class="text-muted">(Read)</small></div></div><?php endif; ?>
                        <div class="term-section"><span class="term-header">// SSH DIRECT ACCESS</span><div><span class="term-label">CMD:</span>  <span class="term-val text-success" id="disp-ssh-cmd"><?=htmlspecialchars($creds['ssh_cmd'] ?? 'Connecting...')?></span></div><div><span class="term-label">USER:</span> <span class="term-val">cliente</span></div><div><span class="term-label">PASS:</span> <span class="term-val" id="disp-ssh-pass"><?=htmlspecialchars($creds['ssh_pass'] ?? 'sylo1234')?></span></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card-clean">
                <div class="d-flex justify-content-between mb-3 align-items-center">
                    <h6 class="fw-bold m-0 text-muted small text-uppercase">Gestión Rápida</h6>
                    <button onclick="manualRefresh()" id="btn-refresh" class="btn btn-sm btn-light border-0 text-muted" title="Recargar Datos"><i class="bi bi-arrow-repeat"></i></button>
                </div>
                <?php if($has_web): ?>
                    <div class="mb-4">
                        <label class="small fw-bold mb-2 d-block text-secondary">Servicio Web</label>
                        <a href="#" target="_blank" id="btn-ver-web" class="btn btn-primary w-100 mb-2 fw-bold position-relative overflow-hidden border-0 <?=$web_url?'':'d-none'?>" style="background: linear-gradient(45deg, #2563eb, #3b82f6); box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);"><div id="web-loader-fill" style="position:absolute;top:0;left:0;height:100%;width:0%;background:rgba(255,255,255,0.4);transition:width 0.5s ease-out;"></div><span id="web-btn-text" style="position:relative; z-index:1;"><i class="bi bi-globe2 me-2"></i>Ver Sitio Web</span></a>
                        <div class="d-flex gap-2"><button class="btn btn-outline-dark w-50 btn-sm" onclick="showEditor()"><i class="bi bi-code-slash me-1"></i> Editor</button><button class="btn btn-outline-secondary w-50 btn-sm" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload me-1"></i> Subir</button></div>
                    </div><hr class="border-secondary opacity-10">
                <?php endif; ?>

                <label class="small fw-bold mb-2 d-block text-secondary">Energía</label>
                <form method="POST"><input type="hidden" name="order_id" value="<?=$current['id']?>">
                    <?php if($current['status']=='active'): ?>
                        <button name="action" value="restart" class="btn-action"><i class="bi bi-arrow-clockwise text-primary me-2"></i> Reiniciar Servidor</button>
                        <button name="action" value="stop" class="btn-action"><i class="bi bi-power text-danger me-2"></i> Apagar Sistema</button>
                    <?php else: ?>
                        <button name="action" value="start" class="btn-action"><i class="bi bi-play-fill text-success me-2 fs-5"></i> Encender Sistema</button>
                    <?php endif; ?>
                </form>
                
                <div id="backup-area" class="mt-4">
                    <label class="small fw-bold mb-2 d-block text-secondary d-flex justify-content-between">
                        <span>Copias de Seguridad</span>
                        <span class="badge bg-secondary opacity-50 small" id="backup-count">0/<?=$backup_limit?></span>
                    </label>
                    <button class="btn-action" data-bs-toggle="modal" data-bs-target="#backupTypeModal" id="btn-create-backup"><i class="bi bi-hdd-network text-info me-2"></i> Nueva Snapshot</button>
                    
                    <div id="backup-ui" style="display:none" class="mt-3"><div class="d-flex justify-content-between small text-muted mb-1"><span>Procesando...</span><span id="backup-pct">0%</span></div><div class="progress" style="height:8px"><div id="backup-bar" class="progress-bar bg-info progress-bar-striped progress-bar-animated" style="width:0%"></div></div></div>
                    
                    <div id="delete-ui" style="display:none" class="mt-3">
                        <div class="d-flex justify-content-between small text-danger mb-1"><span>Eliminando...</span><span id="delete-pct">0%</span></div>
                        <div class="progress" style="height:8px"><div id="delete-bar" class="progress-bar bg-danger progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
                    </div>

                    <div id="backups-list-container" class="mt-3"><small class="text-muted d-block text-center py-2">Cargando...</small></div>
                </div>
                
                <div class="mt-4 pt-3 border-top border-secondary border-opacity-10 text-center">
                    <button class="btn btn-link text-danger btn-sm text-decoration-none opacity-75 hover-opacity-100" onclick="confirmTerminate()">
                        <i class="bi bi-trash me-1"></i> Eliminar Servicio
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="backupTypeModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-hdd-fill me-2"></i>Nueva Snapshot</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="form-label small fw-bold">Nombre de la copia</label><input type="text" id="backup_name_input" class="form-control" placeholder="Ej: Antes de cambios..." maxlength="20"></div><div class="d-flex flex-column gap-2"><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="full" checked class="form-check-input mt-0"><div><div class="fw-bold">Completa (Full)</div><div class="small text-muted" style="font-size:0.75rem">Copia total.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="diff" class="form-check-input mt-0"><div><div class="fw-bold">Diferencial</div><div class="small text-muted" style="font-size:0.75rem">Cambios desde última Full.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="incr" class="form-check-input mt-0"><div><div class="fw-bold">Incremental</div><div class="small text-muted" style="font-size:0.75rem">Cambios desde última copia.</div></div></label></div><div id="limit-alert" class="alert alert-danger small mt-3 mb-0" style="display:none"><i class="bi bi-exclamation-octagon-fill me-1"></i> <strong>Límite alcanzado.</strong><br>Elimina una copia para continuar.</div><div id="normal-alert" class="alert alert-warning small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i> Límite de tu plan: <strong><?=$backup_limit?> copias</strong>.</div></div><div class="modal-footer border-0 px-4 pb-4"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button><button type="button" id="btn-start-backup" onclick="startBackup()" class="btn btn-primary rounded-pill px-4 fw-bold">Iniciar Copia</button></div></div></div></div>

<div class="modal fade" id="profileModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Perfil de Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><input type="hidden" name="action" value="update_profile"><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="form-label small fw-bold text-muted">Nombre Completo</label><input type="text" name="full_name" class="form-control bg-light" value="<?=htmlspecialchars($user_info['full_name']??'')?>"></div><div class="mb-3"><label class="form-label small fw-bold text-muted">Email</label><input type="email" name="email" class="form-control bg-light" value="<?=htmlspecialchars($user_info['email']??'')?>" required></div><div class="row"><div class="col-6 mb-3"><label class="form-label small fw-bold text-muted">DNI / NIF</label><input type="text" name="dni" class="form-control bg-light" value="<?=htmlspecialchars($user_info['dni']??'')?>"></div><div class="col-6 mb-3"><label class="form-label small fw-bold text-muted">Teléfono</label><input type="text" name="telefono" class="form-control bg-light" value="<?=htmlspecialchars($user_info['telefono']??'')?>"></div></div><div class="mb-3"><label class="form-label small fw-bold text-muted">Empresa / Razón Social</label><input type="text" name="company_name" class="form-control bg-light" value="<?=htmlspecialchars($user_info['company_name']??'')?>"></div><div class="mb-3"><label class="form-label small fw-bold text-muted">Dirección Fiscal</label><input type="text" name="calle" class="form-control bg-light" value="<?=htmlspecialchars($user_info['calle']??'')?>"></div></div><div class="modal-footer border-0 px-4 pb-4"><button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Guardar Datos</button></div></form></div></div></div>
<div class="modal fade" id="billingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Facturación (Semanal)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php foreach($clusters as $c): ?><div class="bill-row"><div>#<?=$c['id']?> <strong><?=$c['plan_name']?></strong></div><div class="text-success fw-bold"><?=number_format(calculateWeeklyPrice($c),2)?>€ / sem</div></div><?php endforeach; ?><hr><div class="d-flex justify-content-between fs-5"><strong>Total Semanal</strong><strong class="text-primary"><?=number_format($total_weekly,2)?>€</strong></div></div></div></div></div>
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Editor HTML</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div id="editor"></div></div><div class="modal-footer bg-light"><button class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-primary rounded-pill fw-bold" onclick="saveWeb()"><i class="bi bi-save me-2"></i>Publicar</button></div></div></div></div>
<div class="modal fade" id="uploadModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Subir Web</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="uploadForm" enctype="multipart/form-data"><input type="hidden" name="order_id" value="<?=$current['id']??0?>"><input type="hidden" name="action" value="upload_web"><div class="text-center mb-4"><i class="bi bi-cloud-upload display-4 text-primary opacity-50"></i></div><p class="text-center small text-muted mb-3">Sube aquí tu archivo html para empezar a crear tu web.</p><input type="file" name="html_file" class="form-control mb-4" accept=".html" required><button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold">Subir y Publicar</button></form></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const oid = <?=$current['id']??0?>; 
    const planCpus = <?=$plan_cpus?>; 
    const backupLimit = <?=$backup_limit?>;
    
    // Inicializar modales
    const editorModal = new bootstrap.Modal(document.getElementById('editorModal')); 
    const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal')); 
    const backupModal = new bootstrap.Modal(document.getElementById('backupTypeModal'));
    
    let aceEditor = null;
    const initialCode = <?php echo json_encode($html_code); ?>;
    
    document.addEventListener("DOMContentLoaded", function() { aceEditor = ace.edit("editor"); aceEditor.setTheme("ace/theme/monokai"); aceEditor.session.setMode("ace/mode/html"); aceEditor.setOptions({fontSize: "14pt"}); aceEditor.setValue(initialCode, -1); });
    function showEditor() { editorModal.show(); setTimeout(() => { aceEditor.resize(); }, 200); }
    function copyAllCreds() { navigator.clipboard.writeText(document.getElementById('all-creds-box').innerText); }
    
    function confirmTerminate() {
        const check = prompt("⚠️ ZONA DE PELIGRO ⚠️\n\nEsta acción borrará PERMANENTEMENTE tu servidor y todos sus datos.\nPara confirmar, escribe: eliminar");
        if (check === "eliminar") {
            // Envío AJAX manual para no recargar hasta que termine
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="terminate"><input type="hidden" name="order_id" value="${oid}">`;
            document.body.appendChild(form);
            form.submit();
        } else {
            alert("Operación cancelada. El texto no coincide.");
        }
    }

    function startBackup() {
        const typeEl = document.querySelector('input[name="backup_type"]:checked');
        const type = typeEl ? typeEl.value : 'full';
        const name = document.getElementById('backup_name_input').value || "Backup";
        
        backupModal.hide();
        document.getElementById('backup_name_input').value = ""; // Reset
        
        // FEEDBACK INMEDIATO
        const ui = document.getElementById('backup-ui');
        if(ui) ui.style.display='block';
        const bar = document.getElementById('backup-bar');
        const pct = document.getElementById('backup-pct');
        if(bar) bar.style.width='0%';
        if(pct) pct.innerText='0%';
        
        fetch('?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'backup', order_id:oid, backup_type:type, backup_name:name})}); 
    }
    
    function deleteBackup(file) { 
        if(confirm('¿Borrar esta copia de seguridad?')) { 
            const dui = document.getElementById('delete-ui');
            const dbar = document.getElementById('delete-bar');
            const dpct = document.getElementById('delete-pct');
            const list = document.getElementById('backups-list-container');
            
            if(list) list.style.display = 'none';
            if(dui) dui.style.display = 'block';
            if(dbar) dbar.style.width = '0%';
            if(dpct) dpct.innerText = '0%';
            
            fetch('?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'delete_backup', order_id:oid, filename:file})});
        } 
    }
    
    function manualRefresh() {
        const btn = document.getElementById('btn-refresh');
        if(btn) btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';
        fetch('?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'refresh_status', order_id:oid})})
        .then(() => { loadData(); });
    }
    
    function saveWeb() { 
        const wbtn = document.getElementById('btn-ver-web');
        if(wbtn) { wbtn.classList.add('disabled'); document.getElementById('web-loader-fill').style.width = '5%'; document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>Iniciando...'; }
        editorModal.hide(); 
        fetch('?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'update_web', order_id:oid, html_content:aceEditor.getValue()})});
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
        if(wbtn) { 
            wbtn.classList.add('disabled'); 
            document.getElementById('web-loader-fill').style.width = '5%'; 
            document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin me-2"></i>Iniciando...'; 
        }
        const formData = new FormData(this); 
        fetch('dashboard_cliente.php?ajax_action=1', { method: 'POST', body: formData });
    });

    function loadData() {
        if(!oid) return;
        fetch(`?ajax_data=1&id=${oid}&t=${new Date().getTime()}`)
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
                if(wurl) wurl.innerText = d.web_url; 
                if(btnw) { btnw.href = d.web_url; btnw.classList.remove('d-none'); }
            }
            
            // PROGRESO BACKUP
            const bUi = document.getElementById('backup-ui');
            const bBar = document.getElementById('backup-bar');
            const bPct = document.getElementById('backup-pct');
            const dUi = document.getElementById('delete-ui');
            const dBar = document.getElementById('delete-bar');
            const dPct = document.getElementById('delete-pct');
            const list = document.getElementById('backups-list-container');

            if(d.backup_progress) {
                if(d.backup_progress.status === 'creating') {
                    if(bUi) bUi.style.display='block';
                    if(dUi) dUi.style.display='none'; 
                    if(list) list.style.display='none'; 
                    if(bBar) bBar.style.width=d.backup_progress.progress+'%'; 
                    if(bPct) bPct.innerText=d.backup_progress.progress+'%';
                } else if(d.backup_progress.status === 'deleting') {
                    if(bUi) bUi.style.display='none'; 
                    if(dUi) dUi.style.display='block';
                    if(list) list.style.display='none';
                    if(dBar) dBar.style.width=d.backup_progress.progress+'%'; 
                    if(dPct) dPct.innerText=d.backup_progress.progress+'%';
                }
            } else {
                if(bUi) bUi.style.display='none';
                if(dUi) dUi.style.display='none';
                if(list) list.style.display='block';
            }
            
            // LIMITES
            const currentCount = d.backups_list ? d.backups_list.length : 0;
            const startBtn = document.getElementById('btn-start-backup');
            const limitAlert = document.getElementById('limit-alert');
            const normalAlert = document.getElementById('normal-alert');
            
            if(startBtn && limitAlert && normalAlert) {
                if(currentCount >= backupLimit) {
                    startBtn.disabled = true; startBtn.innerText = "Límite Alcanzado";
                    limitAlert.style.display = 'block'; normalAlert.style.display = 'none';
                } else {
                    startBtn.disabled = false; startBtn.innerText = "Iniciar Copia";
                    limitAlert.style.display = 'none'; normalAlert.style.display = 'block';
                }
            }

            // LISTA
            const listContainer = document.getElementById('backups-list-container');
            const countLabel = document.getElementById('backup-count');
            if(countLabel) countLabel.innerText = `${currentCount}/${backupLimit}`;
            
            if(listContainer && (!d.backup_progress)) {
                if(d.backups_list && d.backups_list.length > 0) {
                    const reversedList = [...d.backups_list].reverse();
                    let html = '';
                    reversedList.forEach(b => {
                        let typeClass = b.type == 'full' ? 'bg-primary' : (b.type == 'diff' ? 'bg-warning text-dark' : 'bg-info text-dark');
                        let typeName = b.type == 'full' ? 'FULL' : (b.type == 'diff' ? 'DIFF' : 'INCR');
                        html += `<div class="backup-item"><div><div class="fw-bold text-dark">${b.name}</div><div class="small text-muted"><span class="badge ${typeClass} badge-type me-1" style="font-size:0.6rem">${typeName}</span> ${b.date}</div></div><button onclick="deleteBackup('${b.file}')" class="btn btn-sm text-danger opacity-50 hover-opacity-100"><i class="bi bi-trash"></i></button></div>`;
                    });
                    listContainer.innerHTML = html;
                } else { 
                    listContainer.innerHTML = '<small class="text-muted d-block text-center py-2">Sin copias disponibles.</small>'; 
                }
            }
            
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
                    if(txt) txt.innerHTML = '<i class="bi bi-globe2 me-2"></i>Ver Sitio Web'; 
                }
            }
        }).catch(err => { console.log("Esperando datos...", err); });
    }

    let currentCountBeforeDelete = 0; 
    setInterval(loadData, 1500);
</script>
</body>
</html>