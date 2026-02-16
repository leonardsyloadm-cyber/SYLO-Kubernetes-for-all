<?php
// logic: sylo-web/panel/php/data.php
// --- SECURITY CONFIG ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'
    ]);
    session_start();
}

define('API_URL_BASE', 'http://172.18.0.1:8001/api/clientes'); // IP LINUX (Docker Gateway - Detected)

// AUTH & DB
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header("Location: ../../public/index.php"); exit; }
if (!isset($_SESSION['user_id'])) { header("Location: ../../public/index.php"); exit; }

$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "sylo_admin_db";
$dbport = getenv('DB_PORT') ?: "3306";

try { 
    $conn = new PDO("mysql:host=$servername;port=$dbport;dbname=$dbname;charset=utf8mb4", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("Error DB"); }

$user_id = $_SESSION['user_id'];

// 🛡️ HELPER: Verify Ownership (Prevent IDOR)
function verifyOwnership($conn, $oid, $uid) {
    if (!$oid) return false;
    $stmt = $conn->prepare("SELECT id FROM k8s_deployments WHERE id = ? AND user_id = ?");
    $stmt->execute([$oid, $uid]);
    return (bool)$stmt->fetch();
}

// --- ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !in_array($_POST['action'], ['update_profile', 'install_monitoring', 'uninstall_monitoring'])) {
    // WRAPPER: Resilience against crashes
    try {
        $oid = (int)$_POST['order_id'];
        $act = $_POST['action'];
        
        // 🛡️ CRITICAL: Verify User Owns This Deployment
        if (!verifyOwnership($conn, $oid, $user_id)) {
            http_response_code(403);
            die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access (IDOR Protected)']));
        }
        
        $data = [
            "id_cliente" => $oid,
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

        // ACTION: Dismiss Plan Update (Fix: Prevent Infinite Reload Loop)
        if ($act == 'dismiss_plan') {
            $f_plan = __DIR__ . "/../../buzon-pedidos/plan_status_{$oid}.json";
            if (file_exists($f_plan)) @unlink($f_plan);
            echo json_encode(['status'=>'ok']); exit;
        }

        // ACTION: Dismiss Tool Install
        if ($act == 'dismiss_install_tool') {
            $f_inst = __DIR__ . "/../../buzon-pedidos/install_status_{$oid}.json";
            if (file_exists($f_inst)) @unlink($f_inst);
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
            if (file_exists($f_gen)) @unlink($f_gen);
            if (file_exists($f_pow)) @unlink($f_pow);
        }
        
        // ACTION: CHANGE PLAN (Prioridad 1)
        if ($act == 'change_plan') {
           $new_plan = $_POST['new_plan'];
           
           // Definir Specs por defecto según el plan
           $plan_id = 1;
           $cpu = 1; $ram = 1; $db_en = 0; $web_en = 0;
           
           if ($new_plan == 'Bronce') { 
               $plan_id = 1; $cpu = 1; $ram = 1; 
           } elseif ($new_plan == 'Plata') { 
               $plan_id = 2; $cpu = 2; $ram = 2; 
           } elseif ($new_plan == 'Oro') { 
               $plan_id = 3; $cpu = 4; $ram = 4; $db_en = 1; $web_en = 1; 
           } elseif ($new_plan == 'Personalizado') {
               $plan_id = 4;
               $cpu = (int)($_POST['custom_cpu'] ?? 1);
               $ram = (int)($_POST['custom_ram'] ?? 1);
               $db_en = isset($_POST['custom_db']) ? 1 : 0;
               $web_en = isset($_POST['custom_web']) ? 1 : 0;
           }
           
           // Update 'k8s_deployments' table (Unified)
           $sql_update = "UPDATE k8s_deployments SET plan_id = ?, cpu_cores = ?, ram_gb = ?, db_enabled = ?, web_enabled = ? WHERE id = ?";
           $stmt = $conn->prepare($sql_update);
           $stmt->execute([$plan_id, $cpu, $ram, $db_en, $web_en, $oid]);
           
           // Generate Action JSON for Operator
           $action_file = __DIR__ . "/../../buzon-pedidos/accion_update_{$oid}.json";
           $payload = [
               "action" => "UPDATE_PLAN",
               "id_cliente" => (int)$oid,
               "new_specs" => [
                   "cpu" => $cpu,
                   "ram" => $ram,
                   "db_enabled" => $db_en,
                   "web_enabled" => $web_en
               ]
           ];
           file_put_contents($action_file, json_encode($payload));

           // --- BACKUP LIMIT ENFORCEMENT ---
           // Calculate new limit
           $new_limit = 2; // Default (Bronce)
           if ($new_plan == 'Oro') $new_limit = 5;
           elseif ($new_plan == 'Plata') $new_limit = 3;
           elseif ($new_plan == 'Personalizado') {
               $score = ($cpu * 5) + ($ram * 5);
               if ($db_en) $score += 10;
               if ($web_en) $score += 10;
               if ($score >= 30) $new_limit = 5;
               elseif ($score >= 15) $new_limit = 3;
           }

           // Scan and Clean Excess
           $buzon_dir = __DIR__ . "/../../buzon-pedidos/";
           $files = glob($buzon_dir . "backup_v{$oid}_*.tar.gz");
           if ($files && count($files) > $new_limit) {
               // Sort by modification time DESC (Newest first)
               usort($files, function($a, $b) {
                   return filemtime($b) - filemtime($a);
               });
               
               // Keep first $new_limit, delete the rest
               $to_delete = array_slice($files, $new_limit);
               foreach ($to_delete as $f) {
                   if (file_exists($f)) @unlink($f);
               }
           }
           // --------------------------------

           // CLEANUP CONFLICTING STATUS FILES (Fix: Stuck Progress Bar)
           // We must remove power/web/backup and PLAN status files so data.php reads our new status_{oid}.json
           $f_pow = $buzon_dir . "power_status_{$oid}.json";
           $f_web = $buzon_dir . "web_status_{$oid}.json";
           $f_back = $buzon_dir . "backup_status_{$oid}.json";
           $f_plan = $buzon_dir . "plan_status_{$oid}.json";

           if (file_exists($f_pow)) @unlink($f_pow);
           if (file_exists($f_web)) @unlink($f_web);
           if (file_exists($f_back)) @unlink($f_back);
           if (file_exists($f_plan)) @unlink($f_plan);

           // Set Transient Status - Starts at 10%
           $f_upd = $buzon_dir . "plan_status_{$oid}.json";
           $transient = [
               'action' => 'plan_update',
               'status' => 'updating_plan',
               'percent' => 5,
               'msg' => "Iniciando cambio de plan a {$new_plan}...",
               // FIX: Pass web_url here too so it overrides the empty DB value immediately
               'web_url' => ($web_en == 1) ? "http://{$oid}.sylo.local" : null
           ];
           file_put_contents($f_upd, json_encode($transient));

           // --- FIX: AUTO-PROVISION WEB IF ENABLED (Visual Upgrade Experience) ---
           if ($web_en == 1) {
               $f_web = $buzon_dir . "web_status_{$oid}.json";
               // Only trigger if no existing web status (avoid overwriting existing site state if re-applying)
               if (!file_exists($f_web)) {
                   $web_transient = [
                       'status' => 'active', // Force Green Badge
                       'percent' => 0,
                       'msg' => 'Provisioning Web...',
                       'web_url' => "http://{$oid}.sylo.local", // Temporary/Predicted URL
                   ];
                   file_put_contents($f_web, json_encode($web_transient));
                   
                   // Trigger Operator Action for Web Content (Default Landing)
                   $web_action_file = $buzon_dir . "accion_update_web_{$oid}.json"; // Using unique action file to avoid race with plan update
                   // Actually operator watches for `accion_update_{$oid}` which we just wrote above. 
                   // BUT, the plan update action only had cpu/ram/db/web flags.
                   // We need to ensure the operator *also* deploys the default index.html if it's new.
                   // Let's rely on the operator being smart enough to see web_enabled=1 and deploy default if missing.
                   // The CRITICAL part for the User Request is the *Visual* part: "web status active".
                   // FORCE WRITE TO DISK NOW
                   $f_web = $buzon_dir . "web_status_{$oid}.json";
                   $web_transient = [
                       'status' => 'active', 
                       'percent' => 100,
                       'msg' => 'Provisioning Web...',
                       // Use localhost port for visual verification
                       'web_url' => "http://localhost:80{$oid}", 
                   ];
                   file_put_contents($f_web, json_encode($web_transient));
               }
           }
        }

        if ($act == 'destroy_k8s') {
            // 1. Mark as terminating in DB (ENUM valid value)
            $stmt = $conn->prepare("UPDATE k8s_deployments SET status='terminating' WHERE id=?");
            $stmt->execute([$oid]);

            // 2. Create Trigger for Operator
            $action_file = __DIR__ . "/../../buzon-pedidos/accion_destroy_{$oid}.json";
            file_put_contents($action_file, json_encode([
                "action" => "DESTROY_K8S",
                "id_cliente" => (int)$oid
            ]));
            
            // 3. Clear status files to allow UI to show 'destroying' immediately
            $buzon_dir = __DIR__ . "/../../buzon-pedidos/";
            @unlink($buzon_dir . "power_status_{$oid}.json");
            @unlink($buzon_dir . "web_status_{$oid}.json");
            @unlink($buzon_dir . "status_{$oid}.json");
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
        
    } catch (Exception $e) {
        error_log("Critical Error in data.php: " . $e->getMessage());
        if(isset($_GET['ajax_action'])) {
            http_response_code(500);
            echo json_encode(['status'=>'error', 'message'=>'Error interno del servidor: ' . $e->getMessage()]);
        } else {
            // Redirect with error param so dashboard can show it (if implemented) or at least not show white screen
            header("Location: ../dashboard.php?id=".(isset($oid)?$oid:'')."&error=internal_error"); 
        }
        exit;
    }
}

// --- AJAX DATA (GET) ---
if (isset($_GET['ajax_data'])) {
    header('Content-Type: application/json');
    $oid = (int)$_GET['id'];
    
    // 🛡️ CRITICAL: Verify Ownership for Read Access
    if (!verifyOwnership($conn, $oid, $user_id)) {
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $ctx = stream_context_create(['http'=> ['timeout' => 3]]);
    
    // Leer Chat si hay respuesta
    $chat_reply = null;
    $res_chat = @file_get_contents(API_URL_BASE . "/chat/leer/$oid", false, $ctx);
    if($res_chat) { $j=json_decode($res_chat,true); if($j && $j['reply']) $chat_reply=$j['reply']; }

    $json = @file_get_contents(API_URL_BASE . "/estado/$oid", false, $ctx);
    $final_data = $json ? json_decode($json, true) : ['error' => 'API Offline'];
    
    // FORCE UPDATE STATUS FROM DB (Source of Truth)
    try {
        $stmt = $conn->prepare("SELECT status FROM k8s_deployments WHERE id = ?");
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
    
    // Priority 0: Plan Updates (Avoids Metrics Race Condition)
    if (file_exists($buzon_dir . "plan_status_{$oid}.json")) {
        $prog_data = json_decode(@file_get_contents($buzon_dir . "plan_status_{$oid}.json"), true);
    }
    // Priority 0.5: Tool Installation (Monitoring)
    else if (file_exists($buzon_dir . "install_status_{$oid}.json")) {
        $prog_data = json_decode(@file_get_contents($buzon_dir . "install_status_{$oid}.json"), true);
        if ($prog_data) $prog_data['action'] = 'install_tool'; // Tag for Frontend
    }
    // Priority 1: Power Ops (Critical)
    else if (file_exists($buzon_dir . "power_status_{$oid}.json")) {
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
    // Map params to new schema
    // Validate Email
    $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
    if (!$email || !checkdnsrr(explode('@', $email)[1], 'MX')) {
        die("Error: Formato de email inválido o dominio inexistente.");
    }
    // Typo Check (Centralized logic)
    $domain = strtolower(explode('@', $email)[1]);
    $typos = ['gmail.co', 'hotmail.co', 'yahoo.co', 'outlook.co', 'gmil.com', 'hotmil.com', 'gm.com'];
    if (in_array($domain, $typos)) die("Error: Dominio de email sospechoso ($domain). Por favor use un proveedor estándar.");

    $sql = "UPDATE users SET full_name=?, email=?, documento_identidad=?, telefono=?, company_name=?, direccion=? WHERE id=?";
    $conn->prepare($sql)->execute([$_POST['full_name'], $email, $_POST['dni'], $_POST['telefono'], $_POST['company_name'], $_POST['calle'], $user_id]);
    header("Location: ../dashboard.php"); exit;
}
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$user_id]); 
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user_info) $user_info = [];

// HELPERS
function calculateWeeklyPrice($r) { 
    $p=match($r['plan_name']??''){'Bronce'=>5,'Plata'=>15,'Oro'=>30,default=>0}; 
    if(($r['plan_name']??'')=='Personalizado'){
        $p=(intval($r['cpu_cores']??0)*5)+(intval($r['ram_gb']??0)*5); // Use correct columns
        if(!empty($r['db_enabled']))$p+=10; if(!empty($r['web_enabled']))$p+=10;
    } 
    return $p/4; 
}
function getBackupLimit($r) { 
    $m=match($r['plan_name']??''){'Bronce'=>5,'Plata'=>15,'Oro'=>30,default=>0}; 
    if(($r['plan_name']??'')=='Personalizado'){
        $m=(intval($r['cpu_cores']??0)*5)+(intval($r['ram_gb']??0)*5); 
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
// Update Query for k8s_deployments
$sql = "SELECT d.*, 
        p.name as plan_name, 
        p.base_cpu as p_cpu, 
        p.base_ram as p_ram, 
        
        -- Tools Aggregation
        (
            SELECT JSON_ARRAYAGG(tool_name) 
            FROM k8s_tools 
            WHERE deployment_id = d.id
        ) as tools_json

        FROM k8s_deployments d 
        JOIN plans p ON d.plan_id=p.id 
        WHERE user_id=? AND status!='cancelled' 
        ORDER BY d.id DESC";

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
    if ($current['plan_name'] == 'Personalizado') $plan_cpus = intval($current['cpu_cores'] ?? 1); else $plan_cpus = intval($current['p_cpu'] ?? 1); 
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
            
            // Fix Tools Loading
            $tools_db = (!empty($current['tools_json'])) ? json_decode($current['tools_json'], true) : [];
            if(!empty($tools_db)) {
                $installed_tools = $tools_db;
            } else {
                $installed_tools = $d['installed_tools'] ?? [];
            }
        }
    }

    // FIX GEMINI: READ FROM DISK (Transient Status) for INIT
    $buzon_dir = __DIR__ . "/../../buzon-pedidos/";
    if ($current) {
        $oid = $current['id'];
        $prog_data = null;
        
        if (file_exists($buzon_dir . "plan_status_{$oid}.json")) {
            $prog_data = json_decode(@file_get_contents($buzon_dir . "plan_status_{$oid}.json"), true);
        }
        else if (file_exists($buzon_dir . "web_status_{$oid}.json")) {
            $prog_data = json_decode(@file_get_contents($buzon_dir . "web_status_{$oid}.json"), true);
        }
        
        // Fallback Logic for Metrics
        if ($prog_data) {
           // Only override web_url if present
           if (isset($prog_data['web_url']) && !empty($prog_data['web_url'])) {
               $web_url = $prog_data['web_url'];
           }
           // IMPORTANT: If transient file has NO metrics (or 0), keep the one from API/DB if available
           // This prevents the "flash of zero" if the operator hasn't updated the file yet but DB has data
           if (empty($prog_data['metrics'])) {
               // DO NOTHING, keep $d['metrics'] from API if exists
           }
        }
    }
}

// ============= MONITORING INSTALLATION =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install_monitoring') {
    header('Content-Type: application/json');
    
    $deployment_id = filter_var($_POST['deployment_id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$deployment_id) {
        echo json_encode(['success' => false, 'error' => 'ID de deployment inválido']);
        exit;
    }
    
    try {
        // 1. Verify deployment exists and belongs to user
        $stmt = $conn->prepare("
            SELECT d.id, d.subdomain, d.status 
            FROM k8s_deployments d 
            WHERE d.id = ? AND d.user_id = ?
        ");
        $stmt->execute([$deployment_id, $_SESSION['user_id']]);
        $deployment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deployment) {
            echo json_encode(['success' => false, 'error' => 'Deployment no encontrado']);
            exit;
        }
        
        if ($deployment['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => 'El deployment debe estar activo']);
            exit;
        }
        
        // 2. Check if monitoring already installed
        $stmt = $conn->prepare("
            SELECT id FROM k8s_tools 
            WHERE deployment_id = ? AND tool_name = 'monitoring'
        ");
        $stmt->execute([$deployment_id]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Monitoring ya está instalado']);
            exit;
        }
        
        // 3. Generate Grafana password (Fixed to 'admin' per user request)
        $grafana_password = 'admin';
        
        // 4. Insert into k8s_tools
        $stmt = $conn->prepare("
            INSERT INTO k8s_tools (deployment_id, tool_name, config_json, installed_at) 
            VALUES (?, 'monitoring', ?, NOW())
        ");
        $config = json_encode([
            'grafana_password' => $grafana_password,
            'grafana_url' => "http://localhost:" . (3000 + intval($deployment_id)),
            'retention_days' => 7
        ]);
        $stmt->execute([$deployment_id, $config]);
        
    // 5. Create buzón action file
        $buzon_path = __DIR__ . '/../../buzon-pedidos';
        if (!is_dir($buzon_path)) {
            mkdir($buzon_path, 0755, true);
        }
        
        $action_file = $buzon_path . "/accion_install_tool_{$deployment_id}.json";
        $action_data = [
            'action' => 'INSTALL_TOOL',
            'deployment_id' => $deployment_id,
            'tool' => 'monitoring',
            'subdomain' => $deployment['subdomain'],
            'grafana_password' => $grafana_password,
            'timestamp' => time()
        ];
        
        // Reset status file
        $status_file = $buzon_path . "/install_status_{$deployment_id}.json";
        if (file_exists($status_file)) @unlink($status_file);
        
        file_put_contents($action_file, json_encode($action_data, JSON_PRETTY_PRINT));
        
        echo json_encode([
            'success' => true,
            'message' => 'Instalación iniciada',
            'grafana_url' => "http://localhost:" . (3000 + intval($deployment_id)),
            'grafana_user' => 'admin',
            'grafana_password' => $grafana_password
        ]);
        
    } catch (Exception $e) {
        error_log("Error installing monitoring: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error del servidor']);
    }
    exit;
}

// ============= MONITORING UNINSTALLATION =============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'uninstall_monitoring') {
    header('Content-Type: application/json');
    
    $deployment_id = filter_var($_POST['deployment_id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$deployment_id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    try {
        // Verify ownership
        $stmt = $conn->prepare("
            SELECT d.id FROM k8s_deployments d 
            WHERE d.id = ? AND d.user_id = ?
        ");
        $stmt->execute([$deployment_id, $_SESSION['user_id']]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            exit;
        }
        
        // Delete from k8s_tools
        $stmt = $conn->prepare("
            DELETE FROM k8s_tools 
            WHERE deployment_id = ? AND tool_name = 'monitoring'
        ");
        $stmt->execute([$deployment_id]);
        
        // Create uninstall action
        $buzon_path = __DIR__ . '/../../buzon-pedidos';
        $action_file = $buzon_path . "/accion_uninstall_tool_{$deployment_id}.json";
        $action_data = [
            'action' => 'UNINSTALL_TOOL',
            'deployment_id' => $deployment_id,
            'tool' => 'monitoring',
            'timestamp' => time()
        ];
        
        file_put_contents($action_file, json_encode($action_data, JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true, 'message' => 'Desinstalación completada']);
        
    } catch (Exception $e) {
        error_log("Error uninstalling monitoring: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error del servidor']);
    }
    exit;
}
?>