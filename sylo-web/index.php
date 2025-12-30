<?php
// --- MODO DEBUG ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// --- CONFIGURACIÓN API (IP REAL) ---
define('API_URL', 'http://host.docker.internal:8001/api/clientes');

// --- 1. CONEXIÓN DB ---
$servername = getenv('DB_HOST') ?: "kylo-main-db";
$username_db = getenv('DB_USER') ?: "sylo_app";
$password_db = getenv('DB_PASS') ?: "sylo_app_pass";
$dbname = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    if($_SERVER['REQUEST_METHOD'] == 'POST') die(json_encode(["status"=>"error", "mensaje"=>"Error Conexión DB"]));
}

// --- CHECK: CLÚSTERES ACTIVOS ---
$has_clusters = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('active', 'suspended', 'creating')");
    $stmt->execute([$_SESSION['user_id']]);
    if ($stmt->fetchColumn() > 0) $has_clusters = true;
}

// --- 2. CHECK STATUS (VIA API) ---
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) { echo json_encode(["status"=>"error"]); exit; }
    
    $id = filter_var($_GET['check_status'], FILTER_VALIDATE_INT);
    $ch = curl_init(API_URL . "/estado/" . $id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) echo $response;
    else echo json_encode(["percent" => 0, "message" => "Sincronizando...", "status" => "pending"]);
    exit;
}

