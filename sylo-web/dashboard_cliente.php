<?php
// =================================================================================
// 🏛️ SYLO WEB V115 - FINAL STABLE (CALCULATOR V99 LOGIC RESTORED + ALL FIXES)
// =================================================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

define('API_URL', 'http://host.docker.internal:8001/api/clientes');

// --- 1. DB ---
$db_host = getenv('DB_HOST') ?: "kylo-main-db";
$db_user = getenv('DB_USER') ?: "sylo_app";
$db_pass = getenv('DB_PASS') ?: "sylo_app_pass";
$db_name = getenv('DB_NAME') ?: "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { if($_SERVER['REQUEST_METHOD']=='POST') die(json_encode(["status"=>"error"])); }

// --- CHECK USER ---
$has_clusters = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('active', 'suspended', 'creating', 'pending')");
    $stmt->execute([$_SESSION['user_id']]);
    if ($stmt->fetchColumn() > 0) $has_clusters = true;
}

// --- 2. STATUS CHECK ---
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    $id = filter_var($_GET['check_status'], FILTER_VALIDATE_INT);
    $ch = curl_init(API_URL . "/estado/" . $id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && $res) echo $res;
    else echo json_encode(["percent" => 10, "message" => "Conectando al Núcleo...", "status" => "pending"]);
    exit;
}

