<?php
session_start();

// --- 1. SEGURIDAD Y CONEXIÓN ---
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("Error DB"); }

// --- 2. PROCESAR ACCIONES DE CONTROL ---
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $oid = $_POST['order_id'];
    $act = $_POST['action']; 
    
    // Verificamos que la orden pertenezca al usuario
    $stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$oid, $_SESSION['user_id']]);
    
    if ($stmt->fetch()) {
        // Generamos el JSON para el Worker (start, stop, restart, terminate)
        $data = ["id" => $oid, "action" => strtoupper($act), "user" => $_SESSION['username'], "time" => date("c")];
        
        // Si la acción es TERMINATE (Eliminar), usamos un nombre específico o el genérico
        $file = "/buzon/accion_{$oid}_{$act}.json";
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        chmod($file, 0777); 
        
        // Si es eliminar, cambiamos estado a 'terminating' visualmente en DB para que desaparezca de la lista o salga gris
        // (Opcional: depende de cómo tu worker maneje el DB, aquí solo mandamos la orden)
        if ($act === 'terminate') {
             $stmtUp = $conn->prepare("UPDATE orders SET status = 'terminating' WHERE id = ?");
             $stmtUp->execute([$oid]);
             header("Location: dashboard_cliente.php?msg=terminating");
             exit;
        }

        // Refresco limpio para otras acciones
        header("Location: dashboard_cliente.php?id=$oid&msg=sent");
        exit;
    }
}

// Mensajes de feedback
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'sent') {
        $msg = "<div class='alert alert-info alert-dismissible fade show'>
                <i class='fas fa-robot me-2'></i>Orden enviada al sistema. 
                <small>Actualiza en unos segundos.</small>
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
    } elseif ($_GET['msg'] == 'terminating') {
        $msg = "<div class='alert alert-warning alert-dismissible fade show'>
                <i class='fas fa-trash-alt me-2'></i>Solicitud de eliminación recibida. 
                <small>El clúster desaparecerá en breve.</small>
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
    }
}