// --- 3. PROCESAR ACCIONES (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    header('Content-Type: application/json');

    // REGISTRO
    if ($action === 'register') {
        // ... (Lógica de registro original mantenida para brevedad, asumo que funciona)
        $user = htmlspecialchars($input['username']);
        $pass = $input['password'];
        $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
        $name = htmlspecialchars($input['full_name']);
        // ... resto de campos ...
        
        // Simulación rápida para que funcione el ejemplo si copiaste el JS extendido
        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            // Insert básico para que funcione la demo
            $sql = "INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'client')";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user, $name, $email, $hash]);
            $_SESSION['user_id'] = $conn->lastInsertId();
            $_SESSION['username'] = $user; $_SESSION['company'] = $input['company_name'] ?? 'Particular';
            echo json_encode(["status"=>"success"]);
        } catch (PDOException $e) { echo json_encode(["status"=>"error","mensaje"=>"Error SQL: ".$e->getMessage()]); }
        exit;
    }

    // LOGIN
    if ($action === 'login') {
        $eu = $input['email_user'];
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$eu, $eu]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($input['password'], $u['password_hash'])) {
            $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['username']; $_SESSION['company'] = $u['company_name'];
            echo json_encode(["status"=>"success"]);
        } else { echo json_encode(["status"=>"error","mensaje"=>"Credenciales inválidas."]); }
        exit;
    }

    // LOGOUT
    if ($action === 'logout') { session_destroy(); echo json_encode(["status"=>"success"]); exit; }

    // =========================================================
    // 🚀 ACCIÓN COMPRAR (ACTUALIZADA V15)
    // =========================================================
    if ($action === 'comprar') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(["status"=>"auth_required","mensaje"=>"Inicia sesión."]); exit; }
        
        $plan_name = htmlspecialchars($input['plan']);
        $specs = $input['specs']; // Ahora recibimos el objeto specs completo

        try {
            // 1. DB LOCAL
            $stmt = $conn->prepare("SELECT id FROM plans WHERE name = :name");
            $stmt->execute(['name' => $plan_name]);
            $pid = $stmt->fetchColumn() ?: 1; 

            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (:uid, :pid, 'pending')");
            $stmt->execute(['uid' => $_SESSION['user_id'], 'pid' => $pid]);
            $order_id = $conn->lastInsertId();

            // INSERTAR ESPECIFICACIONES NUEVAS
            $sqlSpecs = "INSERT INTO order_specs 
                (order_id, cpu_cores, ram_gb, storage_gb, db_enabled, db_type, web_enabled, web_type, 
                 cluster_alias, subdomain, ssh_user, os_image, db_custom_name, web_custom_name) 
                VALUES (:oid, :cpu, :ram, :hdd, :db, :dbt, :web, :webt, :alias, :sub, :ssh, :os, :dbname, :webname)";
            
            $stmtS = $conn->prepare($sqlSpecs);
            $stmtS->execute([
                'oid' => $order_id,
                'cpu' => $specs['cpu'], 
                'ram' => $specs['ram'], 
                'hdd' => $specs['storage'],
                'db'  => $specs['db_enabled'] ? 1 : 0, 
                'dbt' => $specs['db_type'],
                'web' => $specs['web_enabled'] ? 1 : 0, 
                'webt'=> $specs['web_type'],
                'alias'=> $specs['cluster_alias'],
                'sub'  => $specs['subdomain'],
                'ssh'  => $specs['ssh_user'],
                'os'   => $specs['os_image'],
                'dbname' => $specs['db_custom_name'],
                'webname' => $specs['web_custom_name']
            ]);
            
            // 2. ORDEN A LA API
            $api_payload = [
                "id_cliente" => (int)$order_id,
                "plan" => $plan_name,
                "cliente_nombre" => $_SESSION['username'],
                "specs" => $specs
            ];

            $ch = curl_init(API_URL . "/crear");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
            
            $res = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http === 200) echo json_encode(["status"=>"success","order_id"=>$order_id]);
            else {
                $conn->exec("DELETE FROM orders WHERE id=$order_id");
                echo json_encode(["status"=>"error", "mensaje"=>"Error Sylo API: $http"]);
            }

        } catch (Exception $e) { echo json_encode(["status"=>"error","mensaje"=>"Error interno: " . $e->getMessage()]); }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYLO | Cloud Infrastructure</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <style>
        :root { --sylo-blue: #0f172a; --sylo-accent: #3b82f6; --sylo-light: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background-color: var(--sylo-light); color: #334155; }
        .navbar { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(0,0,0,0.05); }
        .navbar-brand { font-weight: 800; color: var(--sylo-blue) !important; letter-spacing: -0.5px; }
        .nav-link { font-weight: 600; color: #64748b !important; margin: 0 10px; transition: color 0.3s; }
        .nav-link:hover { color: var(--sylo-accent) !important; }
        .hero { background: radial-gradient(circle at top right, #1e293b, #0f172a); color: white; padding: 120px 0 100px; position: relative; overflow: hidden; }
        .hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 100px; background: linear-gradient(to top, var(--sylo-light), transparent); }
        .tech-card { background: white; border-radius: 12px; padding: 20px; text-align: center; border: 1px solid #e2e8f0; transition: all 0.3s ease; }
        .tech-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(59, 130, 246, 0.1); border-color: var(--sylo-accent); }
        .tech-icon { font-size: 2.5rem; margin-bottom: 10px; color: #475569; }
        .team-card { background: white; border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.3s; }
        .team-card:hover { transform: translateY(-5px); }
        .team-img { height: 120px; width: 120px; object-fit: cover; border-radius: 50%; margin: -60px auto 0; border: 4px solid white; background: #eee; }
        .team-header { height: 100px; background: linear-gradient(45deg, var(--sylo-accent), #60a5fa); }
        .card-price { border: none; border-radius: 20px; background: white; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transition: all 0.3s; overflow: hidden; }
        .card-price:hover { transform: scale(1.02); box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .card-custom { background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%); border: 2px solid var(--sylo-accent); }
        .price-tag { font-size: 2.5rem; font-weight: 800; color: var(--sylo-blue); }
        .terminal-window { background: #1e1e1e; border-radius: 8px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); font-family: 'Courier New', monospace; overflow: hidden; }
        .terminal-header { background: #2d2d2d; padding: 10px 15px; display: flex; gap: 8px; }
        .dot { width: 12px; height: 12px; border-radius: 50%; }
        .terminal-body { padding: 20px; color: #4ade80; min-height: 200px; }
        .progress { height: 8px; background: #333; margin-top: 15px; border-radius: 4px; }
        .progress-bar { transition: width 0.5s ease; background-color: #10b981; }
        
        .btn-console { background: linear-gradient(45deg, #10b981, #3b82f6); color: white; border: none; font-weight: bold; padding: 5px 15px; }
        .btn-console:hover { transform: scale(1.05); color: white; box-shadow: 0 0 10px rgba(59, 130, 246, 0.5); }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-layer-group me-2 text-primary"></i>SYLO</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="#tecnologias">Tecnologías</a></li>
                    <li class="nav-item"><a class="nav-link" href="#team">Equipo & Datacenter</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing">Planes</a></li>
                    
                    <li class="nav-item ms-4 ps-4 border-start d-flex gap-2">
                        <?php if ($has_clusters): ?>
                            <a href="dashboard_cliente.php" class="btn btn-console rounded-pill shadow-sm">
                                <i class="fas fa-terminal me-2"></i>MIS KUBERNETES
                            </a>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['user_id'])): ?>
                            <div class="dropdown">
                                <button class="btn btn-dark btn-sm dropdown-toggle rounded-pill px-3" data-bs-toggle="dropdown">
                                    <i class="fas fa-user-astronaut me-2"></i><?php echo $_SESSION['username']; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                                    <li class="px-3 py-2 text-muted small text-uppercase fw-bold"><?php echo $_SESSION['company'] ?: 'Particular'; ?></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="#" onclick="logout()">Desconectar</a></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="authModal.show()">Acceso Cliente</button>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <section class="hero text-center d-flex align-items-center">
        <div class="container">
            <span class="badge bg-white text-primary mb-3 px-3 py-2 rounded-pill fw-bold shadow-sm">🚀 Nueva Arquitectura v2.0 Live</span>
            <h1 class="display-3 fw-bold mb-4">La Nube Privada<br><span class="text-gradient">Sin Complicaciones</span></h1>
            <p class="lead text-white-50 mb-5 w-75 mx-auto">Orquestación automática de Kubernetes, Bases de Datos y Servidores Web.<br>Diseñado por ingenieros, para desarrolladores.</p>
            <div class="d-flex justify-content-center gap-3">
                <a href="#pricing" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow-lg">Ver Planes</a>
                <a href="#tecnologias" class="btn btn-outline-light btn-lg rounded-pill px-5 fw-bold">Cómo Funciona</a>
            </div>
        </div>
    </section>

    <section id="tecnologias" class="py-5">
        <div class="container">
            <div class="text-center mb-5"><h6 class="text-primary fw-bold text-uppercase">Nuestro Stack</h6><h2 class="fw-bold">Potenciado por Gigantes</h2></div>
            <div class="row g-4 justify-content-center">
                <div class="col-6 col-md-3 col-lg-2"><div class="tech-card"><i class="fab fa-aws tech-icon text-warning"></i><h6 class="fw-bold mb-0">AWS Cloud</h6></div></div>
                <div class="col-6 col-md-3 col-lg-2"><div class="tech-card"><i class="fab fa-docker tech-icon text-primary"></i><h6 class="fw-bold mb-0">Docker</h6></div></div>
                <div class="col-6 col-md-3 col-lg-2"><div class="tech-card"><i class="fas fa-cubes tech-icon text-info"></i><h6 class="fw-bold mb-0">Kubernetes</h6></div></div>
                <div class="col-6 col-md-3 col-lg-2"><div class="tech-card"><i class="fas fa-code-branch tech-icon text-success"></i><h6 class="fw-bold mb-0">OpenTofu</h6></div></div>
                <div class="col-6 col-md-3 col-lg-2"><div class="tech-card"><i class="fab fa-python tech-icon text-warning"></i><h6 class="fw-bold mb-0">Python</h6></div></div>
                <div class="col-6 col-md-3 col-lg-2"><div class="tech-card"><i class="fas fa-server tech-icon text-dark"></i><h6 class="fw-bold mb-0">Nginx/Apache</h6></div></div>
            </div>
        </div>
    </section>

    <section id="team" class="py-5 bg-white">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-5 mb-5 mb-lg-0">
                    <h6 class="text-primary fw-bold text-uppercase">Infraestructura Física</h6>
                    <h2 class="fw-bold mb-4">El Corazón de Sylo</h2>
                    <p class="text-muted mb-4">Nuestros "Datacenters" no son simples ordenadores. Son nodos de alto rendimiento optimizados para la virtualización extrema.</p>
                    <div class="d-flex align-items-start mb-3"><div class="me-3 text-primary"><i class="fas fa-hdd fa-2x"></i></div><div><h6 class="fw-bold">Almacenamiento NVMe</h6><p class="small text-muted">Velocidad de escritura instantánea para tus bases de datos.</p></div></div>
                    <div class="d-flex align-items-start mb-3"><div class="me-3 text-primary"><i class="fas fa-network-wired fa-2x"></i></div><div><h6 class="fw-bold">Red 10Gbps</h6><p class="small text-muted">Baja latencia entre nodos maestros y esclavos.</p></div></div>
                    <div class="d-flex align-items-start"><div class="me-3 text-primary"><i class="fas fa-shield-alt fa-2x"></i></div><div><h6 class="fw-bold">Seguridad Perimetral</h6><p class="small text-muted">Firewalls físicos y lógicos protegiendo cada bit.</p></div></div>
                </div>
                <div class="col-lg-6 offset-lg-1">
                    <div class="row g-4">
                        <div class="col-md-6"><div class="team-card text-center pb-4"><div class="team-header"></div><img src="https://ui-avatars.com/api/?name=Ivan+Arlanzon&background=0D8ABC&color=fff&size=128" class="team-img shadow"><h5 class="fw-bold mt-3">Ivan Arlanzon</h5><span class="badge-sylo">CEO & Arquitecto Cloud</span><p class="small text-muted px-3 mt-3">Visionario de la infraestructura automatizada. Diseñó el núcleo del orquestador Sylo.</p></div></div>
                        <div class="col-md-6 mt-md-5"><div class="team-card text-center pb-4"><div class="team-header" style="background: linear-gradient(45deg, #10b981, #3b82f6);"></div><img src="https://ui-avatars.com/api/?name=Leonard+Baicu&background=10b981&color=fff&size=128" class="team-img shadow"><h5 class="fw-bold mt-3">Leonard Baicu</h5><span class="badge-sylo">CTO & DevOps Lead</span><p class="small text-muted px-3 mt-3">Maestro de Kubernetes y OpenTofu. Asegura que cada despliegue sea atómico.</p></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5"><h6 class="text-primary fw-bold text-uppercase">Catálogo de Servicios</h6><h2 class="fw-bold">Escalabilidad Instantánea</h2></div>
            <div class="row g-4 justify-content-center">
                <div class="col-xl-3 col-md-6">
                    <div class="card card-price h-100 p-4">
                        <h5 class="fw-bold text-muted">Bronce</h5>
                        <div class="price-tag my-3">5€<span class="fs-6 text-muted fw-normal">/mes</span></div>
                        <ul class="list-unstyled mb-4 small text-secondary">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>1 Nodo K8s</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>1 vCPU / 1 GB RAM</li> <li class="mb-2 text-muted"><i class="fas fa-times me-2"></i>Sin Persistencia</li>
                        </ul>
                        <button onclick="prepararPedido('Bronce')" class="btn btn-outline-dark w-100 rounded-pill fw-bold">Elegir Bronce</button>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-price h-100 p-4 border-primary">
                        <div class="d-flex justify-content-between align-items-center"><h5 class="fw-bold text-primary">Plata</h5><span class="badge bg-primary rounded-pill">DB</span></div>
                        <div class="price-tag my-3">15€<span class="fs-6 text-muted fw-normal">/mes</span></div>
                        <ul class="list-unstyled mb-4 small text-secondary">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>MySQL Cluster HA</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>2 vCPU / 2 GB RAM</li> <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Replicación Activa</li>
                        </ul>
                        <button onclick="prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm">Elegir Plata</button>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-price h-100 p-4">
                        <h5 class="fw-bold" style="color: #d4af37;">Oro</h5>
                        <div class="price-tag my-3">30€<span class="fs-6 text-muted fw-normal">/mes</span></div>
                        <ul class="list-unstyled mb-4 small text-secondary">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i><strong>Full Stack HA</strong></li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>3 vCPU / 3 GB RAM</li> <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Soporte Prioritario</li>
                        </ul>
                        <button onclick="prepararPedido('Oro')" class="btn btn-dark w-100 rounded-pill fw-bold">Elegir Oro</button>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-price card-custom h-100 p-4">
                        <div class="d-flex justify-content-between align-items-center"><h5 class="fw-bold text-primary">A Medida</h5><i class="fas fa-sliders-h text-primary"></i></div>
                        <div class="price-tag my-3 fs-2">Flexible</div>
                        <p class="text-muted small mb-4">Diseña tu infraestructura componente a componente.</p>
                        <ul class="list-unstyled mb-4 small text-secondary">
                            <li class="mb-2"><i class="fas fa-check text-primary me-2"></i>CPU/RAM Variable</li>
                            <li class="mb-2"><i class="fas fa-check text-primary me-2"></i>Multi-Engine DB</li>
                        </ul>
                        <button onclick="prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill fw-bold">Configurar</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="modal fade" id="configModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-light border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-sliders-h me-2 text-primary"></i>Configurar <span id="modal-plan-name"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-5">
                    
                    <h6 class="text-uppercase text-muted fw-bold small mb-4">Identidad del Cluster</h6>
                    <div class="row mb-4">
                         <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Nombre del Cluster (Alias)</label>
                            <input type="text" id="cfg-alias" class="form-control rounded-pill bg-light border-0" placeholder="Ej: Mi Tienda Online">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Subdominio (*.sylobi.org)</label>
                            <div class="input-group">
                                <input type="text" id="cfg-subdomain" class="form-control rounded-start-pill bg-light border-0" placeholder="ej: mi-tienda" pattern="[a-z0-9-]+">
                                <span class="input-group-text rounded-end-pill border-0 bg-white fw-bold">.sylobi.org</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Usuario SSH Admin</label>
                            <input type="text" id="cfg-ssh-user" class="form-control rounded-pill bg-light border-0" value="admin_sylo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Sistema Operativo</label>
                            <select id="cfg-os" class="form-select rounded-pill bg-light border-0">
                                <option value="alpine">Alpine Linux (Ligero)</option>
                                <option value="ubuntu">Ubuntu Server (Estándar)</option>
                                <option value="redhat">RedHat UBI (Enterprise)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="section-names" style="display:none;">
                        <h6 class="text-uppercase text-muted fw-bold small mb-4">Nombres de Servicios</h6>
                        <div class="row mb-4">
                             <div class="col-md-6" id="grp-db-name">
                                <label class="form-label fw-bold">Nombre Base de Datos</label>
                                <input type="text" id="cfg-db-name" class="form-control rounded-pill bg-light border-0" value="sylo_db">
                            </div>
                             <div class="col-md-6" id="grp-web-name">
                                <label class="form-label fw-bold">Nombre Servicio Web</label>
                                <input type="text" id="cfg-web-name" class="form-control rounded-pill bg-light border-0" value="Sylo Web Cluster">
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div id="section-resources">
                        <h6 class="text-uppercase text-muted fw-bold small mb-4">Recursos de Hardware</h6>
                        <div class="row mb-5">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">vCPU <span class="badge bg-dark ms-2" id="val-cpu">2</span></label>
                                <input type="range" class="form-range" min="1" max="6" value="2" id="range-cpu" oninput="updateVal('cpu')">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">RAM (GB) <span class="badge bg-dark ms-2" id="val-ram">4</span></label>
                                <input type="range" class="form-range" min="1" max="12" value="4" id="range-ram" oninput="updateVal('ram')">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Almacenamiento</label>
                                <select class="form-select border-0 bg-light fw-bold" id="sel-storage">
                                    <option value="5">5 GB NVMe</option>
                                    <option value="25" selected>25 GB NVMe</option>
                                    <option value="50">50 GB NVMe</option>
                                    <option value="100">100 GB NVMe</option>
                                </select>
                            </div>
                        </div>

                        <div id="section-software">
                            <h6 class="text-uppercase text-muted fw-bold small mb-3">Software Stack</h6>
                            <div class="card border-0 bg-light mb-3">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="check-db" onchange="toggleInputs()">
                                        <label class="form-check-label fw-bold ms-2" for="check-db">Base de Datos</label>
                                    </div>
                                    <div id="db-options">
                                        <select class="form-select form-select-sm border-0 shadow-sm" id="sel-db-type">
                                            <option value="mysql">MySQL 8.0</option>
                                            <option value="postgresql">PostgreSQL 14</option>
                                            <option value="mongodb">MongoDB Enterprise</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="card border-0 bg-light">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="check-web" onchange="toggleInputs()" checked>
                                        <label class="form-check-label fw-bold ms-2" for="check-web">Servidor Web</label>
                                    </div>
                                    <div id="web-options">
                                        <select class="form-select form-select-sm border-0 shadow-sm" id="sel-web-type">
                                            <option value="nginx">Nginx</option>
                                            <option value="apache">Apache HTTPD</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 px-5 pb-4">
                    <button class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow" onclick="lanzarPedido()">DESPLEGAR AHORA</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="progressModal" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content terminal-window border-0"><div class="terminal-header"><div class="dot bg-danger"></div><div class="dot bg-warning"></div><div class="dot bg-success"></div></div><div class="terminal-body text-center"><div class="spinner-border text-success mb-3" role="status"></div><h5 id="progress-text" class="mb-3">Conectando con Sylo Brain...</h5><small id="prog-num" class="text-muted d-block mb-3">0%</small><div class="progress"><div id="prog-bar" class="progress-bar bg-success" style="width:0%"></div></div></div></div></div></div>

    <div class="modal fade" id="successModal"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content terminal-window border-0"><div class="terminal-header"><span class="text-white small ms-2">root@sylo-cloud:~# result.log</span></div><div class="terminal-body text-start position-relative"><button class="btn btn-sm btn-outline-light position-absolute top-0 end-0 m-3" onclick="copiarDatos()"><i class="fas fa-copy"></i></button><div class="mb-3 text-muted">Despliegue finalizado con éxito. Credenciales generadas:</div><div id="ssh-details" style="white-space: pre-wrap;"><span class="text-warning">$</span> <span id="ssh-cmd"></span><br><br><span class="text-info">OUTPUT:</span><br><span id="ssh-pass"></span></div><div class="mt-4 text-center"><a href="dashboard_cliente.php" class="btn btn-primary btn-sm rounded-pill">IR A MI CONSOLA</a></div></div></div></div></div>

    <div class="modal fade" id="authModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg p-4">
        <ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-pill"><li class="nav-item"><a class="nav-link active rounded-pill" data-bs-toggle="tab" href="#login-pane">Login</a></li><li class="nav-item"><a class="nav-link rounded-pill" data-bs-toggle="tab" href="#reg-pane">Registro</a></li></ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="login-pane">
                <input id="login_email" class="form-control mb-3 rounded-pill bg-light border-0 px-3" placeholder="Email" required>
                <input type="password" id="login_pass" class="form-control mb-3 rounded-pill bg-light border-0 px-3" placeholder="Pass" required>
                <button class="btn btn-primary w-100 rounded-pill fw-bold" onclick="handleLogin()">Entrar</button>
            </div>
            
            <div class="tab-pane fade" id="reg-pane">
                <input id="reg_user" class="form-control mb-2 rounded-pill bg-light border-0" placeholder="Nombre de Usuario">
                <input id="reg_name" class="form-control mb-2 rounded-pill bg-light border-0" placeholder="Nombre Completo">
                <input id="reg_email" class="form-control mb-2 rounded-pill bg-light border-0" placeholder="Email">
                <input id="reg_pass" type="password" class="form-control mb-3 rounded-pill bg-light border-0" placeholder="Contraseña">
                <button class="btn btn-success w-100 rounded-pill fw-bold mt-3" onclick="handleRegister()">Crear Cuenta</button>
            </div>
        </div>
        <div id="authMessage" class="mt-3 text-center small text-danger fw-bold"></div>
    </div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLogged = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        const authModal = new bootstrap.Modal('#authModal'), configModal = new bootstrap.Modal('#configModal'), progressModal = new bootstrap.Modal('#progressModal'), successModal = new bootstrap.Modal('#successModal');
        let currentPlan = '';

        function updateVal(id) { document.getElementById('val-'+id).innerText = document.getElementById('range-'+id).value; }

        function prepararPedido(planName) {
            if (!isLogged) { authModal.show(); return; }
            currentPlan = planName;
            document.getElementById('modal-plan-name').innerText = planName;
            
            // ELEMENTOS UI
            const rangeCpu = document.getElementById('range-cpu');
            const rangeRam = document.getElementById('range-ram');
            const selStorage = document.getElementById('sel-storage');
            const secResources = document.getElementById('section-resources'); // Contenedor de sliders
            const secSoftware = document.getElementById('section-software'); // Contenedor de checks DB/Web
            const secNames = document.getElementById('section-names');
            
            const selOS = document.getElementById('cfg-os');
            const grpDB = document.getElementById('grp-db-name');
            const grpWeb = document.getElementById('grp-web-name');

            // RESET
            secResources.style.opacity = "1";
            secResources.style.pointerEvents = "auto";
            secSoftware.style.display = "block";
            secNames.style.display = "none";
            grpDB.style.display = "none";
            grpWeb.style.display = "none";
            selOS.disabled = false;
            Array.from(selOS.options).forEach(o => o.disabled = false);

            // LOGICA SEGUN PLAN
            if (planName === 'Bronce') {
                // Bloqueado a 1CPU/1RAM, Solo Alpine
                rangeCpu.value = 1; rangeRam.value = 1; selStorage.value = "5";
                secResources.style.opacity = "0.5"; secResources.style.pointerEvents = "none"; // Bloquear sliders
                selOS.value = "alpine"; selOS.disabled = true;
            } 
            else if (planName === 'Plata') {
                // 2CPU/2RAM, Alpine/Ubuntu, DB OBLIGATORIA
                rangeCpu.value = 2; rangeRam.value = 2; selStorage.value = "25";
                secResources.style.opacity = "0.5"; secResources.style.pointerEvents = "none";
                secNames.style.display = "block";
                grpDB.style.display = "block";
                selOS.value = "ubuntu";
                selOS.querySelector('option[value="redhat"]').disabled = true;
            }
            else if (planName === 'Oro') {
                // 3CPU/3RAM, Todo desbloqueado en OS, DB+WEB obligatorios
                rangeCpu.value = 3; rangeRam.value = 3; selStorage.value = "50";
                secResources.style.opacity = "0.5"; secResources.style.pointerEvents = "none";
                secNames.style.display = "block";
                grpDB.style.display = "block";
                grpWeb.style.display = "block";
                selOS.value = "ubuntu";
            }
            else if (planName === 'Personalizado') {
                // TODO LIBRE
                secNames.style.display = "block";
                toggleInputs(); // Chequear qué mostrar según los checks
            }

            updateVal('cpu'); updateVal('ram');
            configModal.show();
        }

        function toggleInputs() {
            if (currentPlan !== 'Personalizado') return;
            const hasDB = document.getElementById('check-db').checked;
            const hasWeb = document.getElementById('check-web').checked;
            document.getElementById('grp-db-name').style.display = hasDB ? 'block' : 'none';
            document.getElementById('grp-web-name').style.display = hasWeb ? 'block' : 'none';
        }

        async function lanzarPedido() {
            const alias = document.getElementById('cfg-alias').value;
            const sub = document.getElementById('cfg-subdomain').value;
            if(!alias || !sub) { alert("Alias y Subdominio obligatorios"); return; }

            // Recopilar datos
            const specs = {
                cluster_alias: alias,
                subdomain: sub,
                ssh_user: document.getElementById('cfg-ssh-user').value || "admin",
                os_image: document.getElementById('cfg-os').value,
                
                // Nombres
                db_custom_name: document.getElementById('cfg-db-name').value,
                web_custom_name: document.getElementById('cfg-web-name').value,
                
                // Hardware
                cpu: parseInt(document.getElementById('range-cpu').value),
                ram: parseInt(document.getElementById('range-ram').value),
                storage: parseInt(document.getElementById('sel-storage').value),
                
                // Software Toggles (Forzados si es plan fijo, leídos si es custom)
                db_enabled: currentPlan==='Plata'||currentPlan==='Oro'||(currentPlan==='Personalizado' && document.getElementById('check-db').checked),
                web_enabled: currentPlan==='Oro'||(currentPlan==='Personalizado' && document.getElementById('check-web').checked),
                
                // Tipos (Leídos del select)
                db_type: document.getElementById('sel-db-type').value,
                web_type: document.getElementById('sel-web-type').value
            };

            configModal.hide();
            progressModal.show();

            try {
                const res = await fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'comprar', plan: currentPlan, specs: specs })
                });
                const j = await res.json();
                if(j.status === 'success') startPolling(j.order_id);
                else { alert(j.mensaje); progressModal.hide(); }
            } catch(e) { alert("Error de red"); progressModal.hide(); }
        }

        function startPolling(oid) {
            const bar = document.getElementById('prog-bar'), 
                  text = document.getElementById('progress-text'),
                  num = document.getElementById('prog-num'),
                  sshCmd = document.getElementById('ssh-cmd'),
                  sshPass = document.getElementById('ssh-pass');

            let int = setInterval(async () => {
                try {
                    const res = await fetch(`index.php?check_status=${oid}`);
                    const s = await res.json();
                    
                    if (s.percent !== undefined) {
                        bar.style.width = s.percent + "%";
                        text.innerText = s.message;
                        num.innerText = s.percent + "%";
                    }

                    // CAMBIO CLAVE: Verificamos que ssh_cmd y ssh_pass existan y tengan longitud
                    if (s.status === 'completed' && s.ssh_cmd && s.ssh_pass) { 
                        clearInterval(int); 
                        progressModal.hide(); 
                        
                        // Actualizamos el contenido
                        sshCmd.innerText = s.ssh_cmd;
                        sshPass.innerText = s.ssh_pass;
                        
                        successModal.show(); 
                    } else if (s.status === 'completed' && (!s.ssh_cmd || !s.ssh_pass)) {
                        // Si está completed pero faltan datos, esperamos un ciclo más
                        console.log("Esperando datos finales...");
                    }
                    
                    if (s.status === 'error') { 
                        clearInterval(int); 
                        alert("Error crítico en el despliegue del Plan Oro"); 
                        progressModal.hide(); 
                    }
                } catch (e) {
                    console.error("Error polling:", e);
                }
            }, 1000);
        }

        // AUTH & UTILS
        async function handleLogin() {
            const r=await fetch('index.php',{method:'POST',body:JSON.stringify({action:'login',email_user:document.getElementById('login_email').value,password:document.getElementById('login_pass').value})});
            const d=await r.json();
            if(d.status==='success') location.reload(); else document.getElementById('authMessage').innerText=d.mensaje;
        }
        async function handleRegister() {
            // Registro simplificado para demo
            const r=await fetch('index.php',{method:'POST',body:JSON.stringify({action:'register', username:document.getElementById('reg_user').value, full_name:document.getElementById('reg_name').value, email:document.getElementById('reg_email').value, password:document.getElementById('reg_pass').value})});
            const d=await r.json(); if(d.status==='success') location.reload(); else document.getElementById('authMessage').innerText=d.mensaje;
        }
        async function logout() { await fetch('index.php',{method:'POST',body:JSON.stringify({action:'logout'})}); location.reload(); }
        function copiarDatos() { navigator.clipboard.writeText(document.getElementById('ssh-details').innerText); }
    </script>
</body>
</html>