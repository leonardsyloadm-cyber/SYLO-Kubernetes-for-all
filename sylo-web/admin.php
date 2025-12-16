<?php
session_start();

// --- 1. CONEXIÓN DB ---
$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("Error DB: " . $e->getMessage()); }

// --- 2. SECURITY CHECK ---
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$stmtSec = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
$stmtSec->execute([$_SESSION['user_id']]);
$currentUser = $stmtSec->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || ($currentUser['username'] !== 'ivan' && $currentUser['role'] !== 'admin')) {
    header("Location: dashboard_cliente.php"); exit;
}

// --- 3. UTILIDADES ESTADO SISTEMA ---
function getContainerStatus($containerName) {
    if($containerName === 'kylo-main-db') { global $conn; return ($conn) ? 'RUNNING' : 'STOPPED'; }
    return 'RUNNING'; 
}
$dbStatus = getContainerStatus('kylo-main-db');
$webStatus = getContainerStatus('sylo-web');
$systemOverall = ($dbStatus == 'RUNNING' && $webStatus == 'RUNNING') ? 'ONLINE' : 'ISSUES';

// --- 4. API INTERNAL (AJAX HANDLERS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // BOMBA TÁCTICA
    if (isset($input['action']) && $input['action'] === 'tactical_nuke') {
        $targets = $input['targets'] ?? [];
        if(empty($targets)) { echo json_encode(["status"=>"error", "message"=>"No targets."]); exit; }
        foreach ($targets as $oid) {
            $kill = ["id"=>$oid, "action"=>"TERMINATE", "admin"=>"GOD_MODE_TACTICAL", "timestamp"=>date("c")];
            file_put_contents("/buzon/accion_{$oid}_terminate.json", json_encode($kill));
            $conn->prepare("UPDATE orders SET status='terminating' WHERE id=?")->execute([$oid]);
        }
        echo json_encode(["status"=>"success", "message"=>"🚀 Ataque lanzado contra " . count($targets) . " objetivos."]); exit;
    }

    // PURGAR DB
    if (isset($input['action']) && $input['action'] === 'purge_db') {
        $oid = $input['order_id'];
        $conn->prepare("DELETE FROM order_specs WHERE order_id=?")->execute([$oid]);
        $conn->prepare("DELETE FROM orders WHERE id=?")->execute([$oid]);
        @unlink("/buzon/status_{$oid}.json");
        echo json_encode(["status"=>"success"]); exit;
    }

    // ELIMINAR USUARIO
    if (isset($input['action']) && $input['action'] === 'delete_user') {
        $uid = $input['user_id'];
        if ($uid == $_SESSION['user_id']) return;
        $conn->prepare("DELETE FROM orders WHERE user_id=?")->execute([$uid]);
        $conn->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        echo json_encode(["status"=>"success", "message"=>"Usuario eliminado."]); exit;
    }
}

