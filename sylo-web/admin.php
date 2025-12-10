<?php
session_start();

// --- 1. CONEXIÓN A BASE DE DATOS ---
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

$stmt = $conn->prepare("SELECT role, username FROM users WHERE id = :uid");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] !== 'admin') {
    die("<h1 style='color:red;text-align:center;margin-top:20%'>403 ACCESO DENEGADO<br><small>Este incidente será reportado.</small></h1>");
}

// --- 3. PROCESAR ACCIONES (NUKE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['action']) && $input['action'] === 'nuke_cluster') {
        $orderId = $input['order_id'];
        try {
            $stmt = $conn->prepare("SELECT id FROM orders WHERE id = :id");
            $stmt->execute(['id' => $orderId]);
            
            if ($stmt->fetch()) {
                $kill_data = [
                    "id" => $orderId,
                    "action" => "TERMINATE",
                    "admin" => $_SESSION['username'],
                    "timestamp" => date("c")
                ];
                
                // Forzamos escritura
                $file_path = "/buzon/kill_orden_" . $orderId . ".json";
                file_put_contents($file_path, json_encode($kill_data, JSON_PRETTY_PRINT));
                
                // Actualizamos DB
                $upd = $conn->prepare("UPDATE orders SET status = 'terminating' WHERE id = :id");
                $upd->execute(['id' => $orderId]);
                
                echo json_encode(["status" => "success", "message" => "Orden de eliminación #$orderId enviada."]);
            } else {
                echo json_encode(["status" => "error", "message" => "Orden no encontrada."]);
            }
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
        }
        exit;
    }
}

// --- 4. OBTENER DATOS (FILTRADOS) ---

// KPIs
$total_revenue = $conn->query("SELECT COALESCE(SUM(p.price), 0) FROM orders o JOIN plans p ON o.plan_id = p.id WHERE o.status != 'cancelled'")->fetchColumn();
$active_clusters = $conn->query("SELECT COUNT(*) FROM orders WHERE status = 'active' OR status = 'running'")->fetchColumn();
$total_users = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();

// --- TABLA PRINCIPAL: FILTRO ROBUSTO ---
// Usamos TRIM y LOWER para que no importe si está escrito como "Creating", "creating " o "CREATING"
$sql = "SELECT o.id, u.username, u.company_name, p.name as plan, o.status, o.purchase_date, p.price 
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        JOIN plans p ON o.plan_id = p.id 
        WHERE LOWER(TRIM(o.status)) NOT IN ('creating', 'building', 'provisioning')
        ORDER BY o.id DESC";

