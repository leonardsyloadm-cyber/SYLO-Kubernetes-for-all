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
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
if ($stmt->fetchColumn() !== 'admin') die("<h1>403 ACCESO DENEGADO</h1>");

// --- 3. API ACCIONES ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // NUKE CLUSTER
    if (isset($input['action']) && $input['action'] === 'nuke_cluster') {
        $oid = $input['order_id'];
        $kill = ["id"=>$oid, "action"=>"TERMINATE", "admin"=>"admin", "timestamp"=>date("c")];
        file_put_contents("/buzon/kill_orden_$oid.json", json_encode($kill));
        $conn->prepare("UPDATE orders SET status='terminating' WHERE id=?")->execute([$oid]);
        echo json_encode(["status"=>"success"]);
        exit;
    }

    // NUKE USER
    if (isset($input['action']) && $input['action'] === 'delete_user') {
        $uid = $input['user_id'];
        $conn->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        echo json_encode(["status"=>"success", "message"=>"Usuario eliminado."]);
        exit;
    }

    // PURGE ALL
    if (isset($input['action']) && $input['action'] === 'purge_all') {
        $conn->exec("DELETE FROM orders");
        file_put_contents("/buzon/PURGE_ALL.signal", "true");
        chmod("/buzon/PURGE_ALL.signal", 0777);
        echo json_encode(["status"=>"success", "message"=>"Sistema purgado."]);
        exit;
    }
}

// API GET DETALLES PEDIDO
if (isset($_GET['get_details'])) {
    $oid = $_GET['get_details'];
    $sql = "SELECT o.*, 
                   u.username, u.email, u.company_name, u.full_name, 
                   u.tipo_usuario, u.dni, u.telefono, u.calle, u.tipo_empresa,
                   p.name as plan_name 
            FROM orders o 
            JOIN users u ON o.user_id = u.id 
            JOIN plans p ON o.plan_id = p.id 
            WHERE o.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$oid]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