// API GET
if (isset($_GET['list_users'])) {
    $users = $conn->query("SELECT * FROM users WHERE role!='admin' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users); exit;
}
if (isset($_GET['get_user_details'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['get_user_details']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit;
}

// --- 5. RENDERIZADO DE DATOS ---
$view = isset($_GET['view']) && $_GET['view'] === 'trash' ? 'trash' : 'active';

// KPIs
$revenue = $conn->query("SELECT COALESCE(SUM(p.price), 0) FROM orders o JOIN plans p ON o.plan_id = p.id WHERE o.status IN ('active', 'suspended')")->fetchColumn();
$active_count = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('active', 'suspended')")->fetchColumn();
$users_count = $conn->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
$trash_count = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn();

// Consulta Principal
$sqlBase = "SELECT o.id, o.status, p.name as plan, p.price,
                   u.username, u.email, u.company_name, u.full_name, 
                   u.tipo_usuario, u.dni, u.telefono, u.calle, u.tipo_empresa
            FROM orders o 
            JOIN users u ON o.user_id = u.id 
            JOIN plans p ON o.plan_id = p.id";

$sql = ($view === 'trash') ? "$sqlBase WHERE o.status = 'cancelled' ORDER BY o.id DESC" : "$sqlBase WHERE o.status != 'cancelled' ORDER BY o.id DESC";
$orders = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function getJSONData($oid) {
    $file = "/buzon/status_{$oid}.json";
    $data = ['ip' => '...', 'ssh' => 'N/A', 'pass' => '...', 'web_port' => '80'];
    if (file_exists($file)) {
        $json = json_decode(file_get_contents($file), true);
        if (isset($json['ssh_cmd'])) {
            preg_match('/@([\d\.]+)/', $json['ssh_cmd'], $matches);
            $data['ip'] = $matches[1] ?? '...';
            $data['ssh'] = $json['ssh_cmd'];
        }
        $data['pass'] = $json['ssh_pass'] ?? '...';
        $data['web_port'] = 80; 
    }
    return $data;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SYLO | Ultimate God Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0f172a; color: #e2e8f0; font-family: 'Segoe UI', system-ui, sans-serif; }
        .navbar { background: #1e293b; border-bottom: 1px solid #334155; }
        .navbar-brand { font-weight: 900; color: #f43f5e !important; letter-spacing: 1px; }

        /* KPI Cards & Golden Shine */
        .kpi-card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 25px; transition: all 0.3s ease; position: relative; overflow: hidden; }
        .kpi-card:hover { transform: translateY(-5px); box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.3); border-color: #64748b; cursor: pointer; }
        .kpi-value { font-size: 2.2rem; font-weight: 800; color: white; }
        .kpi-label { color: #94a3b8; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        
        /* ORO PURO */
        .gold-shine { color: #ffd700; text-shadow: 0 0 10px #c6a300, 0 0 20px #c6a300; animation: goldenGlow 2s ease-in-out infinite alternate; }
        .gold-img-shine { filter: drop-shadow(0 0 10px #ffd700) drop-shadow(0 0 20px #c6a300); animation: goldenImgGlow 2s ease-in-out infinite alternate; }
        @keyframes goldenGlow { from { text-shadow: 0 0 10px #c6a300; opacity: 0.9; } to { text-shadow: 0 0 25px #ffd700; opacity: 1; }}
        @keyframes goldenImgGlow { from { filter: drop-shadow(0 0 5px #c6a300); transform: scale(1); } to { filter: drop-shadow(0 0 20px #ffd700); transform: scale(1.05); }}

        /* Tabs */
        .nav-tabs .nav-link { color: #94a3b8; border: none; font-weight: 700; padding: 15px 25px; transition: 0.3s; }
        .nav-tabs .nav-link:hover { color: #e2e8f0; background: rgba(255,255,255,0.05); }
        .nav-tabs .nav-link.active { color: #3b82f6; background: transparent; border-bottom: 3px solid #3b82f6; }

        /* Tabla Estilo */
        .table-custom { background: #1e293b; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .table-custom th { background: #16213e; color: #64748b; font-weight: 700; text-transform: uppercase; border: none; padding: 20px; }
        .table-custom td { border-bottom: 1px solid #334155; padding: 20px; vertical-align: middle; color: #cbd5e1; }
        
        /* HOVER ANIMATION LOGIC */
        .cluster-row { cursor: pointer; transition: background 0.2s; border-left: 3px solid transparent; z-index: 2; position: relative; }
        .cluster-row:hover { background: #24304a; border-left-color: #3b82f6; }
        
        /* Contenedor oculto de detalles */
        .expanded-details-row td { padding: 0 !important; border: none; background: #111a2e; }
        .detail-wrapper {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, opacity 0.3s ease, padding 0.3s ease;
        }
        
        /* Trigger de la animación */
        .cluster-row:hover + .expanded-details-row .detail-wrapper,
        .expanded-details-row:hover .detail-wrapper {
            max-height: 500px; 
            opacity: 1;
            padding: 20px;
        }

        /* DISEÑO DE CAJAS DE DETALLE - CORREGIDO: HEIGHT AUTO */
        .detail-box { 
            background: #1e293b; 
            border-radius: 8px; 
            padding: 20px; 
            height: auto; /* IMPORTANTE: Altura automática para ajustarse al contenido */
            border: 1px solid #334155; 
        }
        .detail-title { text-transform: uppercase; font-weight: 800; font-size: 0.8rem; margin-bottom: 15px; letter-spacing: 1px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 4px; }
        .info-label { color: #94a3b8; font-weight: 600; }
        .info-val { color: #e2e8f0; text-align: right; }

        /* PLAN BADGES */
        .plan-badge { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; padding: 6px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .badge-bronce { background: linear-gradient(135deg, #a67c52, #8a5a3a); color: #ffebd6; border-color: #a67c52; }
        .badge-plata  { background: linear-gradient(135deg, #e2e8f0, #94a3b8); color: #0f172a; border-color: #cbd5e1; }
        .badge-oro    { background: linear-gradient(135deg, #ffd700, #eab308); color: #422006; border-color: #facc15; animation: goldenGlow 3s infinite; }
        .badge-custom { background: #3b82f6; color: white; }

        /* Estados */
        .st-badge { padding: 6px 12px; border-radius: 6px; font-weight: 800; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .st-active { color: #10b981; background: rgba(16, 185, 129, 0.15); box-shadow: 0 0 10px rgba(16, 185, 129, 0.2); }
        .st-suspended { color: #f59e0b; background: rgba(245, 158, 11, 0.15); }
        .st-terminating { color: #ef4444; background: rgba(239, 68, 68, 0.15); animation: pulseRed 1s infinite; }
        .st-cancelled { color: #64748b; text-decoration: line-through; background: rgba(100, 116, 139, 0.1); }
        @keyframes pulseRed { 0% { opacity: 0.7; } 50% { opacity: 1; box-shadow: 0 0 15px rgba(239, 68, 68, 0.4); } 100% { opacity: 0.7; } }

        /* Botones y Dropdown */
        .btn-hiroshima { background: linear-gradient(45deg, #991b1b, #dc2626); color: white; font-weight: 900; letter-spacing: 1px; border: none; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.4); transition: 0.3s; }
        .btn-hiroshima:hover { transform: scale(1.05) translateY(-2px); box-shadow: 0 6px 20px rgba(220, 38, 38, 0.6); color: white; }
        
        .dropdown-menu-dark { background-color: #1e293b; border-color: #334155; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .dropdown-item:hover { background-color: #24304a; color: white; }
        .service-status-indicator { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 10px; }
        .status-running { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .status-stopped { background: #ef4444; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark mb-5 p-3">
    <div class="container-fluid">
        <a class="navbar-brand" href="#"><i class="fas fa-biohazard me-3"></i>GOD MODE_</a>
        <div class="d-flex gap-3 align-items-center">
            <button data-bs-toggle="modal" data-bs-target="#hiroshimaModal" class="btn btn-hiroshima px-4 py-2 rounded-pill">
                <i class="fas fa-radiation-alt me-2"></i>HIROSHIMA
            </button>
            <div class="vr bg-secondary mx-3" style="opacity: 0.3;"></div>
            <div class="dropdown">
                <button class="btn btn-outline-<?php echo ($systemOverall==='ONLINE')?'success':'warning';?> fw-bold dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-network-wired me-2"></i>SISTEMA <?php echo $systemOverall; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end p-2">
                    <li><h6 class="dropdown-header text-uppercase text-muted small">Estado de Servicios</h6></li>
                    <li><div class="dropdown-item d-flex align-items-center"><span class="service-status-indicator status-<?php echo strtolower($dbStatus);?>"></span><div><strong>Base de Datos</strong><br><small class="text-muted"><?php echo $dbStatus; ?></small></div></div></li>
                    <li><div class="dropdown-item d-flex align-items-center"><span class="service-status-indicator status-<?php echo strtolower($webStatus);?>"></span><div><strong>Servidor Web</strong><br><small class="text-muted"><?php echo $webStatus; ?></small></div></div></li>
                </ul>
            </div>
            <a href="index.php" class="btn btn-dark border-secondary"><i class="fas fa-store me-2"></i>Ir a Tienda</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-5">
    
    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="kpi-card" style="background: linear-gradient(145deg, #1e293b, #172033);">
                <div class="kpi-value d-flex align-items-center gold-shine">
                    <?php echo number_format($revenue, 2); ?>€
                    <img src="graciosa.png" alt="$$$" class="gold-img-shine" style="height: 65px; margin-left: 20px;">
                </div>
                <div class="kpi-label gold-shine" style="opacity: 0.8;">Ingresos Mensuales</div>
            </div>
        </div>
        <div class="col-md-3" onclick="filtrarActivos()" id="kpiVivos">
            <div class="kpi-card border-success">
                <div class="kpi-value text-success"><?php echo $active_count; ?></div>
                <div class="kpi-label text-success"><i class="fas fa-heartbeat me-2"></i>Servicios Vivos (Filtrar)</div>
            </div>
        </div>
        <div class="col-md-3" onclick="abrirGestionUsuarios()">
            <div class="kpi-card border-info">
                <div class="kpi-value text-info"><?php echo $users_count; ?></div>
                <div class="kpi-label text-info"><i class="fas fa-users me-2"></i>Gestionar Clientes</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card border-warning">
                <div class="kpi-value text-warning"><?php echo $trash_count; ?></div>
                <div class="kpi-label text-warning"><i class="fas fa-trash-alt me-2"></i>Papelera</div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4 border-bottom-0 ps-3">
        <li class="nav-item"><a class="nav-link <?php echo ($view === 'active') ? 'active' : ''; ?>" href="admin.php?view=active"><i class="fas fa-cubes me-2"></i>Flota Activa</a></li>
        <li class="nav-item"><a class="nav-link <?php echo ($view === 'trash') ? 'active' : ''; ?>" href="admin.php?view=trash"><i class="fas fa-history me-2"></i>Historial de Bajas</a></li>
    </ul>

    <div class="table-custom mb-5">
        <table class="table table-borderless mb-0">
            <thead>
                <tr>
                    <th class="ps-5">ID CLÚSTER</th>
                    <th>CLIENTE / EMPRESA</th>
                    <th>PLAN CONTRATADO</th>
                    <th>PRECIO</th>
                    <th>IP ACCESO</th>
                    <th>ESTADO ACTUAL</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($orders)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted display-6"><i class="fas fa-wind me-3"></i>Nada por aquí...</td></tr>
                <?php else: ?>
                    <?php foreach($orders as $o): 
                        $jsonData = getJSONData($o['id']);
                        $statusLower = strtolower($o['status']);
                        
                        // LÓGICA DE ICONOS
                        $isCompany = ($o['tipo_usuario'] === 'empresa');
                        $iconClass = $isCompany ? 'fa-building' : 'fa-user-circle';
                        $iconColor = $isCompany ? 'text-info' : 'text-light';

                        // LÓGICA DE ESTILOS DE PLAN
                        $planClean = strtolower($o['plan']);
                        $badgeClass = 'badge-custom';
                        if(strpos($planClean, 'bronce') !== false) $badgeClass = 'badge-bronce';
                        if(strpos($planClean, 'plata') !== false) $badgeClass = 'badge-plata';
                        if(strpos($planClean, 'oro') !== false) $badgeClass = 'badge-oro';
                    ?>
                        <tr class="cluster-row <?php echo ($statusLower=='active')?'row-active':''; ?>">
                            <td class="ps-5 fw-bold text-danger font-monospace">#<?php echo str_pad($o['id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fas <?php echo $iconClass; ?> fa-2x <?php echo $iconColor; ?> opacity-75"></i>
                                    <div>
                                        <div class="fw-bold text-white"><?php echo $isCompany ? $o['company_name'] : $o['full_name']; ?></div>
                                        <small class="text-secondary"><?php echo $o['username']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="plan-badge <?php echo $badgeClass; ?>"><?php echo $o['plan']; ?></span></td>
                            <td class="fw-bold text-success font-monospace"><?php echo $o['price']; ?>€/mes</td>
                            <td class="font-monospace text-warning"><?php echo ($view === 'active') ? $jsonData['ip'] : '---'; ?></td>
                            <td><span class="st-badge st-<?php echo $statusLower; ?>"><?php echo strtoupper($o['status']); ?></span></td>
                        </tr>
                        
                        <tr class="expanded-details-row">
                            <td colspan="6">
                                <div class="detail-wrapper">
                                    <div class="row g-4 align-items-start">
                                        <div class="col-md-4">
                                            <div class="detail-box border-info">
                                                <div>
                                                    <div class="detail-title text-info"><i class="fas fa-address-card me-2"></i>Información Cliente</div>
                                                    <div class="info-row"><span class="info-label">Nombre:</span><span class="info-val"><?php echo $o['full_name']; ?></span></div>
                                                    <div class="info-row"><span class="info-label">Email:</span><span class="info-val"><?php echo $o['email']; ?></span></div>
                                                    <div class="info-row"><span class="info-label">Teléfono:</span><span class="info-val"><?php echo $o['telefono']; ?></span></div>
                                                    <?php if($isCompany): ?>
                                                        <div class="info-row"><span class="info-label">Razón Social:</span><span class="info-val"><?php echo $o['company_name']; ?></span></div>
                                                        <div class="info-row"><span class="info-label">Dirección:</span><span class="info-val small"><?php echo $o['calle']; ?></span></div>
                                                    <?php else: ?>
                                                        <div class="info-row"><span class="info-label">DNI:</span><span class="info-val"><?php echo $o['dni']; ?></span></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="detail-box border-secondary" style="background: #151b29;">
                                                <div>
                                                    <div class="detail-title text-light"><i class="fas fa-code me-2"></i>Acceso Técnico (<?php echo $o['plan']; ?>)</div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="small text-muted text-uppercase mb-1">Acceso SSH</label>
                                                        <input type="text" class="form-control form-control-sm bg-black text-success font-monospace border-secondary" value="<?php echo $jsonData['ssh']; ?>" readonly>
                                                        <div class="small text-secondary mt-1">Pass: <span class="text-white font-monospace"><?php echo $jsonData['pass']; ?></span></div>
                                                    </div>

                                                    <?php if(strpos($planClean, 'plata') !== false || strpos($planClean, 'oro') !== false): ?>
                                                    <div class="mb-3 border-top border-secondary pt-2">
                                                        <label class="small text-muted text-uppercase mb-1">Base de Datos</label>
                                                        <div class="small text-info font-monospace">
                                                            Host: <?php echo $jsonData['ip']; ?>:3306<br>
                                                            User: root / Pass: <?php echo $jsonData['pass']; ?>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>

                                                    <?php if(strpos($planClean, 'oro') !== false): ?>
                                                    <div class="mb-2 border-top border-secondary pt-2">
                                                        <label class="small text-muted text-uppercase mb-1">Acceso Web</label>
                                                        <a href="http://<?php echo $jsonData['ip']; ?>" target="_blank" class="btn btn-sm btn-outline-warning w-100"><i class="fas fa-external-link-alt me-2"></i>ABRIR WEB</a>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="detail-box border-danger bg-danger bg-opacity-10">
                                                <div>
                                                    <div class="detail-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Zona de Peligro</div>
                                                    <p class="small text-muted mb-3">Acciones destructivas para este clúster.</p>
                                                </div>
                                                
                                                <div>
                                                    <?php if($view === 'trash'): ?>
                                                        <button onclick="purgeDB(<?php echo $o['id']; ?>)" class="btn btn-outline-danger btn-sm w-100 fw-bold py-2"><i class="fas fa-fire me-2"></i>PURGAR DEFINITIVAMENTE</button>
                                                    <?php elseif($statusLower !== 'terminating'): ?>
                                                         <button onclick="nukeCluster(<?php echo $o['id']; ?>)" class="btn btn-danger btn-sm w-100 fw-bold py-2"><i class="fas fa-trash me-2"></i>ELIMINAR CLÚSTER</button>
                                                    <?php else: ?>
                                                        <button class="btn btn-dark btn-sm w-100 text-muted py-2" disabled>Eliminación en curso...</button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="hiroshimaModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark border-danger">
            <div class="modal-header border-secondary bg-danger bg-opacity-25">
                <h5 class="modal-title text-danger fw-bold"><i class="fas fa-crosshairs me-2"></i>SELECCIÓN DE OBJETIVOS TÁCTICOS</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-black">
                <p class="text-muted mb-4">Selecciona los clústeres que deseas eliminar.</p>
                <form id="tacticalNukeForm">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead><tr><th class="text-center"><i class="fas fa-check-double"></i></th><th>ID</th><th>Cliente</th><th>Plan</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php
                            $targets = $conn->query("SELECT o.id, u.username, p.name, o.status FROM orders o JOIN users u ON o.user_id = u.id JOIN plans p ON o.plan_id = p.id WHERE o.status NOT IN ('cancelled', 'terminating') ORDER BY o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
                            if(empty($targets)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No hay objetivos válidos.</td></tr>
                            <?php else: foreach($targets as $t): ?>
                                <tr>
                                    <td class="text-center"><input type="checkbox" class="form-check-input" name="targets[]" value="<?php echo $t['id']; ?>" style="transform: scale(1.3); cursor: pointer;"></td>
                                    <td class="text-danger fw-bold font-monospace">#<?php echo $t['id']; ?></td>
                                    <td class="fw-bold"><?php echo $t['username']; ?></td>
                                    <td><span class="badge bg-secondary text-info"><?php echo $t['name']; ?></span></td>
                                    <td><span class="badge bg-<?php echo ($t['status']=='active')?'success':'warning';?>"><?php echo strtoupper($t['status']); ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-secondary bg-black">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-hiroshima fw-bold px-4" onclick="lanzarAtaqueTactico()"><i class="fas fa-rocket me-2"></i>LANZAR BOMBA</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="usersModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content p-4 bg-dark border-secondary">
            <div class="d-flex justify-content-between mb-4">
                <h4 class="text-info m-0"><i class="fas fa-users-cog me-2"></i>Clientes Registrados</h4>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr class="text-secondary text-uppercase small">
                            <th>ID</th>
                            <th>Usuario / Nombre</th>
                            <th>Tipo</th>
                            <th>Contacto</th>
                            <th>DNI / CIF</th>
                            <th class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="users-table-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="userDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content p-3 bg-dark border-info">
            <h5 class="text-info mb-3">Perfil de Cliente</h5>
            <div id="user-detail-content"></div>
            <div class="mt-4 pt-3 border-top border-secondary d-flex justify-content-between">
                <button id="btn-delete-user" class="btn btn-danger btn-sm">ELIMINAR USUARIO</button>
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const usersModal = new bootstrap.Modal('#usersModal');
    const userDetailModal = new bootstrap.Modal('#userDetailModal');

    // --- FILTRAR ACTIVOS ---
    let filtroActivos = false;
    function filtrarActivos() {
        filtroActivos = !filtroActivos;
        const rows = document.querySelectorAll('.cluster-row');
        const kpi = document.getElementById('kpiVivos');
        
        if(filtroActivos) {
            kpi.classList.add('bg-success', 'bg-opacity-10', 'border-2');
            rows.forEach(row => { if(!row.classList.contains('row-active')) row.style.display = 'none'; });
        } else {
            kpi.classList.remove('bg-success', 'bg-opacity-10', 'border-2');
            rows.forEach(row => row.style.display = '');
        }
    }

    // --- HIROSHIMA TÁCTICO ---
    async function lanzarAtaqueTactico() {
        const form = document.getElementById('tacticalNukeForm');
        const formData = new FormData(form);
        const targets = formData.getAll('targets[]');
        if(targets.length === 0) { alert("⚠️ Selecciona al menos un objetivo."); return; }
        if(!confirm(`🔴 ¿LANZAR BOMBA?\n\nSe eliminarán ${targets.length} clústeres.`)) return;

        try {
            await fetch('admin.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action: 'tactical_nuke', targets: targets}) });
            location.reload();
        } catch(e) { alert("Error: " + e); }
    }

    // --- ACCIONES INDIVIDUALES ---
    async function nukeCluster(id) {
        if(!confirm("⚠️ ¿ELIMINAR CLÚSTER?")) return;
        await fetch('admin.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action: 'tactical_nuke', targets: [id]}) });
        location.reload();
    }
    async function purgeDB(id) {
        if(!confirm("⚠️ ¿PURGAR DE BD?")) return;
        await fetch('admin.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action: 'purge_db', order_id: id}) });
        location.reload();
    }

    // --- GESTIÓN USUARIOS (TABLA COMPLETA) ---
    async function abrirGestionUsuarios() {
        usersModal.show();
        const res = await fetch('admin.php?list_users=true');
        const users = await res.json();
        const tbody = document.getElementById('users-table-body');
        tbody.innerHTML = '';
        users.forEach(u => {
            let tipoLabel = u.tipo_usuario === 'empresa' ? 
                '<span class="badge bg-info text-dark"><i class="fas fa-building me-1"></i>Empresa</span>' : 
                '<span class="badge bg-warning text-dark"><i class="fas fa-user me-1"></i>Autónomo</span>';
            
            let dniShow = u.dni || 'N/A';
            let telShow = u.telefono || 'N/A';
            
            tbody.innerHTML += `
                <tr>
                    <td class="font-monospace text-muted">#${u.id}</td>
                    <td>
                        <div class="fw-bold text-white">${u.username}</div>
                        <small class="text-secondary">${u.full_name}</small>
                    </td>
                    <td>${tipoLabel}</td>
                    <td>
                        <div class="text-info small">${u.email}</div>
                        <div class="text-muted small"><i class="fas fa-phone me-1"></i>${telShow}</div>
                    </td>
                    <td class="font-monospace small text-white">${dniShow}</td>
                    <td class="text-end">
                        <button onclick="verUsuario(${u.id})" class="btn btn-sm btn-outline-light">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>`;
        });
    }

    async function verUsuario(uid) {
        usersModal.hide();
        userDetailModal.show();
        const res = await fetch(`admin.php?get_user_details=${uid}`);
        const u = await res.json();
        let html = `<div class="d-flex justify-content-between mb-2"><span>ID:</span><span>#${u.id}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span>User:</span><strong class="text-warning">${u.username}</strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>Nombre:</span><span>${u.full_name}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span>Email:</span><span>${u.email}</span></div>`;
        document.getElementById('user-detail-content').innerHTML = html;
        document.getElementById('btn-delete-user').onclick = () => borrarUsuario(u.id);
    }

    async function borrarUsuario(uid) {
        if(!confirm("☢️ ¿BORRAR USUARIO Y DATOS?")) return;
        await fetch('admin.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action: 'delete_user', user_id: uid}) });
        location.reload();
    }
</script>
</body>
</html>