$orders = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SYLO | God Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #1a1a2e; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .navbar { background-color: #16213e; border-bottom: 1px solid #0f3460; }
        .navbar-brand { font-weight: 800; color: #e94560 !important; letter-spacing: 2px; }
        
        .kpi-card { background-color: #16213e; border: 1px solid #0f3460; border-radius: 10px; padding: 20px; transition: transform 0.3s; }
        .kpi-card:hover { transform: translateY(-5px); border-color: #e94560; }
        .kpi-value { font-size: 2.5rem; font-weight: bold; color: #fff; }
        .kpi-label { color: #8d99ae; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        
        .table-custom { background-color: #16213e; border-radius: 10px; overflow: hidden; }
        .table-custom th { background-color: #0f3460; color: #fff; border: none; padding: 15px; }
        .table-custom td { border-bottom: 1px solid #0f3460; color: #ccc; padding: 15px; vertical-align: middle; }
        .table-custom tr:hover td { background-color: #1a1a2e; color: #fff; }
        
        .badge-status { padding: 8px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-active, .status-running { background-color: rgba(16, 185, 129, 0.2); color: #10b981; }
        .status-pending { background-color: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .status-cancelled, .status-terminated, .status-error { background-color: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .status-terminating { background-color: rgba(239, 68, 68, 0.1); color: #ef4444; animation: pulse 2s infinite; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        
        .btn-nuke { background-color: transparent; border: 1px solid #ef4444; color: #ef4444; transition: all 0.3s; }
        .btn-nuke:hover { background-color: #ef4444; color: white; box-shadow: 0 0 15px #ef4444; }

        .btn-sync { background-color: transparent; border: 1px solid #0dcaf0; color: #0dcaf0; font-weight: 600; transition: all 0.3s; }
        .btn-sync:hover { background-color: #0dcaf0; color: #000; box-shadow: 0 0 15px rgba(13, 202, 240, 0.5); }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark mb-5">
        <div class="container-fluid px-5">
            <a class="navbar-brand" href="#"><i class="fas fa-biohazard me-2"></i>SYLO // GOD MODE</a>
            <div class="d-flex align-items-center">
                <span class="text-muted me-3">Bienvenido, <strong><?php echo $_SESSION['username']; ?></strong></span>
                <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fas fa-store me-2"></i>Ir a Tienda</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-5">
        
        <div class="row g-4 mb-5">
            <div class="col-md-3"><div class="kpi-card"><div class="d-flex justify-content-between align-items-center"><div><div class="kpi-value"><?php echo number_format($total_revenue, 2); ?>€</div><div class="kpi-label">Ingresos Totales</div></div><i class="fas fa-coins fa-2x text-warning"></i></div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="d-flex justify-content-between align-items-center"><div><div class="kpi-value"><?php echo $active_clusters; ?></div><div class="kpi-label">Clústeres Activos</div></div><i class="fas fa-server fa-2x text-success"></i></div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="d-flex justify-content-between align-items-center"><div><div class="kpi-value"><?php echo $total_users; ?></div><div class="kpi-label">Clientes Registrados</div></div><i class="fas fa-users fa-2x text-info"></i></div></div></div>
            <div class="col-md-3"><div class="kpi-card border-danger"><div class="d-flex justify-content-between align-items-center"><div><div class="kpi-value text-danger">ONLINE</div><div class="kpi-label text-danger">Estado del Sistema</div></div><i class="fas fa-heartbeat fa-2x text-danger"></i></div></div></div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="text-white-50 m-0"><i class="fas fa-list me-2"></i>Gestión de Flota</h4>
            <a href="sync_status.php" class="btn btn-sync btn-sm px-3 py-2">
                <i class="fas fa-sync-alt me-2"></i>Sincronizar / Limpiar Fantasmas
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-custom">
                <thead>
                    <tr>
                        <th>#ID</th>
                        <th>Cliente / Empresa</th>
                        <th>Plan</th>
                        <th>Precio</th>
                        <th>Fecha Alta</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($orders) > 0): ?>
                        <?php foreach($orders as $o): ?>
                        <tr>
                            <td class="fw-bold">#<?php echo $o['id']; ?></td>
                            <td>
                                <div class="fw-bold text-white"><?php echo $o['username']; ?></div>
                                <small class="text-muted"><?php echo $o['company_name'] ?: 'Particular'; ?></small>
                            </td>
                            <td><span class="badge bg-dark border border-secondary"><?php echo strtoupper($o['plan']); ?></span></td>
                            <td><?php echo number_format($o['price'], 2); ?>€</td>
                            <td><?php echo date('d M Y H:i', strtotime($o['purchase_date'])); ?></td>
                            <td>
                                <span class="badge-status status-<?php echo strtolower($o['status']); ?>">
                                    <?php echo $o['status']; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php if(!in_array(strtolower($o['status']), ['cancelled', 'terminated', 'error'])): ?>
                                    <button onclick="confirmNuke(<?php echo $o['id']; ?>, '<?php echo $o['username']; ?>')" class="btn btn-nuke btn-sm">
                                        <i class="fas fa-skull me-2"></i>TERMINAR
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fas fa-ban"></i></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                No hay pedidos (o todos están en proceso de creación).
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="nukeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border border-danger">
                <div class="modal-header border-danger">
                    <h5 class="modal-title text-danger fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>ZONA DE PELIGRO</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="fas fa-radiation fa-4x text-danger mb-3"></i>
                    <h3 class="mb-3">¿Ejecutar Protocolo de Eliminación?</h3>
                    <p class="text-muted">Estás a punto de destruir toda la infraestructura del cliente <strong id="nuke-client" class="text-white"></strong>.</p>
                    <p class="text-danger small fw-bold">ESTA ACCIÓN ES IRREVERSIBLE.</p>
                    <input type="hidden" id="nuke-order-id">
                </div>
                <div class="modal-footer border-0 justify-content-center pb-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger fw-bold px-4" onclick="executeNuke()">
                        <i class="fas fa-bomb me-2"></i>CONFIRMAR DESTRUCCIÓN
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const nukeModal = new bootstrap.Modal(document.getElementById('nukeModal'));

        function confirmNuke(id, client) {
            document.getElementById('nuke-order-id').value = id;
            document.getElementById('nuke-client').innerText = client;
            nukeModal.show();
        }

        async function executeNuke() {
            const id = document.getElementById('nuke-order-id').value;
            try {
                const res = await fetch('admin.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'nuke_cluster', order_id: id })
                });
                const data = await res.json();
                
                if (data.status === 'success') {
                    alert("🚀 " + data.message);
                    location.reload();
                } else {
                    alert("Error: " + data.message);
                }
            } catch(e) {
                alert("Error de comunicación con el servidor.");
            }
            nukeModal.hide();
        }
    </script>
</body>
</html>