// API GET DETALLES USUARIO
if (isset($_GET['get_user_details'])) {
    $uid = $_GET['get_user_details'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

// API GET LISTA USUARIOS
if (isset($_GET['list_users'])) {
    $users = $conn->query("SELECT * FROM users WHERE role='client' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($users);
    exit;
}

// DATOS DASHBOARD
// Ingresos (Solo activos)
$total_revenue = $conn->query("SELECT COALESCE(SUM(p.price), 0) FROM orders o JOIN plans p ON o.plan_id = p.id WHERE o.status IN ('active', 'suspended')")->fetchColumn();
// Activos + Suspendidos (Ya que existen físicamente)
$active_clusters = $conn->query("SELECT COUNT(*) FROM orders WHERE status IN ('active', 'suspended')")->fetchColumn();
$total_users = $conn->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
// Lista completa de pedidos
$orders = $conn->query("SELECT o.id, u.username, u.company_name, p.name as plan, o.status, o.purchase_date, p.price FROM orders o JOIN users u ON o.user_id = u.id JOIN plans p ON o.plan_id = p.id ORDER BY o.id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
        
        .kpi-card { background-color: #16213e; border: 1px solid #0f3460; border-radius: 10px; padding: 20px; transition: 0.3s; }
        .kpi-clickable:hover { border-color: #3b82f6; cursor: pointer; transform: translateY(-2px); box-shadow: 0 0 15px rgba(59, 130, 246, 0.3); }
        .kpi-value { font-size: 2.5rem; font-weight: bold; color: #fff; }
        
        /* TABLAS */
        .table-custom { background-color: #16213e; border-radius: 10px; overflow: hidden; }
        .table-custom th { background-color: #0f3460; color: #fff; border: none; padding: 15px; }
        .table-custom td { border-bottom: 1px solid #0f3460; padding: 15px; vertical-align: middle; font-weight: 500; }
        
        .id-cell { color: #ff4d4d; font-weight: 800; font-family: monospace; font-size: 1.1rem; }
        .client-cell { color: #ffffff !important; font-weight: 600; }
        
        /* COLORES */
        .plan-bronce { color: #cd7f32 !important; font-weight: bold; text-transform: uppercase; }
        .plan-plata { color: #e0e0e0 !important; font-weight: bold; text-transform: uppercase; }
        .plan-oro { color: #ffd700 !important; font-weight: bold; text-transform: uppercase; }
        .plan-custom { color: #3b82f6 !important; font-weight: bold; text-transform: uppercase; }

        .price-low { color: #fff9c4; font-weight: bold; } 
        .price-mid { color: #ffeb3b; font-weight: bold; } 
        .price-high { color: #ffd600; font-weight: 800; font-size: 1.1rem; } 

        /* ESTADOS */
        .status-badge { padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 0.8rem; text-transform: uppercase; display: inline-block; width: 100%; text-align: center; }
        .status-active { background-color: #10b981; color: white; }
        .status-suspended { background-color: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b; }
        .status-pending { background-color: rgba(255, 255, 255, 0.1); color: #ccc; }
        .status-creating { background-color: rgba(59, 130, 246, 0.2); color: #3b82f6; animation: pulse 1.5s infinite; }
        .status-error { background-color: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .status-terminated { background-color: #000; color: #666; border: 1px solid #333; }

        .btn-ver { background-color: #4b5563; color: white; border: none; font-size: 0.75rem; font-weight: bold; padding: 6px 12px; transition: 0.2s; }
        .btn-ver:hover { background-color: #374151; color: #fff; }
        .btn-eliminar { background-color: #dc2626; color: white; border: none; font-size: 0.75rem; font-weight: bold; padding: 6px 12px; transition: 0.2s; }
        .btn-eliminar:hover { background-color: #b91c1c; color: #fff; }

        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        
        /* MODALES */
        .modal-content { background: #1f2937; border: 1px solid #374151; color: white; }
        .detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #374151; }
        .detail-label { color: #9ca3af; font-weight: 600; width: 40%; }
        .detail-value { color: white; font-weight: 400; width: 60%; text-align: right; word-break: break-word; }
        .section-title { color: #3b82f6; font-weight: 800; font-size: 0.9rem; text-transform: uppercase; margin-top: 15px; margin-bottom: 10px; letter-spacing: 1px; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark mb-5">
    <div class="container-fluid px-5">
        <a class="navbar-brand" href="#"><i class="fas fa-biohazard me-2"></i>GOD MODE</a>
        <div class="d-flex gap-2">
            <button onclick="purgeAll()" class="btn btn-outline-danger btn-sm fw-bold"><i class="fas fa-radiation me-2"></i>PURGAR TODO</button>
            <a href="index.php" class="btn btn-outline-light btn-sm">Ir a Tienda</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-5">
    <div class="row g-4 mb-5">
        <div class="col-md-3"><div class="kpi-card"><div class="kpi-value"><?php echo number_format($total_revenue, 2); ?>€</div><small>Ingresos (Activos+Susp)</small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="kpi-value"><?php echo $active_clusters; ?></div><small>Infraestructura Viva</small></div></div>
        
        <div class="col-md-3" onclick="abrirGestionUsuarios()">
            <div class="kpi-card kpi-clickable">
                <div class="kpi-value"><?php echo $total_users; ?></div>
                <small class="text-info"><i class="fas fa-search me-1"></i>GESTIONAR CLIENTES</small>
            </div>
        </div>
        
        <div class="col-md-3"><div class="kpi-card border-danger"><div class="kpi-value text-danger">ONLINE</div><small>System</small></div></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><i class="fas fa-server me-2"></i>Flota de Clústeres</h4>
        <button onclick="location.reload()" class="btn btn-sm btn-outline-info"><i class="fas fa-sync-alt"></i></button>
    </div>

    <div class="table-responsive">
        <table class="table table-custom align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>CLIENTE</th>
                    <th>PLAN</th>
                    <th>PRECIO</th>
                    <th>ESTADO</th>
                    <th class="text-end">ACCIONES</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $o): 
                    $planClass = 'text-muted';
                    if($o['plan'] == 'Bronce') $planClass = 'plan-bronce';
                    elseif($o['plan'] == 'Plata') $planClass = 'plan-plata';
                    elseif($o['plan'] == 'Oro') $planClass = 'plan-oro';
                    elseif($o['plan'] == 'Personalizado') $planClass = 'plan-custom';

                    $priceClass = 'text-white';
                    if($o['price'] <= 5) $priceClass = 'price-low';
                    elseif($o['price'] <= 15) $priceClass = 'price-mid';
                    else $priceClass = 'price-high';

                    $st = strtolower($o['status']);
                    $statusClass = 'status-pending'; 
                    if($st == 'active') $statusClass = 'status-active';
                    elseif($st == 'suspended') $statusClass = 'status-suspended';
                    elseif($st == 'creating') $statusClass = 'status-creating';
                    elseif($st == 'terminated') $statusClass = 'status-terminated';
                    elseif($st == 'error') $statusClass = 'status-error';
                ?>
                <tr>
                    <td class="id-cell">#<?php echo $o['id']; ?></td>
                    <td class="client-cell">
                        <div><?php echo $o['username']; ?></div>
                        <small class="text-secondary fw-normal"><?php echo $o['company_name'] ?: 'Particular'; ?></small>
                    </td>
                    <td class="<?php echo $planClass; ?>"><?php echo strtoupper($o['plan']); ?></td>
                    <td class="<?php echo $priceClass; ?>"><?php echo number_format($o['price'], 2); ?>€</td>
                    <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo strtoupper($o['status']); ?></span></td>
                    <td class="text-end">
                        <button onclick="verDetalles(<?php echo $o['id']; ?>)" class="btn btn-ver rounded-start">VER DETALLES</button>
                        <?php if($st != 'terminated' && $st != 'cancelled'): ?>
                            <button onclick="nuke(<?php echo $o['id']; ?>)" class="btn btn-eliminar rounded-end">ELIMINAR</button>
                        <?php else: ?>
                            <button class="btn btn-dark rounded-end btn-sm" disabled>ELIMINADO</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="detailModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content p-4">
    <h4 class="mb-2 text-info"><i class="fas fa-id-card me-2"></i>Ficha Técnica</h4>
    <div id="modal-body-content" class="mb-3">Cargando...</div>
    <div class="text-end"><button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<div class="modal fade" id="usersModal"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="text-warning m-0"><i class="fas fa-users-cog me-2"></i>Gestión de Clientes</h4>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><i class="fas fa-times"></i></button>
    </div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle">
            <thead><tr><th>ID</th><th>Usuario</th><th>Nombre Completo</th><th>Email</th><th class="text-end">Acciones</th></tr></thead>
            <tbody id="users-table-body">
                </tbody>
        </table>
    </div>
</div></div></div>

<div class="modal fade" id="userDetailModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content p-4">
    <h4 class="mb-3 text-warning"><i class="fas fa-user-circle me-2"></i>Perfil de Usuario</h4>
    <div id="user-detail-content">Cargando...</div>
    <div class="mt-4 d-flex justify-content-between">
        <button class="btn btn-danger btn-sm" id="btn-delete-user" onclick="">ELIMINAR USUARIO</button>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
    </div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const detailModal = new bootstrap.Modal('#detailModal');
    const usersModal = new bootstrap.Modal('#usersModal');
    const userDetailModal = new bootstrap.Modal('#userDetailModal');

    // --- DETALLES DEL PEDIDO ---
    async function verDetalles(id) {
        detailModal.show();
        const res = await fetch(`admin.php?get_details=${id}`);
        const d = await res.json();
        
        const tipoIcon = d.tipo_usuario === 'empresa' ? '<i class="fas fa-building me-2"></i>' : '<i class="fas fa-user-tie me-2"></i>';
        const tipoLabel = d.tipo_usuario === 'empresa' ? 'EMPRESA' : 'AUTÓNOMO';

        let html = `
            <div class="text-center mb-3"><span class="badge bg-light text-dark fs-6">${tipoIcon} ${tipoLabel}</span></div>
            <div class="section-title">Datos Titular</div>
            <div class="detail-row"><span class="detail-label">Nombre:</span> <span class="detail-value">${d.full_name}</span></div>
            <div class="detail-row"><span class="detail-label">Email:</span> <span class="detail-value text-white">${d.email}</span></div>
            <div class="detail-row"><span class="detail-label">Teléfono:</span> <span class="detail-value">${d.telefono || 'N/A'}</span></div>
        `;

        if (d.tipo_usuario === 'autonomo') {
            html += `<div class="detail-row"><span class="detail-label">DNI/NIE:</span> <span class="detail-value">${d.dni || 'N/A'}</span></div>`;
        } else {
            html += `
                <div class="section-title">Datos Fiscales</div>
                <div class="detail-row"><span class="detail-label">Razón Social:</span> <span class="detail-value">${d.company_name}</span></div>
                <div class="detail-row"><span class="detail-label">Tipo:</span> <span class="detail-value">${d.tipo_empresa || 'N/A'}</span></div>
                <div class="detail-row"><span class="detail-label">Dirección:</span> <span class="detail-value">${d.calle || 'N/A'}</span></div>
            `;
        }
        document.getElementById('modal-body-content').innerHTML = html;
    }

    // --- GESTIÓN USUARIOS ---
    async function abrirGestionUsuarios() {
        usersModal.show();
        const res = await fetch('admin.php?list_users=true');
        const users = await res.json();
        const tbody = document.getElementById('users-table-body');
        tbody.innerHTML = '';

        users.forEach(u => {
            tbody.innerHTML += `
                <tr>
                    <td>#${u.id}</td>
                    <td class="fw-bold text-info">${u.username}</td>
                    <td>${u.full_name}</td>
                    <td class="small text-white">${u.email}</td>
                    <td class="text-end">
                        <button onclick="verUsuario(${u.id})" class="btn btn-ver btn-sm">VER</button>
                    </td>
                </tr>
            `;
        });
    }

    async function verUsuario(uid) {
        usersModal.hide(); 
        userDetailModal.show();
        
        const res = await fetch(`admin.php?get_user_details=${uid}`);
        const u = await res.json();

        let html = `
            <div class="detail-row"><span class="detail-label">ID:</span> <span class="detail-value">#${u.id}</span></div>
            <div class="detail-row"><span class="detail-label">Usuario:</span> <span class="detail-value fw-bold">${u.username}</span></div>
            <div class="detail-row"><span class="detail-label">Nombre:</span> <span class="detail-value">${u.full_name}</span></div>
            <div class="detail-row"><span class="detail-label">Tipo:</span> <span class="detail-value badge bg-secondary">${u.tipo_usuario}</span></div>
            <div class="detail-row"><span class="detail-label">Email:</span> <span class="detail-value text-white">${u.email}</span></div>
            <div class="detail-row"><span class="detail-label">Teléfono:</span> <span class="detail-value">${u.telefono}</span></div>
            <div class="detail-row"><span class="detail-label">Alta:</span> <span class="detail-value">${u.created_at}</span></div>
        `;
        
        document.getElementById('user-detail-content').innerHTML = html;
        document.getElementById('btn-delete-user').onclick = function() { borrarUsuario(u.id); };
    }

    async function borrarUsuario(uid) {
        if(!confirm("⚠️ ¿BORRAR USUARIO Y TODOS SUS PEDIDOS?\nEsta acción no se puede deshacer.")) return;
        
        const res = await fetch('admin.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action: 'delete_user', user_id: uid})
        });
        const d = await res.json();
        
        if(d.status === 'success') {
            alert(d.message);
            location.reload();
        } else {
            alert("Error al borrar usuario.");
        }
    }

    // --- ACCIONES GENERALES ---
    async function nuke(id) {
        if(!confirm("¿ELIMINAR CLÚSTER?")) return;
        await fetch('admin.php',{method:'POST',body:JSON.stringify({action:'nuke_cluster',order_id:id})});
        location.reload();
    }

    async function purgeAll() {
        if(!confirm("⚠️ ¿PURGA TOTAL?")) return;
        const res = await fetch('admin.php',{method:'POST',body:JSON.stringify({action:'purge_all'})});
        const d = await res.json();
        alert(d.message);
        location.reload();
    }
</script>
</body>
</html>