// --- 3. POST HANDLER ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    header('Content-Type: application/json');

    if ($action === 'register') {
        try {
            if ($input['password'] !== $input['password_confirm']) throw new Exception("Pass mismatch");
            $user = htmlspecialchars($input['username']);
            $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
            $pass = password_hash($input['password'], PASSWORD_BCRYPT);
            $tipo = $input['tipo_usuario'];
            $fn = ($tipo === 'autonomo') ? $input['full_name'] : $input['contact_name'];
            $dn = ($tipo === 'autonomo') ? $input['dni'] : $input['cif'];
            $cn = ($tipo === 'empresa') ? $input['company_name'] : null;
            $te = ($tipo === 'empresa') ? $input['tipo_empresa'] : null;
            $sql = "INSERT INTO users (username, full_name, email, password_hash, role, tipo_usuario, dni, telefono, company_name, tipo_empresa, calle, created_at) VALUES (?, ?, ?, ?, 'client', ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user, $fn, $email, $pass, $tipo, $dn, $input['telefono'], $cn, $te, $input['calle']]);
            $_SESSION['user_id'] = $conn->lastInsertId(); $_SESSION['username'] = $user; $_SESSION['company'] = $cn ?: 'Particular';
            echo json_encode(["status"=>"success"]);
        } catch (Exception $e) { echo json_encode(["status"=>"error", "mensaje"=>$e->getMessage()]); }
        exit;
    }
    if ($action === 'login') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$input['email_user'], $input['email_user']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($input['password'], $u['password_hash'])) {
            $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['username']; $_SESSION['company'] = $u['company_name'];
            echo json_encode(["status"=>"success"]);
        } else echo json_encode(["status"=>"error", "mensaje"=>"Credenciales inválidas"]);
        exit;
    }
    if ($action === 'logout') { session_destroy(); echo json_encode(["status"=>"success"]); exit; }
    if ($action === 'comprar') {
        if (!isset($_SESSION['user_id'])) exit(json_encode(["status"=>"auth_required"]));
        $plan = htmlspecialchars($input['plan']); $s = $input['specs']; 
        try {
            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (?, 1, 'pending')");
            $stmt->execute([$_SESSION['user_id']]);
            $oid = $conn->lastInsertId();
            $sqlS = "INSERT INTO order_specs (order_id, cpu_cores, ram_gb, storage_gb, db_enabled, db_type, web_enabled, web_type, cluster_alias, subdomain, ssh_user, os_image, db_custom_name, web_custom_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtS = $conn->prepare($sqlS);
            $stmtS->execute([$oid, $s['cpu'], $s['ram'], $s['storage'], $s['db_enabled']?1:0, $s['db_type'], $s['web_enabled']?1:0, $s['web_type'], $s['cluster_alias'], $s['subdomain'], $s['ssh_user'], $s['os_image'], $s['db_custom_name'], $s['web_custom_name']]);
            
            $payload = ["id_cliente" => (int)$oid, "plan" => $plan, "cliente_nombre" => $_SESSION['username'], "specs" => $s, "id_usuario_real" => (string)$_SESSION['user_id']];
            $ch = curl_init(API_URL . "/crear");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_exec($ch); curl_close($ch);
            echo json_encode(["status"=>"success", "order_id"=>$oid]);
        } catch (Exception $e) { echo json_encode(["status"=>"error", "mensaje"=>$e->getMessage()]); }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYLO | Cloud Engineering</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    
    <style>
        :root { --sylo-bg: #f8fafc; --sylo-text: #334155; --sylo-card: #ffffff; --sylo-accent: #2563eb; --input-bg: #f1f5f9; }
        [data-theme="dark"] { --sylo-bg: #0f172a; --sylo-text: #f1f5f9; --sylo-card: #1e293b; --sylo-accent: #3b82f6; --input-bg: #334155; }
        
        body { font-family: 'Inter', sans-serif; background-color: var(--sylo-bg); color: var(--sylo-text); transition: 0.3s; }
        .navbar { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(0,0,0,0.05); }
        [data-theme="dark"] .navbar { background: rgba(15, 23, 42, 0.95); border-bottom: 1px solid rgba(255,255,255,0.1); }
        [data-theme="dark"] .navbar-brand, [data-theme="dark"] .nav-link { color: white !important; }
        
        /* THEME ELEMENTS */
        .info-card, .bg-white { background-color: var(--sylo-card) !important; color: var(--sylo-text); }
        [data-theme="dark"] .text-muted { color: #94a3b8 !important; }
        [data-theme="dark"] .bg-light { background-color: #1e293b !important; border-color: #334155; }
        [data-theme="dark"] .form-control, [data-theme="dark"] .form-select { background-color: var(--input-bg); border-color: #475569; color: white; }
        [data-theme="dark"] .modal-content { background-color: var(--sylo-card); color: white; }
        
        .hero { padding: 140px 0 100px; background: linear-gradient(180deg, var(--sylo-bg) 0%, var(--sylo-card) 100%); }
        
        /* CALCULATOR */
        .calc-box { background: #1e293b; border-radius: 24px; padding: 40px; color: white; border: 1px solid #334155; }
        .price-display { font-size: 3.5rem; font-weight: 800; color: var(--sylo-accent); }
        
        /* SLIDERS AZULES FUERTES (FIXED) */
        input[type=range] { -webkit-appearance: none; width: 100%; background: transparent; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; height: 20px; width: 20px; border-radius: 50%; background: #3b82f6; cursor: pointer; margin-top: -8px; box-shadow: 0 0 10px #3b82f6; }
        input[type=range]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #475569; border-radius: 2px; }
        input[type=range]:focus::-webkit-slider-runnable-track { background: #3b82f6; }

        /* 3D CARDS */
        .card-stack-container { perspective: 1500px; height: 600px; cursor: pointer; position: relative; margin-bottom: 30px; }
        .card-face { width: 100%; height: 100%; position: relative; transform-style: preserve-3d; transition: transform 0.8s; border-radius: 24px; }
        .card-stack-container.active .card-face { transform: rotateY(180deg); }
        .face-front, .face-back { position: absolute; width: 100%; height: 100%; top:0; left:0; backface-visibility: hidden; border-radius: 24px; padding: 30px; display: flex; flex-direction: column; justify-content: space-between; background: var(--sylo-card); border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .face-front { z-index: 2; transform: rotateY(0deg); pointer-events: auto; }
        .face-back { z-index: 1; transform: rotateY(180deg); background: var(--sylo-bg); border-color: var(--sylo-accent); pointer-events: none; }
        .card-stack-container.active .face-front { pointer-events: none; }
        .card-stack-container.active .face-back { pointer-events: auto; }
        
        /* UI HELPERS */
        .bench-bar { height: 35px; border-radius: 8px; display: flex; align-items: center; padding: 0 15px; color: white; margin-bottom: 8px; transition: width 1s; }
        .b-sylo { background: linear-gradient(90deg, #2563eb, #3b82f6); }
        .b-aws { background: #64748b; }
        .success-box { background: black; color: white; border-radius: 8px; padding: 20px; font-family: 'JetBrains Mono', monospace; text-align: left; }
        .status-dot { width: 10px; height: 10px; background-color: #22c55e; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0% {box-shadow:0 0 0 0 rgba(34,197,94,0.7);} 70% {box-shadow:0 0 0 6px rgba(34,197,94,0);} 100% {box-shadow:0 0 0 0 rgba(34,197,94,0);} }
        
        .modal-content { border:none; border-radius: 16px; }
        .avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 15px; }
        .k8s-specs li { display: flex; justify-content: space-between; border-bottom: 1px dashed #cbd5e1; padding: 8px 0; font-size: 0.9rem; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="#"><i class="fas fa-cube me-2"></i>SYLO</a>
            <div class="collapse navbar-collapse justify-content-center" id="mainNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="#empresa">Empresa</a></li>
                    <li class="nav-item"><a class="nav-link" href="#bench">Rendimiento</a></li>
                    <li class="nav-item"><a class="nav-link" href="#calculadora">Calculadora</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing">Planes</a></li>
                </ul>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link nav-link p-0" onclick="toggleTheme()"><i class="fas fa-moon fa-lg"></i></button>
                <div class="d-none d-md-flex align-items-center cursor-pointer" onclick="new bootstrap.Modal('#statusModal').show()">
                    <div class="status-dot me-2"></div><span class="small fw-bold text-success">Status</span>
                </div>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="dashboard_cliente.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold">CONSOLA</a>
                    <button class="btn btn-link text-danger btn-sm" onclick="logout()"><i class="fas fa-sign-out-alt"></i></button>
                <?php else: ?>
                    <button class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" onclick="openM('authModal')">CLIENTE</button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <section class="hero text-center">
        <div class="container">
            <span class="badge bg-primary mb-3 px-3 py-1 rounded-pill">V115 Stable</span>
            <h1 class="display-3 fw-bold mb-4">Infraestructura <span class="text-primary">Ryzen™</span></h1>
            <p class="lead mb-5">Orquestación Kubernetes V21 desde Alicante, España.</p>
        </div>
    </section>

    <section id="empresa" class="py-5 bg-white"><div class="container"><div class="row align-items-center mb-5"><div class="col-lg-6"><h6 class="text-primary fw-bold">NUESTRA MISIÓN</h6><h2 class="fw-bold mb-4">Ingeniería Real</h2><p class="text-muted">Sylo nació en Alicante para eliminar la complejidad. Usamos hardware Threadripper y NVMe Gen5 real.</p><div class="mt-4"><a href="https://github.com/leonardsyloadm-cyber/SYLO-Kubernetes-for-all" target="_blank" class="btn btn-outline-dark rounded-pill px-4"><i class="fab fa-github me-2"></i>GitHub</a></div></div><div class="col-lg-6"><div class="row g-4"><div class="col-6 text-center"><div class="p-4 rounded bg-light border"><img src="https://ui-avatars.com/api/?name=Ivan+Arlanzon&background=0f172a&color=fff&size=100" class="avatar"><h5 class="fw-bold">Ivan A.</h5><span class="badge bg-primary">CEO</span></div></div><div class="col-6 text-center"><div class="p-4 rounded bg-light border"><img src="https://ui-avatars.com/api/?name=Leonard+Baicu&background=2563eb&color=fff&size=100" class="avatar"><h5 class="fw-bold">Leonard B.</h5><span class="badge bg-success">CTO</span></div></div></div></div></div></div></section>

    <section id="bench" class="py-5 bg-light"><div class="container"><div class="row align-items-center"><div class="col-lg-5"><h2 class="fw-bold mb-3">Rendimiento Bruto</h2><p>Cinebench R23 Single Core.</p><div class="d-flex justify-content-between small fw-bold mt-4"><span>SYLO Ryzen</span><span class="text-primary">1,950 pts</span></div><div class="bench-bar b-sylo" style="width:100%"></div><div class="d-flex justify-content-between small fw-bold mt-3"><span>AWS c6a</span><span>1,420 pts</span></div><div class="bench-bar b-aws" style="width:72%"></div></div><div class="col-lg-6 offset-lg-1"><div class="row g-3"><div class="col-6"><div class="p-4 border rounded-4 text-center bg-white"><i class="fas fa-hdd fa-2x text-danger mb-2"></i><h5 class="fw-bold">NVMe Gen5</h5><small>7,500 MB/s</small></div></div><div class="col-6"><div class="p-4 border rounded-4 text-center bg-white"><i class="fas fa-memory fa-2x text-success mb-2"></i><h5 class="fw-bold">DDR5 ECC</h5><small>Error Correction</small></div></div></div></div></div></div></section>

    <section id="calculadora" class="container py-5 my-5"><div class="calc-box shadow-lg"><div class="row g-5"><div class="col-lg-6"><h4 class="text-white mb-4"><i class="fas fa-calculator me-2 text-primary"></i>Configurador</h4><select class="form-select w-50 bg-dark text-white border-secondary mb-4" id="calc-preset" onchange="applyPreset()"><option value="custom">-- A Medida --</option><option value="bronce">Plan Bronce</option><option value="plata">Plan Plata</option><option value="oro">Plan Oro</option></select>
        <label class="small text-white-50 fw-bold">vCPU (5€): <span id="c-cpu">1</span></label><input type="range" class="form-range mb-4" min="1" max="16" value="1" id="in-cpu" oninput="userMovedSlider()">
        <label class="small text-white-50 fw-bold">RAM (5€): <span id="c-ram">1</span> GB</label><input type="range" class="form-range mb-4" min="1" max="32" value="1" id="in-ram" oninput="userMovedSlider()">
        <div class="row g-2"><div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check-calc-db" oninput="userMovedSlider()"><label class="text-white small">DB (+5€)</label></div></div><div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check-calc-web" oninput="userMovedSlider()"><label class="text-white small">Web (+5€)</label></div></div></div>
    </div><div class="col-lg-6"><div class="bg-white p-5 rounded-4 h-100 d-flex flex-column justify-content-center text-dark"><h5 class="fw-bold mb-3">Estimación</h5><div class="d-flex justify-content-between"><span class="fw-bold text-primary">SYLO</span><span class="price-display" id="out-sylo">0€</span></div><div class="progress mb-3" style="height:20px"><div id="pb-sylo" class="progress-bar bg-primary" style="width:0%"></div></div><div class="d-flex justify-content-between small text-muted"><span>AWS</span><span id="out-aws">0€</span></div><div class="progress mb-2" style="height:6px"><div id="pb-aws" class="progress-bar bg-secondary" style="width:100%"></div></div><div class="d-flex justify-content-between small text-muted"><span>Azure</span><span id="out-azure">0€</span></div><div class="progress mb-3" style="height:6px"><div id="pb-azure" class="progress-bar bg-secondary" style="width:100%"></div></div><div class="text-center mt-3"><span class="badge bg-success border border-success text-success bg-opacity-10 px-3 py-2">AHORRO: <span id="out-save">0%</span></span></div></div></div></div></div></section>

    <section id="pricing" class="py-5 bg-white"><div class="container"><div class="text-center mb-5"><h6 class="text-primary fw-bold">PLANES V21</h6><h2 class="fw-bold">Escala con Confianza</h2></div><div class="row g-4 justify-content-center">
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front"><div><h5 class="fw-bold text-muted">Bronce</h5><div class="display-4 fw-bold text-primary my-2">5€</div><ul class="list-unstyled small text-muted"><li>1 vCPU / 1 GB RAM</li><li>Alpine Only</li><li class="text-danger">Sin DB / Web</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Bronce')" class="btn btn-outline-dark w-100 rounded-pill btn-select">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-2">Specs</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU:</span> <strong>1 Core</strong></li><li><span>RAM:</span> <strong>1 GB</strong></li><li><span>OS:</span> <strong>Alpine (Fijo)</strong></li><li><span>SSH:</span> <strong>Sí</strong></li></ul><div class="mt-2"><p class="small fw-bold mb-0">Ideal para:</p><p class="small text-muted">Bastion Host, VPN, CLI.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Bronce')" class="btn btn-outline-warning w-100 rounded-pill btn-select">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front" style="border-color:#2563eb"><div><h5 class="fw-bold text-primary">Plata</h5><div class="display-4 fw-bold text-primary my-2">15€</div><ul class="list-unstyled small text-muted"><li>MySQL Cluster</li><li>2 vCPU / 2 GB RAM</li><li class="text-danger">Sin Web</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill btn-select">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-2">Backend</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU:</span> <strong>2 Cores</strong></li><li><span>RAM:</span> <strong>2 GB</strong></li><li><span>OS:</span> <strong>Alp/Ubu</strong></li><li><span>DB:</span> <strong>MySQL 8</strong></li></ul><div class="mt-2"><p class="small fw-bold mb-0">Ideal para:</p><p class="small text-muted">Microservicios, Bases de Datos.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill btn-select">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front" style="border-color:#f59e0b"><div><h5 class="fw-bold text-warning">Oro</h5><div class="display-4 fw-bold text-primary my-2">30€</div><ul class="list-unstyled small text-muted"><li>Full Stack</li><li>3 vCPU / 3 GB RAM</li><li>Dominio .cloud</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Oro')" class="btn btn-warning w-100 rounded-pill btn-select text-white">Elegir</button></div><div class="face-back"><div><h6 class="text-warning fw-bold mb-2">Producción</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU:</span> <strong>3 Cores</strong></li><li><span>RAM:</span> <strong>3 GB</strong></li><li><span>OS:</span> <strong>Alp/Ubu/RHEL</strong></li><li><span>Stack:</span> <strong>Nginx+MySQL</strong></li></ul><div class="mt-2"><p class="small fw-bold mb-0">Ideal para:</p><p class="small text-muted">Apps Producción, E-commerce.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Oro')" class="btn btn-warning w-100 rounded-pill btn-select text-white">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front card-custom"><div><h5 class="fw-bold text-primary">A Medida</h5><div class="display-4 fw-bold text-primary my-2">Flex</div><ul class="list-unstyled small text-muted"><li>Hardware Ryzen</li><li>Topología Mixta</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill btn-select">Configurar</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-2">Arquitecto</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU:</span> <strong>1-32 Cores</strong></li><li><span>RAM:</span> <strong>1-64 GB</strong></li><li><span>OS:</span> <strong>Todos</strong></li><li><span>Net:</span> <strong>Custom</strong></li></ul><div class="mt-2"><p class="small fw-bold mb-0">Ideal para:</p><p class="small text-muted">Big Data, IA, Proyectos complejos.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill btn-select">Configurar</button></div></div></div></div>
    </div></div></section>

    <section class="py-5 bg-white border-top"><div class="container text-center"><h3 class="fw-bold">Sylo Academy</h3><p class="text-muted mb-4">Documentación técnica oficial.</p><a href="https://www.notion.so/SYLO-Kubernetes-For-All-1f5bfdf3150380328e1efc4fe8e181f9?source=copy_link" target="_blank" class="btn btn-dark rounded-pill px-5 fw-bold"><i class="fas fa-book me-2"></i>Leer Docs</a></div></section>

    <footer class="py-5 bg-light border-top mt-5"><div class="container text-center"><h5 class="fw-bold text-primary mb-3">SYLO CORP S.L.</h5><div class="mb-4"><a href="mailto:arlanzonivan@gmail.com" class="text-muted mx-2 text-decoration-none">arlanzonivan@gmail.com</a><a href="mailto:leob@gmail.com" class="text-muted mx-2 text-decoration-none">leob@gmail.com</a></div><button class="btn btn-link text-muted small" onclick="openLegal()">TÉRMINOS Y CONDICIONES</button></div></footer>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="legalCanvas"><div class="offcanvas-header p-4 border-bottom"><h4 class="offcanvas-title fw-bold text-primary">Contrato de Servicios</h4><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-5 legal-content">
        <h5>1. Identidad y Objeto</h5><p>SYLO CORP S.L. es una Sociedad Limitada registrada en Alicante (CIF B-12345678). El presente contrato regula el uso de la plataforma Oktopus™ V21. Al contratar, acepta estas condiciones sin reservas.</p>
        <h5>2. Hardware Ryzen Garantizado</h5><p>Garantizamos el uso exclusivo de procesadores AMD Ryzen™ Threadripper™ de última generación. Los recursos contratados (vCPU) corresponden a hilos de ejecución físicos y no virtuales. No realizamos "overselling" agresivo.</p>
        <h5>3. Política de Uso Aceptable (AUP) - Kubernetes</h5><p>Queda terminantemente prohibido el uso para: Minería de criptomonedas, ataques DDoS, intrusiones en redes ajenas, y alojamiento de contenido ilegal. La detección resultará en la terminación inmediata.</p>
        <h5>4. Privacidad y Protección de Datos</h5><p>En cumplimiento con el RGPD (UE 2016/679): Sus datos se almacenan cifrados en reposo (AES-256) en Alicante. No realizamos inspección profunda de paquetes (DPI).</p>
        <h5>5. Acuerdo de Nivel de Servicio (SLA)</h5><p>Garantizamos un 99.9% de disponibilidad mensual. En caso de incumplimiento, se compensará con créditos de servicio.</p>
        <h5>6. Pagos y Suspensión</h5><p>El servicio es prepago. El impago resultará en la suspensión del servicio a las 48 horas y el borrado de datos a los 15 días.</p>
        <h5>7. Responsabilidad</h5><p>Sylo no será responsable de pérdidas de datos derivadas de una mala configuración por parte del usuario o vulnerabilidades en su software.</p>
        <h5>8. Jurisdicción</h5><p>Las partes se someten a los juzgados de Alicante, España.</p>
    </div></div>

    <div class="modal fade" id="configModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content shadow-lg border-0 p-3">
        <div class="modal-header border-0"><h5 class="fw-bold">Configurar <span id="m_plan" class="text-primary"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-5">
            <div class="row mb-3"><div class="col-6"><label class="form-label fw-bold small">Alias Cluster</label><input id="cfg-alias" class="form-control rounded-pill bg-light border-0"></div><div class="col-6"><label class="form-label fw-bold small">Usuario SSH</label><input id="cfg-ssh-user" class="form-control rounded-pill bg-light border-0" value="admin_sylo"></div></div>
            <div class="mb-3"><label class="form-label fw-bold small">SO</label><select id="cfg-os" class="form-select rounded-pill bg-light border-0"></select></div>

            <div id="grp-hardware" class="mb-3 p-3 bg-light rounded-3" style="display:none;">
                <h6 class="text-primary fw-bold mb-3"><i class="fas fa-microchip me-2"></i>Recursos Personalizados</h6>
                <div class="row">
                    <div class="col-6"><label class="small fw-bold">vCPU: <span id="lbl-cpu"></span></label><input type="range" id="mod-cpu" min="1" max="16" oninput="updateModalHard()"></div>
                    <div class="col-6"><label class="small fw-bold">RAM: <span id="lbl-ram"></span> GB</label><input type="range" id="mod-ram" min="1" max="32" oninput="updateModalHard()"></div>
                </div>
            </div>

            <div class="d-flex gap-3 mb-2">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="mod-check-db" onchange="toggleModalSoft()"><label class="small fw-bold ms-1">Base de Datos</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="mod-check-web" onchange="toggleModalSoft()"><label class="small fw-bold ms-1">Servidor Web</label></div>
            </div>
            
            <div id="mod-db-opts" style="display:none;" class="p-3 border rounded-3 mb-2">
                <label class="small fw-bold">Motor DB</label>
                <select id="mod-db-type" class="form-select rounded-pill bg-white border-0 mb-2"><option value="mysql">MySQL 8.0</option><option value="postgresql">PostgreSQL 14</option><option value="mongodb">MongoDB</option></select>
                <input id="mod-db-name" class="form-control rounded-pill bg-white border-0" value="sylo_db" placeholder="Nombre DB">
            </div>
            <div id="mod-web-opts" style="display:none;" class="p-3 border rounded-3">
                <label class="small fw-bold">Servidor Web</label>
                <select id="mod-web-type" class="form-select rounded-pill bg-white border-0 mb-2"><option value="nginx">Nginx</option><option value="apache">Apache</option></select>
                <input id="mod-web-name" class="form-control rounded-pill bg-white border-0 mb-2" value="sylo_web" placeholder="Nombre App">
                <div class="input-group rounded-pill overflow-hidden"><input id="mod-sub" class="form-control border-0" placeholder="mi-app"><span class="input-group-text border-0 bg-white small">.sylobi.org</span></div>
            </div>

            <div class="mt-4"><button class="btn btn-primary w-100 rounded-pill fw-bold py-2 shadow-sm" onclick="lanzar()">DESPLEGAR AHORA</button></div>
        </div>
    </div></div></div>

    <div class="modal fade" id="statusModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content p-4 border-0 shadow-lg"><h5 class="fw-bold mb-4">Estado del Sistema <span class="status-dot ms-2"></span></h5><div class="d-flex justify-content-between border-bottom py-2"><span>API Gateway (Alicante)</span><span class="badge bg-success">Online</span></div><div class="d-flex justify-content-between border-bottom py-2"><span>NVMe Array</span><span class="badge bg-success">Online</span></div><div class="d-flex justify-content-between border-bottom py-2"><span>Oktopus V21</span><span class="badge bg-success">Active</span></div></div></div></div>
    <div class="modal fade" id="progressModal" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content terminal-window border-0"><div class="terminal-body text-center"><div class="spinner-border text-success mb-3" role="status"></div><h5 id="progress-text" class="mb-3">Iniciando...</h5><div class="progress"><div id="prog-bar" class="progress-bar bg-success" style="width:0%"></div></div></div></div></div></div>
    <div class="modal fade" id="successModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 p-4" style="background:white; border-radius:15px;"><h2 class="text-success fw-bold mb-3">✅ ÉXITO</h2><div class="success-box"><span class="text-muted"># RESUMEN</span><br>Plan: <span id="s-plan" class="text-warning"></span><br>OS: <span id="s-os" class="text-info"></span><br>CPU: <span id="s-cpu"></span> vCore | RAM: <span id="s-ram"></span> GB<div class="mt-3 border-top border-secondary pt-2">CMD: <span id="s-cmd" class="text-white"></span><br>PASS: <span id="s-pass" class="text-white"></span></div></div><div class="text-center"><a href="dashboard_cliente.php" class="btn btn-primary rounded-pill px-5 fw-bold">IR A LA CONSOLA</a></div></div></div></div>
    <div class="modal fade" id="authModal"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content border-0 shadow-lg p-4"><ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-pill"><li class="nav-item"><a class="nav-link active rounded-pill" data-bs-toggle="tab" href="#login-pane">Login</a></li><li class="nav-item"><a class="nav-link rounded-pill" data-bs-toggle="tab" href="#reg-pane">Registro</a></li></ul><div class="tab-content"><div class="tab-pane fade show active" id="login-pane"><input id="log_email" class="form-control mb-3" placeholder="Usuario/Email"><input type="password" id="log_pass" class="form-control mb-3" placeholder="Contraseña"><button class="btn btn-primary w-100 rounded-pill fw-bold" onclick="handleLogin()">Entrar</button></div><div class="tab-pane fade" id="reg-pane"><div class="text-center mb-3"><div class="btn-group w-50"><input type="radio" class="btn-check" name="t_u" id="t_a" value="autonomo" checked onchange="toggleReg()"><label class="btn btn-outline-primary" for="t_a">Autónomo</label><input type="radio" class="btn-check" name="t_u" id="t_e" value="empresa" onchange="toggleReg()"><label class="btn btn-outline-primary" for="t_e">Empresa</label></div></div><div class="row g-2"><div class="col-6"><input id="reg_u" class="form-control mb-2" placeholder="Usuario"></div><div class="col-6"><input id="reg_e" class="form-control mb-2" placeholder="Email"></div><div class="col-6"><input type="password" id="reg_p1" class="form-control mb-2" placeholder="Contraseña"></div><div class="col-6"><input type="password" id="reg_p2" class="form-control mb-2" placeholder="Repetir"></div></div><div id="fields-auto" class="mt-2"><input id="reg_fn" class="form-control mb-2" placeholder="Nombre Completo"><input id="reg_dni_a" class="form-control mb-2" placeholder="DNI"></div><div id="fields-emp" class="mt-2" style="display:none"><div class="row g-2"><div class="col-6"><input id="reg_contact" class="form-control mb-2" placeholder="Persona Contacto"></div><div class="col-6"><input id="reg_cif" class="form-control mb-2" placeholder="CIF"></div></div><select id="reg_tipo_emp" class="form-select mb-2" onchange="checkOther()"><option value="SL">S.L.</option><option value="SA">S.A.</option><option value="Cooperativa">Cooperativa</option><option value="Otro">Otro</option></select><input id="reg_rs" class="form-control mb-2" placeholder="Razón Social" style="display:none"></div><div class="row g-2 mt-1"><div class="col-6"><input id="reg_tel" class="form-control mb-2" placeholder="Teléfono"></div><div class="col-6"><input id="reg_cal" class="form-control mb-2" placeholder="Dirección"></div></div><div class="form-check mt-3"><input type="checkbox" id="reg_terms" class="form-check-input"><label class="form-check-label small">Acepto los <a href="#" onclick="viewTermsFromReg()">Términos</a>.</label></div><button class="btn btn-success w-100 rounded-pill fw-bold mt-3" onclick="handleRegister()">Crear Cuenta</button></div></div></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLogged = <?=isset($_SESSION['user_id'])?'true':'false'?>;
        function openM(id){const el=document.getElementById(id);if(el)new bootstrap.Modal(el).show();}
        function hideM(id){const el=document.getElementById(id);const m=bootstrap.Modal.getInstance(el);if(m)m.hide();}
        function openAuth(){openM('authModal');}
        function openLegal(){new bootstrap.Offcanvas(document.getElementById('legalCanvas')).show();}
        function viewTermsFromReg(){hideM('authModal');openLegal();}
        function toggleTheme(){document.body.dataset.theme=document.body.dataset.theme==='dark'?'':'dark';}
        function toggleCard(el){document.querySelectorAll('.card-stack-container').forEach(c=>c!==el&&c.classList.remove('active'));el.classList.toggle('active');}
        function toggleReg(){const e=document.getElementById('t_e').checked;document.getElementById('fields-emp').style.display=e?'block':'none';document.getElementById('fields-auto').style.display=e?'none':'block';}
        function checkOther(){document.getElementById('reg_rs').style.display=(document.getElementById('reg_tipo_emp').value==='Otro')?'block':'none';}

        // --- CALCULADORA (V99 LOGIC WITH INLINE EVENTS) ---
        // Se llama al cargar para inicializar valores
        document.addEventListener('DOMContentLoaded', updCalc);

        function userMovedSlider() { 
            document.getElementById('calc-preset').value='custom'; 
            updCalc(); 
        }
        
        function applyPreset() {
            const p=document.getElementById('calc-preset').value, c=document.getElementById('in-cpu'), r=document.getElementById('in-ram'), d=document.getElementById('check-calc-db'), w=document.getElementById('check-calc-web');
            if(p==='bronce'){c.value=1;r.value=1;d.checked=false;w.checked=false;} 
            else if(p==='plata'){c.value=2;r.value=2;d.checked=true;w.checked=false;} 
            else if(p==='oro'){c.value=3;r.value=3;d.checked=true;w.checked=true;}
            updCalc();
        }

        function updCalc() {
            let c=parseInt(document.getElementById('in-cpu').value), r=parseInt(document.getElementById('in-ram').value);
            document.getElementById('c-cpu').innerText=c; document.getElementById('c-ram').innerText=r;
            
            if(document.getElementById('calc-preset').value === 'bronce'){renderP(5);return;} 
            if(document.getElementById('calc-preset').value === 'plata'){renderP(15);return;} 
            if(document.getElementById('calc-preset').value === 'oro'){renderP(30);return;}
            
            let d_c=document.getElementById('check-calc-db').checked?5:0;
            let w_c=document.getElementById('check-calc-web').checked?5:0;
            
            renderP((c*5)+(r*5)+d_c+w_c+5);
        }

        function renderP(val){ 
            document.getElementById('out-sylo').innerText=val+"€"; 
            let aws=Math.round((val*3.5)+40), az=Math.round((val*3.2)+30), sv=Math.round(((aws-val)/aws)*100); if(sv>99)sv=99;
            document.getElementById('out-aws').innerText=aws+"€"; document.getElementById('out-azure').innerText=az+"€"; document.getElementById('out-save').innerText=sv+"%"; document.getElementById('pb-sylo').style.width=sv+"%"; document.getElementById('pb-aws').style.width="100%"; document.getElementById('pb-azure').style.width=(az/aws*100)+"%";
        }

        // --- DEPLOY LOGIC ---
        let curPlan='';
        function prepararPedido(plan) {
            if(!isLogged) { openAuth(); return; }
            curPlan = plan;
            document.getElementById('m_plan').innerText = plan;
            
            const selOS = document.getElementById('cfg-os'), mCpu = document.getElementById('mod-cpu'), mRam = document.getElementById('mod-ram'), dbT = document.getElementById('mod-db-type'), webT = document.getElementById('mod-web-type'), cDb = document.getElementById('mod-check-db'), cWeb = document.getElementById('mod-check-web');
            const grpHard = document.getElementById('grp-hardware');

            selOS.innerHTML = "";
            if(plan==='Bronce'){ 
                selOS.innerHTML="<option value='alpine'>Alpine</option>"; selOS.disabled=true; 
                grpHard.style.display="none"; // OCULTAR SLIDERS EN FIJOS
                mCpu.value=1; mRam.value=1; 
                cDb.checked=false; cWeb.checked=false; cDb.disabled=true; cWeb.disabled=true; 
            }
            else if(plan==='Plata'){ 
                selOS.innerHTML="<option value='ubuntu'>Ubuntu</option><option value='alpine'>Alpine</option>"; selOS.disabled=false; 
                grpHard.style.display="none";
                mCpu.value=2; mRam.value=2; 
                cDb.checked=true; cWeb.checked=false; cDb.disabled=true; cWeb.disabled=true; dbT.disabled=true; 
            }
            else if(plan==='Oro'){ 
                selOS.innerHTML="<option value='ubuntu'>Ubuntu</option><option value='alpine'>Alpine</option><option value='redhat'>RedHat</option>"; selOS.disabled=false; 
                grpHard.style.display="none";
                mCpu.value=3; mRam.value=3; 
                cDb.checked=true; cWeb.checked=true; cDb.disabled=true; cWeb.disabled=true; dbT.disabled=true; webT.disabled=true; 
            }
            else { // Custom
                selOS.innerHTML="<option value='ubuntu'>Ubuntu</option><option value='alpine'>Alpine</option><option value='redhat'>RedHat</option>"; selOS.disabled=false; 
                grpHard.style.display="block"; // MOSTRAR SLIDERS SOLO EN CUSTOM
                mCpu.value = document.getElementById('in-cpu').value; mRam.value = document.getElementById('in-ram').value; 
                cDb.disabled=false; cWeb.disabled=false;
                cDb.checked = document.getElementById('check-calc-db').checked;
                cWeb.checked = document.getElementById('check-calc-web').checked;
                dbT.disabled=false; webT.disabled=false;
            }
            updateModalHard(); toggleModalSoft();
            openM('configModal');
        }

        function updateModalHard(){ document.getElementById('lbl-cpu').innerText=document.getElementById('mod-cpu').value; document.getElementById('lbl-ram').innerText=document.getElementById('mod-ram').value; }
        function toggleModalSoft(){ document.getElementById('mod-db-opts').style.display=document.getElementById('mod-check-db').checked?'block':'none'; document.getElementById('mod-web-opts').style.display=document.getElementById('mod-check-web').checked?'block':'none'; }

        async function lanzar() {
            const alias = document.getElementById('cfg-alias').value; if(!alias) { alert("Alias obligatorio"); return; }
            const specs = {
                cluster_alias: alias,
                ssh_user: document.getElementById('cfg-ssh-user').value,
                os_image: document.getElementById('cfg-os').value,
                cpu: parseInt(document.getElementById('mod-cpu').value),
                ram: parseInt(document.getElementById('mod-ram').value),
                storage: 25,
                db_enabled: document.getElementById('mod-check-db').checked,
                web_enabled: document.getElementById('mod-check-web').checked,
                db_custom_name: document.getElementById('mod-db-name').value,
                web_custom_name: document.getElementById('mod-web-name').value,
                subdomain: document.getElementById('mod-sub').value || 'interno',
                db_type: document.getElementById('mod-db-type').value,
                web_type: document.getElementById('mod-web-type').value
            };
            hideM('configModal'); openM('progressModal');
            try {
                const res = await fetch('index.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'comprar', plan:curPlan, specs:specs}) });
                const j = await res.json();
                if(j.status === 'success') startPolling(j.order_id, specs); else { hideM('progressModal'); alert(j.mensaje); }
            } catch(e) { hideM('progressModal'); alert("Error"); }
        }

        function startPolling(oid, finalSpecs) {
            let i = setInterval(async () => {
                const r = await fetch(`index.php?check_status=${oid}`);
                const s = await r.json();
                document.getElementById('prog-bar').style.width = s.percent+"%";
                document.getElementById('progress-text').innerText = s.message;
                if(s.status === 'completed') {
                    clearInterval(i); hideM('progressModal');
                    document.getElementById('s-plan').innerText = curPlan;
                    document.getElementById('s-os').innerText = finalSpecs.os_image;
                    document.getElementById('s-cpu').innerText = finalSpecs.cpu;
                    document.getElementById('s-ram').innerText = finalSpecs.ram;
                    document.getElementById('s-cmd').innerText = s.ssh_cmd;
                    document.getElementById('s-pass').innerText = s.ssh_pass;
                    openM('successModal');
                }
            }, 1500);
        }

        async function handleLogin() { const r=await fetch('index.php',{method:'POST',body:JSON.stringify({action:'login',email_user:document.getElementById('log_email').value,password:document.getElementById('log_pass').value})}); const d=await r.json(); if(d.status==='success') location.reload(); else alert(d.mensaje); }
        async function handleRegister() { if(!document.getElementById('reg_terms').checked) return; const t = document.getElementById('t_a').checked ? 'autonomo' : 'empresa'; const d = { action:'register', username:document.getElementById('reg_u').value, email:document.getElementById('reg_e').value, password:document.getElementById('reg_p1').value, password_confirm:document.getElementById('reg_p2').value, telefono:document.getElementById('reg_tel').value, calle:document.getElementById('reg_cal').value, tipo_usuario:t }; if(t==='autonomo') { d.full_name=document.getElementById('reg_fn').value; d.dni=document.getElementById('reg_dni_a').value; } else { d.contact_name=document.getElementById('reg_contact').value; d.cif=document.getElementById('reg_cif').value; d.dni=d.cif; d.tipo_empresa=document.getElementById('reg_tipo_emp').value; if(d.tipo_empresa==='Otro') d.company_name=document.getElementById('reg_rs').value; } await fetch('index.php',{method:'POST',body:JSON.stringify(d)}); location.reload(); }
        function logout() { fetch('index.php',{method:'POST',body:JSON.stringify({action:'logout'})}).then(()=>location.reload()); }
        function copyData(){ navigator.clipboard.writeText(document.getElementById('ssh-details').innerText); }
    </script>
</body>
</html>