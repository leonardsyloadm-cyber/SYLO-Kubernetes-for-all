<?php
// logic: sylo-web/panel/php/data.php
session_start();
define('API_URL_BASE', 'http://172.17.0.1:8001/api/clientes'); // IP LINUX

// AUTH & DB
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header("Location: ../../public/index.php"); exit; }
if (!isset($_SESSION['user_id'])) { header("Location: ../../public/index.php"); exit; }

$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";

try { $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db); } catch(PDOException $e) { die("Error DB"); }

$user_id = $_SESSION['user_id'];

// --- ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] != 'update_profile') {
    $oid = $_POST['order_id'];
    $act = $_POST['action'];
    
    $data = [
        "id_cliente" => (int)$oid,
        "accion" => $act,
        "backup_type" => "full",
        "backup_name" => "Backup",
        "filename_to_restore" => "",
        "filename_to_delete" => "",
        "html_content" => ""
    ];

    // --- BACKUP ACTIONS WITH VISUAL FEEDBACK ---
    if ($act == 'backup' || $act == 'restore_backup' || $act == 'delete_backup') {
        $data['backup_type'] = $_POST['backup_type'] ?? 'full';
        $data['backup_name'] = $_POST['backup_name'] ?? 'Manual';
        if ($act == 'restore_backup') $data['filename_to_restore'] = $_POST['filename'];
        if ($act == 'delete_backup') $data['filename_to_delete'] = $_POST['filename'];

        // transient status setup
        $dir = __DIR__ . "/../../buzon-pedidos/";
        $f_back = $dir . "backup_status_{$oid}.json";
        $f_web = $dir . "web_status_{$oid}.json";   // CONFLICTO
        $f_pow = $dir . "power_status_{$oid}.json"; // CONFLICTO
        $f_gen = $dir . "status_{$oid}.json";       // CONFLICTO
        
        // Clean ALL old files to avoid priority conflicts (Fix: "Web Restaurada 100%" infinite loop)
        // If we don't delete web_status, data.php reads it first and ignores backup_status!
        if (file_exists($f_gen)) @unlink($f_gen);
        if (file_exists($f_web)) @unlink($f_web);
        if (file_exists($f_pow)) @unlink($f_pow);
        
        // Prepare Message
        $msg = "Procesando...";
        if ($act == 'backup') $msg = "Creando Snapshot...";
        if ($act == 'restore_backup') $msg = "Restaurando...";
        if ($act == 'delete_backup') $msg = "Eliminando...";

        // Write Transient
        $transient = ['status' => 'backup_processing', 'percent' => 0, 'msg' => $msg];
        file_put_contents($f_back, json_encode($transient));
    }
    
    // ACTION: Dismiss Backup (Frontend confirma que ya vio el 100%)
    if ($act == 'dismiss_backup') {
        $f_back = __DIR__ . "/../../buzon-pedidos/backup_status_{$oid}.json";
        if (file_exists($f_back)) @unlink($f_back);
        echo json_encode(['status'=>'ok']); exit;
    }

    if ($act == 'update_web') {
        $data['html_content'] = $_POST['html_content'];
        // SMART LINKING (Fix Blinking & Status Flop)
        // en vez de borrar todo (Reset), leemos el ultimo estado conocido
        // y creamos un archivo temporal 'web_status' con status='active'.
        // Asi el dashboard sigue viendo las metricas y el badge verde mientras arranca el Operator.
        
        $dir = __DIR__ . "/../../buzon-pedidos/";
        $f_web = $dir . "web_status_{$oid}.json";
        $f_gen = $dir . "status_{$oid}.json";
        $f_pow = $dir . "power_status_{$oid}.json";
        
        // 1. Intentar rescatar datos viejos (para no perder SSH/Metrics visualmente)
        $old_data = [];
        if (file_exists($f_web)) $old_data = json_decode(file_get_contents($f_web), true);
        else if (file_exists($f_gen)) $old_data = json_decode(file_get_contents($f_gen), true);
        
        // 2. Preparar el estado 'Transitorio'
        $transient = [
            'status' => 'active', // Mantiene el badge VERDE
            'percent' => 0,
            'msg' => 'Iniciando...', // Mensaje inicial
            'metrics' => $old_data['metrics'] ?? null,   // Persistir Metricas
            'ssh_cmd' => $old_data['ssh_cmd'] ?? null,   // Persistir SSH
            'web_url' => $old_data['web_url'] ?? null,   // Persistir URL
            'os_info' => $old_data['os_info'] ?? null,
            'ssh_pass'=> $old_data['ssh_pass'] ?? null
        ];
        
        // 3. Escribir el nuevo archivo web_status YA (para que data.php lo lea inmediatamente)
        file_put_contents($f_web, json_encode($transient));
        
        // 4. Borrar el genérico y power para evitar conflictos de prioridad
        if (file_exists($f_gen)) @unlink($f_gen);
        if (file_exists($f_pow)) @unlink($f_pow);
    }
    
    if ($act == 'destroy_k8s') {
        // No params needed
    }
    
    // CHAT
    if ($act == 'send_chat') {
        $data = ["id_cliente" => (int)$oid, "mensaje" => $_POST['message']];
        $ctx = stream_context_create(['http' => ['header'=>"Content-type: application/json\r\n",'method'=>'POST','content'=>json_encode($data),'timeout'=>2]]);
        @file_get_contents(API_URL_BASE . "/chat", false, $ctx);
        if(isset($_GET['ajax_action'])) { echo json_encode(['status' => 'ok']); exit; }
        return;
    }

    $ctx = stream_context_create(['http' => ['header'=>"Content-type: application/json\r\n",'method'=>'POST','content'=>json_encode($data),'timeout'=>2]]);
    @file_get_contents(API_URL_BASE . "/accion", false, $ctx);
    
    if(isset($_GET['ajax_action'])) { echo json_encode(['status' => 'ok']); exit; }
    header("Location: ../dashboard.php?id=$oid"); exit;
}

