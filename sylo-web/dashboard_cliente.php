<?php
session_start();
// --- 1. SEGURIDAD Y CONEXIÓN DB ---
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

// --- 2. HELPERS ---
function calculateWeeklyPrice($row) {
    $price = match($row['plan_name']) { 'Bronce'=>5, 'Plata'=>15, 'Oro'=>30, default=>0 };
    if($row['plan_name'] == 'Personalizado') {
        $price = (intval($row['custom_cpu'])*5) + (intval($row['custom_ram'])*5);
        if(!empty($row['db_enabled'])) $price += 10;
        if(!empty($row['web_enabled'])) $price += 10;
    }
    return $price;
}

// --- 3. API AJAX ---
if (isset($_GET['ajax_data']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $oid = $_GET['id'];
    
    $json = @file_get_contents("$buzon_path/status_$oid.json");
    $backup = @file_get_contents("$buzon_path/backup_info_$oid.json");
    $web_status = @file_get_contents("$buzon_path/web_status_$oid.json");
    
    $data = json_decode($json, true) ?? [];
    
    echo json_encode([
        'metrics' => $data['metrics'] ?? ['cpu' => 0, 'ram' => 0],
        'ssh_cmd' => $data['ssh_cmd'] ?? 'Conectando...',
        'ssh_pass' => $data['ssh_pass'] ?? '...',
        'web_url' => $data['web_url'] ?? null,
        'db_host' => $data['db_host'] ?? null,
        'backup' => json_decode($backup, true),
        'web_progress' => json_decode($web_status, true)
    ]);
    exit;
}

// --- 4. PROCESAR ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $oid = $_POST['order_id'];
    $act = $_POST['action'];
    $data = ["id" => (int)$oid, "action" => strtoupper($act), "user" => $_SESSION['username']];
    
    if($act == 'update_web') {
        $html_content = $_POST['html_content'];
        $data['html_content'] = $html_content;
        file_put_contents("$buzon_path/web_source_{$oid}.html", $html_content);
        @chmod("$buzon_path/web_source_{$oid}.html", 0666);
    }
    
    $fname = match($act) { 'terminate'=>'terminate', 'backup'=>'backup', 'update_web'=>'update_web', default=>$act };
    file_put_contents("$buzon_path/accion_{$oid}_{$fname}.json", json_encode($data));
    
    if($act == 'backup') @unlink("$buzon_path/backup_info_{$oid}.json");
    if($act == 'update_web') @unlink("$buzon_path/web_status_{$oid}.json");
    
    if(isset($_GET['ajax_action'])) { header('Content-Type: application/json'); echo json_encode(['status'=>'ok']); exit; }
    header("Location: dashboard_cliente.php?id=$oid"); exit;
}

// --- 5. DATOS ---
$sql = "SELECT o.*, o.purchase_date, p.name as plan_name, os.cpu_cores as custom_cpu, os.ram_gb as custom_ram, os.db_enabled, os.web_enabled FROM orders o JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE user_id=? AND status!='cancelled' ORDER BY o.id DESC";
$stmt = $conn->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = null;
$creds = ['ssh_cmd'=>'Esperando...', 'ssh_pass'=>'...'];
$actualUser = 'user';
$web_url = null; $backup_info = null; $has_web = false; $has_db = false;
$total_weekly = 0;

foreach($clusters as $c) $total_weekly += calculateWeeklyPrice($c);