// --- 3. OBTENER CLÚSTERES ---
// Filtramos para no mostrar los que ya se están borrando (terminating)
$stmt = $conn->prepare("
    SELECT o.*, 
           p.name as plan_name, 
           p.cpu_cores as plan_cpu, 
           p.ram_gb as plan_ram,
           s.cpu_cores as custom_cpu,
           s.ram_gb as custom_ram
    FROM orders o 
    JOIN plans p ON o.plan_id = p.id 
    LEFT JOIN order_specs s ON o.id = s.order_id
    WHERE o.user_id = ? AND o.status IN ('active', 'suspended', 'creating') 
    ORDER BY o.id DESC
");
$stmt->execute([$_SESSION['user_id']]);
$all_clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. LÓGICA DE RECURSOS ---
function getResources($c) {
    $cpu = ($c['plan_cpu'] > 0) ? $c['plan_cpu'] : $c['custom_cpu'];
    $ram = ($c['plan_ram'] > 0) ? $c['plan_ram'] : $c['custom_ram'];
    return ['cpu' => $cpu ?: '?', 'ram' => $ram ?: '?'];
}

// --- 5. SELECCIONAR EL ACTUAL ---
$current_cluster = null;
$creds = [];

if (count($all_clusters) > 0) {
    if (isset($_GET['id'])) {
        foreach ($all_clusters as $c) {
            if ($c['id'] == $_GET['id']) {
                $current_cluster = $c;
                break;
            }
        }
    }
    if (!$current_cluster) $current_cluster = $all_clusters[0];

    // Cargar credenciales
    $jsonFile = "/buzon/status_" . $current_cluster['id'] . ".json";
    if (file_exists($jsonFile)) {
        $creds = json_decode(file_get_contents($jsonFile), true);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi Consola | SYLO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0f172a; color: #e2e8f0; font-family: 'Segoe UI', sans-serif; }
        .navbar { background: rgba(30, 41, 59, 0.95); border-bottom: 1px solid #334155; }
        .brand { font-weight: 800; color: #3b82f6; letter-spacing: 1px; }
        
        .card-dash { background: #1e293b; border: 1px solid #334155; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .card-header-dash { border-bottom: 1px solid #334155; padding: 15px 20px; font-weight: 700; color: #fff; background: rgba(255,255,255,0.02); }
        .card-body-dash { padding: 20px; }

        .cluster-list { list-style: none; padding: 0; margin: 0; }
        .cluster-item { 
            display: block; padding: 15px; border-bottom: 1px solid #334155; 
            color: #94a3b8; text-decoration: none; transition: all 0.2s; border-left: 3px solid transparent;
        }
        .cluster-item:hover { background: rgba(255,255,255,0.03); color: #fff; }
        .cluster-item.active { background: rgba(59, 130, 246, 0.1); color: #fff; border-left-color: #3b82f6; }
        
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 8px; }
        .dot-active { background-color: #10b981; box-shadow: 0 0 5px #10b981; }
        .dot-suspended { background-color: #f59e0b; }
        .dot-creating { background-color: #3b82f6; animation: pulse 1.5s infinite; }

        .badge-status { padding: 6px 15px; border-radius: 20px; font-size: 0.85rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }
        .st-active { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; }
        .st-suspended { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444; }
        .st-creating { background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 1px solid #3b82f6; }

        .btn-control { width: 100%; margin-bottom: 10px; font-weight: 600; border: none; padding: 12px; transition: 0.2s; }
        .btn-start { background: #064e3b; color: #34d399; } .btn-start:hover { background: #065f46; color: white; }
        .btn-stop { background: #7f1d1d; color: #fca5a5; } .btn-stop:hover { background: #991b1b; color: white; }
        .btn-restart { background: #1e3a8a; color: #93c5fd; } .btn-restart:hover { background: #1e40af; color: white; }
        
        /* ESTILO PARA EL BOTÓN DE ELIMINAR */
        .btn-terminate { background: transparent; border: 1px solid #ef4444; color: #ef4444; margin-top: 15px; } 
        .btn-terminate:hover { background: #ef4444; color: white; }

        .console-box { background: #000; color: #4ade80; padding: 15px; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 0.9rem; white-space: pre-wrap; border: 1px solid #333; }
        .info-label { color: #94a3b8; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; display: block; }

        @keyframes pulse { 0% { opacity: 0.5; } 50% { opacity: 1; } 100% { opacity: 0.5; } }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid px-4">
            <a class="navbar-brand brand" href="index.php"><i class="fas fa-layer-group me-2"></i>SYLO CONSOLE</a>
            <div class="ms-auto">
                <span class="text-muted me-3">Usuario: <strong class="text-white"><?php echo $_SESSION['username']; ?></strong></span>
                <a href="index.php" class="btn btn-outline-light btn-sm">Volver a Tienda</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4 px-4">
        <?php echo $msg; ?>

        <?php if (empty($all_clusters)): ?>
            <div class="text-center py-5">
                <i class="fas fa-server fa-4x text-muted mb-4"></i>
                <h3 class="text-white">No tienes clústeres activos</h3>
                <p class="text-secondary">Contrata un plan en la tienda para empezar.</p>
                <a href="index.php#pricing" class="btn btn-primary rounded-pill px-4 mt-3">Ver Planes</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                
                <div class="col-lg-3">
                    <div class="card card-dash h-100">
                        <div class="card-header-dash text-uppercase text-muted small">Mis Servicios</div>
                        <div class="p-0">
                            <ul class="cluster-list">
                                <?php foreach ($all_clusters as $c): ?>
                                    <?php 
                                        $isActive = ($current_cluster['id'] == $c['id']) ? 'active' : ''; 
                                        $dotClass = ($c['status'] == 'active') ? 'dot-active' : (($c['status'] == 'creating') ? 'dot-creating' : 'dot-suspended');
                                        $res = getResources($c); 
                                    ?>
                                    <li>
                                        <a href="dashboard_cliente.php?id=<?php echo $c['id']; ?>" class="cluster-item <?php echo $isActive; ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="status-dot <?php echo $dotClass; ?>"></span>
                                                    <strong><?php echo $c['plan_name']; ?></strong>
                                                </div>
                                                <small class="text-muted">#<?php echo $c['id']; ?></small>
                                            </div>
                                            <small class="d-block mt-1 text-secondary" style="font-size: 0.75rem;">
                                                <?php echo $res['cpu']; ?> vCPU / <?php echo $res['ram']; ?> GB
                                            </small>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3">
                    <div class="card card-dash">
                        <div class="card-header-dash d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-microchip me-2"></i>Estado Actual</span>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-status st-<?php echo $current_cluster['status']; ?>">
                                    <?php echo strtoupper($current_cluster['status']); ?>
                                </span>
                                <button onclick="window.location.href=window.location.href" class="btn btn-sm btn-outline-light border-0" title="Actualizar">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body-dash text-center">
                            <h2 class="text-white mb-0"><?php echo $current_cluster['plan_name']; ?></h2>
                            <small class="text-info font-monospace">ID: <?php echo $current_cluster['id']; ?></small>
                            <hr class="border-secondary my-4">
                            
                            <?php $resCurrent = getResources($current_cluster); ?>
                            <div class="row">
                                <div class="col-6 border-end border-secondary">
                                    <h4 class="text-light"><?php echo $resCurrent['cpu']; ?></h4>
                                    <small class="text-muted text-uppercase">vCPU</small>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-light"><?php echo $resCurrent['ram']; ?></h4>
                                    <small class="text-muted text-uppercase">GB RAM</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card-dash">
                        <div class="card-header-dash"><i class="fas fa-power-off me-2"></i>Operaciones</div>
                        <div class="card-body-dash">
                            <form method="POST">
                                <input type="hidden" name="order_id" value="<?php echo $current_cluster['id']; ?>">
                                <?php if($current_cluster['status'] === 'suspended'): ?>
                                    <button type="submit" name="action" value="start" class="btn btn-control btn-start"><i class="fas fa-play me-2"></i>ENCENDER</button>
                                <?php elseif($current_cluster['status'] === 'active'): ?>
                                    <button type="submit" name="action" value="stop" class="btn btn-control btn-stop"><i class="fas fa-stop me-2"></i>APAGAR</button>
                                <?php endif; ?>
                                <button type="submit" name="action" value="restart" class="btn btn-control btn-restart"><i class="fas fa-sync me-2"></i>REINICIAR</button>
                            </form>

                            <button type="button" class="btn btn-control btn-terminate" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                <i class="fas fa-trash-alt me-2"></i>ELIMINAR SERVICIO
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card card-dash h-100">
                        <div class="card-header-dash d-flex justify-content-between">
                            <span><i class="fas fa-terminal me-2"></i>Acceso Remoto</span>
                            <button class="btn btn-sm btn-outline-light" onclick="copiarCreds()"><i class="fas fa-copy"></i> Copiar Todo</button>
                        </div>
                        <div class="card-body-dash">
                            <span class="info-label">Acceso Rápido SSH</span>
                            <div class="input-group mb-4">
                                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="fas fa-chevron-right"></i></span>
                                <input type="text" class="form-control bg-dark border-secondary text-success font-monospace" 
                                       value="<?php echo htmlspecialchars($creds['ssh_cmd'] ?? 'Cargando...'); ?>" readonly>
                            </div>

                            <span class="info-label">Credenciales Completas</span>
                            <div class="console-box" id="creds-box" style="height: 300px; overflow-y: auto;">
<?php echo isset($creds['ssh_pass']) ? htmlspecialchars($creds['ssh_pass']) : "Esperando inicialización del clúster...\nSi acaba de comprarlo, espere a que la barra de progreso finalice."; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-danger text-white">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-danger fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>ZONA DE PELIGRO</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Estás a punto de eliminar permanentemente el servicio <strong>#<?php echo $current_cluster['id'] ?? ''; ?></strong>.</p>
                    <div class="alert alert-danger border-0 bg-danger bg-opacity-25 text-danger">
                        <i class="fas fa-skull me-2"></i> Se perderán todos los datos, bases de datos y configuraciones. Esta acción es <strong>IRREVERSIBLE</strong>.
                    </div>
                    <p class="mb-2">Para confirmar, escribe la palabra <strong>Eliminar</strong> abajo:</p>
                    <input type="text" id="deleteConfirmInput" class="form-control bg-black text-white border-secondary" placeholder="Escribe Eliminar" autocomplete="off">
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    
                    <form method="POST">
                        <input type="hidden" name="order_id" value="<?php echo $current_cluster['id'] ?? ''; ?>">
                        <input type="hidden" name="action" value="terminate">
                        <button type="submit" id="btnConfirmDelete" class="btn btn-danger fw-bold" disabled>
                            <i class="fas fa-trash me-2"></i>CONFIRMAR ELIMINACIÓN
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copiarCreds() {
            const cmd = document.querySelector('input[readonly]').value;
            const details = document.getElementById('creds-box').innerText;
            navigator.clipboard.writeText(cmd + "\n\n" + details);
            alert("¡Copiado!");
        }

        // LÓGICA DEL MODAL DE BORRADO
        const deleteInput = document.getElementById('deleteConfirmInput');
        const deleteBtn = document.getElementById('btnConfirmDelete');

        if(deleteInput) {
            deleteInput.addEventListener('keyup', function() {
                if (this.value === 'Eliminar') {
                    deleteBtn.disabled = false;
                } else {
                    deleteBtn.disabled = true;
                }
            });
        }
    </script>
</body>
</html>