// --- AJAX DATA (GET) ---
if (isset($_GET['ajax_data'])) {
    header('Content-Type: application/json');
    $oid = $_GET['id'];
    $ctx = stream_context_create(['http'=> ['timeout' => 3]]);
    
    // Leer Chat si hay respuesta
    $chat_reply = null;
    $res_chat = @file_get_contents(API_URL_BASE . "/chat/leer/$oid", false, $ctx);
    if($res_chat) { $j=json_decode($res_chat,true); if($j && $j['reply']) $chat_reply=$j['reply']; }

    $json = @file_get_contents(API_URL_BASE . "/estado/$oid", false, $ctx);
    $final_data = $json ? json_decode($json, true) : ['error' => 'API Offline'];
    
    // FORCE UPDATE STATUS FROM DB (Source of Truth)
    try {
        $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$oid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $final_data['status'] = $row['status'];
        }
    } catch (Exception $e) {}

    if($chat_reply) $final_data['chat_reply'] = $chat_reply;

    // =========================================================================
    // 🛠️ FIX GEMINI: LECTURA DIRECTA DE DISCO (PRIORIDAD DE ARCHIVOS)
    // =========================================================================
    
    // Ruta calculada hacia 'buzon-pedidos' subiendo desde /var/www/html/sylo-web/panel/php/
    $buzon_dir = __DIR__ . "/../../buzon-pedidos/"; 
    
    // 1. LEER ARCHIVO DE PROGRESO CON PRIORIDAD
    // La API escribe en archivos distintos según el tipo (power, web, backup).
    // Debemos leer el más relevante.
    $prog_data = null;
    
    // Priority 1: Power Ops (Critical)
    if (file_exists($buzon_dir . "power_status_{$oid}.json")) {
        $prog_data = json_decode(@file_get_contents($buzon_dir . "power_status_{$oid}.json"), true);
    }
    // Priority 2: Web Updates (High User Vis)
    else if (file_exists($buzon_dir . "web_status_{$oid}.json")) {
        $prog_data = json_decode(@file_get_contents($buzon_dir . "web_status_{$oid}.json"), true);
    }
    // Priority 3: Backups
    else if (file_exists($buzon_dir . "backup_status_{$oid}.json")) {
        $prog_data = json_decode(@file_get_contents($buzon_dir . "backup_status_{$oid}.json"), true);
        if ($prog_data) $prog_data['source_type'] = 'backup'; // Tag for Frontend
    }
    // Priority 4: Generic/Legacy
    else if (file_exists($buzon_dir . "status_{$oid}.json")) {
        $prog_data = json_decode(@file_get_contents($buzon_dir . "status_{$oid}.json"), true);
    }

    if ($prog_data) {
        $final_data['general_progress'] = $prog_data;
        
        // 🔥 MERGE CRITICO (Bypass API Cache/Errors): Sobrescribir datos raíz
        if (isset($prog_data['metrics'])) $final_data['metrics'] = $prog_data['metrics'];
        if (isset($prog_data['ssh_cmd'])) $final_data['ssh_cmd'] = $prog_data['ssh_cmd'];
        if (isset($prog_data['web_url'])) $final_data['web_url'] = $prog_data['web_url'];
        if (isset($prog_data['os_info'])) $final_data['os_info'] = $prog_data['os_info'];
        
        // Solo sobrescribir status si no es nulo
        if (isset($prog_data['status']) && !empty($prog_data['status'])) {
             // FIX: Prevent stale 'creating' status from file overriding 'active' status from DB
             $db_is_active = in_array(strtolower($final_data['status'] ?? ''), ['active', 'running', 'online']);
             $file_is_creating = in_array(strtolower($prog_data['status']), ['creating', 'provisioning']);
             
             if ($db_is_active && $file_is_creating) {
                 // Ignore file status, keep DB status
             } else {
                 $final_data['status'] = $prog_data['status'];
             }
        } 
    }

    // 2. LEER LISTA DE BACKUPS (.tar.gz) DEL DISCO
    // El orquestador guarda los backups aquí. PHP los lee directamente.
    $files = glob($buzon_dir . "backup_v{$oid}_*.tar.gz");
    $backups_list = [];
    
    if ($files) {
        foreach ($files as $f) {
            $base = basename($f);
            $parts = explode('_', $base);
            // Formato esperado: backup_vID_TYPE_NAME_DATE.tar.gz
            // Ejemplo: backup_v47_FULL_Manual_20250115203000.tar.gz
            if (count($parts) >= 4) {
                // Adaptive Parsing: Handle legacy (5 parts) vs new (4 parts) filenames
                // Legacy: backup_v_48_FULL_Name_Date (5 parts)
                // New:    backup_v48_FULL_Name_Date (4 parts)
                $idx_type = count($parts) == 5 ? 2 : 1;
                $idx_name = count($parts) == 5 ? 3 : 2;
                $idx_date = count($parts) == 5 ? 4 : 3;
                
                $ts = explode('.', $parts[$idx_date])[0];
                $date_fmt = substr($ts,6,2)."/".substr($ts,4,2)." ".substr($ts,8,2).":".substr($ts,10,2);
                
                $backups_list[] = [
                    "file" => $base,
                    "name" => $parts[$idx_name],
                    "type" => strtoupper($parts[$idx_type]),
                    "date" => $date_fmt
                ];
            }
        }
    }
    // Sobreescribimos la lista vacía de la API con la lista REAL del disco
    $final_data['backups_list'] = $backups_list;
    // =========================================================================
    
    echo json_encode($final_data); 
    exit;
}