if($clusters) {
    $current = (isset($_GET['id'])) ? array_values(array_filter($clusters, fn($c)=>$c['id']==$_GET['id']))[0] ?? $clusters[0] : $clusters[0];
    
    if ($current['plan_name'] === 'Oro' || (!empty($current['web_enabled']) && $current['web_enabled'] == 1)) { $has_web = true; }
    if ($current['plan_name'] === 'Oro' || (!empty($current['db_enabled']) && $current['db_enabled'] == 1)) { $has_db = true; }
    
    $file_path = "$buzon_path/status_{$current['id']}.json";
    if (file_exists($file_path)) {
        $raw = file_get_contents($file_path);
        $status_data = json_decode($raw, true);
        $creds = array_merge($creds, $status_data);
        if (isset($status_data['web_url'])) $web_url = $status_data['web_url'];
        if (preg_match('/ssh\s+(.*?)@/', $creds['ssh_cmd'], $m)) $actualUser = $m[1];
    }
    
    $bak_path = "$buzon_path/backup_info_{$current['id']}.json";
    if(file_exists($bak_path)) $backup_info = json_decode(file_get_contents($bak_path), true);
    
    $src_file = "$buzon_path/web_source_{$current['id']}.html";
    $html_code = file_exists($src_file) ? file_get_contents($src_file) : "<h1>Hola Mundo</h1>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Panel SYLO | <?php echo $_SESSION['username']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js"></script>
    <style>
        :root { --sylo-blue: #2563eb; --sylo-dark: #0f172a; --sylo-bg: #f8fafc; }
        body { font-family: 'Outfit', sans-serif; background-color: var(--sylo-bg); color: #334155; overflow-x: hidden; }
        .sidebar { height: 100vh; background: #ffffff; border-right: 1px solid #e2e8f0; padding-top: 25px; position: fixed; width: 260px; z-index: 1000; box-shadow: 4px 0 24px rgba(0,0,0,0.02); }
        .sidebar .nav-link { color: #64748b; padding: 14px 24px; margin: 4px 16px; border-radius: 12px; transition: all 0.2s; font-weight: 500; display: flex; align-items: center; }
        .sidebar .nav-link:hover { color: var(--sylo-blue); background: #eff6ff; transform: translateX(3px); }
        .sidebar .nav-link.active { background: linear-gradient(135deg, var(--sylo-blue), #3b82f6); color: white; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }
        .main-content { margin-left: 260px; padding: 40px; animation: fadeInUp 0.5s ease-out; }
        
        .card-clean { background: white; border: none; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); padding: 1.8rem; height: 100%; transition: transform 0.3s ease; }
        .metric-card { background: white; border-radius: 20px; padding: 25px; position: relative; overflow: hidden; box-shadow: 0 10px 20px rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.03); transition: transform 0.2s; }
        .metric-card.cpu { border-left: 6px solid #2563eb; background: linear-gradient(145deg, #ffffff 40%, #eff6ff 100%); }
        .metric-card.ram { border-left: 6px solid #10b981; background: linear-gradient(145deg, #ffffff 40%, #f0fdf4 100%); }
        .metric-card .metric-value { font-size: 2.8rem; font-weight: 800; line-height: 1; letter-spacing: -1.5px; }
        .progress-thin { height: 10px; border-radius: 20px; background: #e2e8f0; margin-top: 18px; overflow: hidden; }
        .progress-bar { border-radius: 20px; transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1); height: 100%; }
        #cpu-bar { background-color: #2563eb; } #ram-bar { background-color: #10b981; }
        .icon-bg { position: absolute; right: -15px; bottom: -15px; font-size: 6rem; opacity: 0.08; transform: rotate(-10deg); pointer-events: none; }
        .plan-badge { font-size: 0.7rem; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; font-weight: 700; }
        .bg-bronce { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
        .bg-plata { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .bg-oro { background: #fefce8; color: #b45309; border: 1px solid #fef3c7; }
        .bg-personalizado { background: #eff6ff; color: #2563eb; border: 1px solid #dbeafe; }
        
        /* ESTILO TERMINAL CREDENCIALES */
        .terminal-container { background: #0f172a; border-radius: 12px; padding: 0; overflow: hidden; margin-top: 15px; }
        .terminal-body { padding: 20px; font-family: 'Fira Code', monospace; font-size: 0.85rem; color: #e2e8f0; line-height: 1.6; }
        .term-label { color: #64748b; font-weight: bold; letter-spacing: 0.5px; margin-right: 10px; user-select: none; }
        .term-val { color: #a5b4fc; }
        .term-section { margin-bottom: 15px; border-bottom: 1px dashed #334155; padding-bottom: 10px; }
        .term-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .term-header { color: #22d3ee; font-weight: bold; margin-bottom: 5px; display: block; text-transform: uppercase; font-size: 0.75rem; }

        .btn-sylo { background: linear-gradient(135deg, var(--sylo-blue), #3b82f6); color: white; border:none; padding: 10px 24px; border-radius: 8px; font-weight: 600; }
        .btn-action { width: 100%; text-align: left; margin-bottom: 8px; padding: 12px 16px; font-size: 0.95rem; border-radius: 8px; display: flex; align-items: center; border: 1px solid #f1f5f9; background: white; color: #475569; transition: all 0.2s; font-weight: 500; }
        .btn-action:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--sylo-blue); }
        .bill-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; align-items: center; }
        #editor { width: 100%; height: 500px; font-size: 14px; border-radius: 0 0 8px 8px; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .spin { animation: spin 1s linear infinite; display: inline-block; }
    </style>
</head>
<body>
<div class="sidebar d-flex flex-column p-3">
    <div class="px-2 mb-4"><h4 class="fw-bold text-dark"><i class="bi bi-hdd-network-fill text-primary me-2"></i>SYLO Cloud</h4></div>
    <ul class="nav flex-column mb-auto">
        <li><a href="index.php" class="nav-link"><i class="bi bi-plus-circle me-2"></i> Nuevo Servicio</a></li>
        <li><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#billingModal"><i class="bi bi-receipt me-2"></i> Facturación</a></li>
        <li><a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i> Salir</a></li>
    </ul>
    <div class="mt-4 px-2 mb-2 text-muted fw-bold small">SERVIDORES</div>
    <div class="d-flex flex-column gap-1">
        <?php foreach($clusters as $c): $cls = ($current && $c['id']==$current['id'])?'active':''; $pcl = 'bg-'.strtolower($c['plan_name']=='Personalizado'?'personalizado':$c['plan_name']); ?>
        <a href="?id=<?=$c['id']?>" class="nav-link <?=$cls?> justify-content-between"><span>#<?=$c['id']?></span><span class="plan-badge <?=$pcl?>"><?=substr($c['plan_name'],0,3)?></span></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="main-content">
    <?php if (!$current): ?><div class="text-center py-5"><h3>Sin servicios</h3></div><?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2 class="fw-bold mb-0">Servidor #<?=$current['id']?></h2><span class="badge bg-success bg-opacity-10 text-success">ACTIVO</span></div>
        <a href="index.php" class="btn btn-outline-primary btn-sm">Nuevo</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="row g-4 mb-4">
                <div class="col-md-6"><div class="metric-card cpu"><i class="bi bi-cpu-fill icon-bg"></i><div class="metric-label">CPU</div><div class="metric-value" style="color:#2563eb"><span id="cpu-val">0</span>%</div><div class="progress-thin"><div id="cpu-bar" class="progress-bar" style="width:0%"></div></div></div></div>
                <div class="col-md-6"><div class="metric-card ram"><i class="bi bi-memory icon-bg"></i><div class="metric-label">RAM</div><div class="metric-value" style="color:#10b981"><span id="ram-val">0</span>%</div><div class="progress-thin"><div id="ram-bar" class="progress-bar" style="width:0%"></div></div></div></div>
            </div>
            
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between mb-3 align-items-center">
                    <h5 class="fw-bold m-0"><i class="bi bi-terminal-fill me-2"></i>Credenciales de Acceso</h5>
                    <button onclick="copyAllCreds()" class="btn btn-sm btn-light border"><i class="bi bi-clipboard"></i> Copiar Todo</button>
                </div>
                
                <div class="terminal-container" id="all-creds-box">
                    <div class="terminal-body">
                        
                        <?php if($has_web): ?>
                        <div class="term-section">
                            <span class="term-header">// WEB SERVER ACCESS</span>
                            <div><span class="term-label">URL:</span> <span class="term-val" id="disp-web-url"><?=htmlspecialchars($web_url)?></span></div>
                        </div>
                        <?php endif; ?>

                        <?php if($has_db): ?>
                        <div class="term-section">
                            <span class="term-header">// DATABASE CLUSTER</span>
                            <div><span class="term-label">MASTER:</span> <span class="term-val text-warning">mysql-master-0</span> (Read/Write)</div>
                            <div><span class="term-label">SLAVE:</span>  <span class="term-val text-warning">mysql-slave-0</span> (Read Only)</div>
                            <div><span class="term-label">HOST:</span>   <span class="term-val" id="disp-db-host"><?=htmlspecialchars($creds['db_host']??'Pending...')?></span></div>
                        </div>
                        <?php endif; ?>

                        <div class="term-section">
                            <span class="term-header">// SSH SERVER ACCESS</span>
                            <div><span class="term-label">CMD:</span>  <span class="term-val text-success" id="disp-ssh-cmd"><?=htmlspecialchars($creds['ssh_cmd'])?></span></div>
                            <div><span class="term-label">USER:</span> <span class="term-val">cliente</span></div>
                            <div><span class="term-label">PASS:</span> <span class="term-val"><?=htmlspecialchars($creds['ssh_pass'])?></span></div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card-clean">
                <h6 class="fw-bold mb-3 text-muted small">GESTIÓN</h6>
                
                <?php if($has_web): ?>
                    <div class="mb-3">
                        <label class="small fw-bold mb-2 d-block">Web</label>
                        <a href="#" target="_blank" id="btn-ver-web" class="btn btn-primary w-100 mb-2 fw-bold position-relative overflow-hidden <?=$web_url?'':'d-none'?>">
                            <div id="web-loader-fill" style="position:absolute;top:0;left:0;height:100%;width:0%;background:rgba(255,255,255,0.3);transition:width 0.3s;"></div>
                            <span id="web-btn-text"><i class="bi bi-globe2 me-2"></i>Ver Sitio Web</span>
                        </a>
                        <button class="btn btn-outline-dark w-100 btn-sm" onclick="showEditor()"><i class="bi bi-code-slash me-2"></i>Editar HTML</button>
                    </div>
                    <hr>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="order_id" value="<?=$current['id']?>">
                    <?php if($current['status']=='active'): ?>
                        <button name="action" value="restart" class="btn-action"><i class="bi bi-arrow-clockwise text-primary"></i> Reiniciar</button>
                        <button name="action" value="stop" class="btn-action"><i class="bi bi-power text-danger"></i> Apagar</button>
                    <?php else: ?>
                        <button name="action" value="start" class="btn-action"><i class="bi bi-play text-success"></i> Encender</button>
                    <?php endif; ?>
                </form>
                
                <div id="backup-area" class="mt-3">
                    <button onclick="doBackup()" class="btn-action"><i class="bi bi-hdd-network text-secondary"></i> Snapshot</button>
                    <div id="backup-ui" style="display:none" class="mt-2"><div class="progress" style="height:4px"><div id="backup-bar" class="progress-bar bg-info" style="width:0%"></div></div><small class="text-center d-block" id="backup-txt">...</small></div>
                    <div id="backup-ok" class="alert alert-success p-2 small mt-2 mb-0" style="display:none"></div>
                </div>
                
                <div class="mt-4 text-center"><button class="btn btn-link text-danger btn-sm text-decoration-none" data-bs-toggle="modal" data-bs-target="#delModal">Eliminar</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="billingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Facturación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php foreach($clusters as $c): ?><div class="bill-row"><div>#<?=$c['id']?> <?=$c['plan_name']?></div><div><?=number_format(calculateWeeklyPrice($c),2)?>€</div></div><?php endforeach; ?><hr><div class="d-flex justify-content-between"><strong>Total</strong><strong><?=number_format($total_weekly,2)?>€</strong></div></div></div></div></div>
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Editor HTML</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div id="editor"></div></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" onclick="saveWeb()">Publicar Cambios</button></div></div></div></div>
<div class="modal fade" id="delModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-danger"><div class="modal-body text-center p-4"><h4>¿Eliminar?</h4><form method="POST"><input type="hidden" name="order_id" value="<?=$current['id']??0?>"><button name="action" value="terminate" class="btn btn-danger w-100">Sí, Eliminar</button></form></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const oid = <?=$current['id']??0?>; const editorModal = new bootstrap.Modal('#editorModal'); let aceEditor = null;
    const initialCode = <?php echo json_encode($html_code); ?>;
    
    document.addEventListener("DOMContentLoaded", function() { aceEditor = ace.edit("editor"); aceEditor.setTheme("ace/theme/monokai"); aceEditor.session.setMode("ace/mode/html"); aceEditor.setOptions({fontSize: "14pt"}); aceEditor.setValue(initialCode, -1); });
    function showEditor() { editorModal.show(); setTimeout(() => { aceEditor.resize(); }, 200); }
    function copyAllCreds() { navigator.clipboard.writeText(document.getElementById('all-creds-box').innerText); }
    
    function doBackup() { document.getElementById('backup-ui').style.display='block'; document.getElementById('backup-ok').style.display='none'; fetch('?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'backup', order_id:oid})}); }
    
    function saveWeb() {
        const content = aceEditor.getValue();
        const btn = document.querySelector('#editorModal .btn-primary'); const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Publicando...'; btn.disabled = true;
        fetch('?ajax_action=1', {method:'POST', body:new URLSearchParams({action:'update_web', order_id:oid, html_content:content})}).then(() => {
            editorModal.hide(); btn.innerHTML = orig; btn.disabled = false;
            const wbtn = document.getElementById('btn-ver-web');
            if(wbtn) { 
                document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Actualizando...'; 
                wbtn.classList.add('disabled'); 
            }
        });
    }

    setInterval(() => {
        if(!oid) return;
        fetch(`?ajax_data=1&id=${oid}&t=${new Date().getTime()}`).then(r=>r.json()).then(d => {
            if(d.metrics) { 
                document.getElementById('cpu-val').innerText = d.metrics.cpu; 
                document.getElementById('ram-val').innerText = d.metrics.ram; 
                document.getElementById('cpu-bar').style.width = parseFloat(d.metrics.cpu)+'%'; 
                document.getElementById('ram-bar').style.width = parseFloat(d.metrics.ram)+'%'; 
            }
            
            // ACTUALIZAR CREDENCIALES DINÁMICAS
            if(d.ssh_cmd) document.getElementById('disp-ssh-cmd').innerText = d.ssh_cmd;
            if(d.web_url) {
                const el = document.getElementById('disp-web-url');
                if(el) el.innerText = d.web_url;
            }
            if(d.db_host) {
                const el = document.getElementById('disp-db-host');
                if(el) el.innerText = d.db_host;
            }
            
            const wbtn = document.getElementById('btn-ver-web');
            if(d.web_url && wbtn) {
                if(wbtn.classList.contains('d-none')) wbtn.classList.remove('d-none');
                wbtn.href = d.web_url;
            }

            if(d.backup && d.backup.status=='creating') { document.getElementById('backup-ui').style.display='block'; document.getElementById('backup-bar').style.width=d.backup.progress+'%'; document.getElementById('backup-txt').innerText=d.backup.msg; }
            else if(d.backup && d.backup.status=='completed') { document.getElementById('backup-ui').style.display='none'; const ok=document.getElementById('backup-ok'); ok.style.display='block'; ok.innerText="Backup OK: "+d.backup.file; }
            
            if(wbtn && d.web_progress) {
                if (d.web_progress.progress < 100) {
                    document.getElementById('web-loader-fill').style.width = d.web_progress.progress+'%';
                    document.getElementById('web-btn-text').innerHTML = `<i class="bi bi-arrow-repeat spin me-2"></i>${d.web_progress.msg}`;
                } else {
                    document.getElementById('web-loader-fill').style.width = '0%';
                    document.getElementById('web-btn-text').innerHTML = '<i class="bi bi-globe2 me-2"></i>Ver Sitio Web';
                    wbtn.classList.remove('disabled');
                }
            } else if (wbtn && !wbtn.classList.contains('disabled') && !d.web_progress) {
                 const txt = document.getElementById('web-btn-text');
                 if(txt.innerText.includes('Actualizando')) { txt.innerHTML = `<i class="bi bi-globe2 me-2"></i>Ver Sitio Web`; }
            }
        });
    }, 2000);
</script>
</body>
</html>