// UPDATE PROFILE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $sql = "UPDATE users SET full_name=?, email=?, dni=?, telefono=?, company_name=?, calle=? WHERE id=?";
    $conn->prepare($sql)->execute([$_POST['full_name'], $_POST['email'], $_POST['dni'], $_POST['telefono'], $_POST['company_name'], $_POST['calle'], $user_id]);
    header("Location: ../dashboard.php"); exit;
}
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$user_id]); 
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user_info) $user_info = [];

// HELPERS
function calculateWeeklyPrice($r) { 
    $p=match($r['plan_name']??''){'Bronce'=>5,'Plata'=>15,'Oro'=>30,default=>0}; 
    if(($r['plan_name']??'')=='Personalizado'){
        $p=(intval($r['custom_cpu']??0)*5)+(intval($r['custom_ram']??0)*5); 
        if(!empty($r['db_enabled']))$p+=10; if(!empty($r['web_enabled']))$p+=10;
    } 
    return $p/4; 
}
function getBackupLimit($r) { 
    $m=match($r['plan_name']??''){'Bronce'=>5,'Plata'=>15,'Oro'=>30,default=>0}; 
    if(($r['plan_name']??'')=='Personalizado'){
        $m=(intval($r['custom_cpu']??0)*5)+(intval($r['custom_ram']??0)*5); 
        if(!empty($r['db_enabled']))$m+=10; if(!empty($r['web_enabled']))$m+=10;
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

// --- INIT DATA ---
$sql = "SELECT o.*, p.name as plan_name, p.cpu_cores as p_cpu, p.ram_gb as p_ram, os.cpu_cores as custom_cpu, os.ram_gb as custom_ram, os.db_enabled, os.web_enabled, os.os_image, os.tools, os.cluster_alias FROM orders o JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE user_id=? AND status!='cancelled' ORDER BY o.id DESC";
$stmt = $conn->prepare($sql); $stmt->execute([$_SESSION['user_id']]); $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = null; 
$os_image = 'ubuntu'; 
$total_weekly = 0; 
$creds = ['ssh_cmd'=>'Esperando...', 'ssh_pass'=>'...'];
$web_url = null;
$html_code = "<!DOCTYPE html>\n<html>\n<body>\n<h1>Welcome to Sylo</h1>\n</body>\n</html>";
$installed_tools = [];

foreach($clusters as $c) $total_weekly += calculateWeeklyPrice($c);

if($clusters) {
    $current = (isset($_GET['id'])) ? array_values(array_filter($clusters, fn($c)=>$c['id']==$_GET['id']))[0] ?? $clusters[0] : $clusters[0];
    if ($current['plan_name'] == 'Personalizado') $plan_cpus = intval($current['custom_cpu'] ?? 1); else $plan_cpus = intval($current['p_cpu'] ?? 1); 
    if ($plan_cpus < 1) $plan_cpus = 1;
    $backup_limit = getBackupLimit($current);
    $has_web = ($current['plan_name'] === 'Oro' || (!empty($current['web_enabled']) && $current['web_enabled'] == 1));
    $has_db = ($current['plan_name'] === 'Oro' || (!empty($current['db_enabled']) && $current['db_enabled'] == 1));
    $os_image = $current['os_image'] ?? 'ubuntu';
    
    $ctx = stream_context_create(['http'=> ['timeout' => 3]]);
    $api_init = @file_get_contents(API_URL_BASE . "/estado/{$current['id']}", false, $ctx);
    
    if($api_init) { 
        $d = json_decode($api_init, true); 
        if($d) {
            $creds['ssh_cmd'] = $d['ssh_cmd'] ?? 'Esperando...';
            $creds['ssh_pass'] = $d['ssh_pass'] ?? '...';
            $web_url = $d['web_url'] ?? null;
            if(isset($d['html_source']) && !empty($d['html_source'])) { $html_code = $d['html_source']; }
            
            $tools_db = (!empty($current['tools'])) ? json_decode($current['tools'], true) : [];
            if(!empty($tools_db)) {
                $installed_tools = $tools_db;
            } else {
                $installed_tools = $d['installed_tools'] ?? [];
            }
        }
    }
}
?>