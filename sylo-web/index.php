<?php
session_start();

// --- 1. CONFIGURACIÓN BASE DE DATOS ---
$servername = "kylo-main-db";
$username_db = "sylo_app";
$password_db = "sylo_app_pass";
$dbname = "kylo_main_db";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // En producción, loguear error. Aquí silenciamos para no romper el HTML.
}

// --- 2. API: CONSULTAR ESTADO (POLLING) ---
if (isset($_GET['check_status'])) {
    $id = htmlspecialchars($_GET['check_status']);
    $archivo = "/buzon/status_" . $id . ".json";
    header('Content-Type: application/json');
    
    if (file_exists($archivo)) {
        echo file_get_contents($archivo);
    } else {
        echo json_encode(["percent" => 0, "message" => "Esperando al orquestador...", "status" => "waiting"]);
    }
    exit;
}

// --- 3. API: PROCESAR ACCIONES (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    header('Content-Type: application/json');

    // A. REGISTRO
    if ($action === 'register') {
        $user = htmlspecialchars($input['username']);
        $pass = $input['password'];
        $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
        $name = htmlspecialchars($input['full_name']);
        $company = htmlspecialchars($input['company']);

        // Validación Contraseña
        if (!preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{6,}$/', $pass)) {
            echo json_encode(["status" => "error", "mensaje" => "Contraseña débil: Mín 6 chars, 1 Mayúscula, 1 Símbolo."]); exit;
        }
        // Validación Email
        if (!$email) { echo json_encode(["status" => "error", "mensaje" => "Email inválido."]); exit; }
        
        // Validación DNS
        $domain = substr(strrchr($email, "@"), 1);
        if (!checkdnsrr($domain, "MX")) { echo json_encode(["status" => "error", "mensaje" => "Dominio de correo inexistente."]); exit; }

        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, company_name) VALUES (:user, :name, :email, :hash, :comp)");
            $stmt->execute(['user'=>$user, 'name'=>$name, 'email'=>$email, 'hash'=>$hash, 'comp'=>$company]);
            echo json_encode(["status" => "success", "mensaje" => "Cuenta creada. Inicia sesión."]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "mensaje" => "Usuario o Email ya existen."]);
        }
        exit;
    }

    // B. LOGIN
    if ($action === 'login') {
        $email_user = $input['email_user'];
        $pass = $input['password'];
        
        try {
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = :eu OR username = :eu");
            $stmt->execute(['eu' => $email_user]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($pass, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['company'] = $user['company_name'];
                $_SESSION['email'] = $user['email'];
                echo json_encode(["status" => "success", "mensaje" => "Bienvenido, " . $user['full_name']]);
            } else {
                echo json_encode(["status" => "error", "mensaje" => "Credenciales incorrectas."]);
            }
        } catch(Exception $e) { echo json_encode(["status" => "error", "mensaje" => "Error sistema."]); }
        exit;
    }

    // C. LOGOUT
    if ($action === 'logout') { session_destroy(); echo json_encode(["status" => "success"]); exit; }

    // D. COMPRA (DESPLIEGUE)
    if ($action === 'comprar') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(["status" => "auth_required", "mensaje" => "Inicia sesión."]); exit; }

        $plan_name = htmlspecialchars($input['plan']);
        $user_id = $_SESSION['user_id'];
        $cliente_email = $_SESSION['email'];

        try {
            // 1. Buscar ID Plan
            $stmt = $conn->prepare("SELECT id FROM plans WHERE name = :name");
            $stmt->execute(['name' => $plan_name]);
            $plan_row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Fallback si la DB está vacía o no encuentra el plan
            $plan_id = $plan_row ? $plan_row['id'] : 0; 

            // 2. Crear Orden
            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (:uid, :pid, 'pending')");
            $stmt->execute(['uid' => $user_id, 'pid' => $plan_id]);
            $order_id = $conn->lastInsertId();

            // 3. Escribir en Buzón para el Orquestador
            $contenido = json_encode([
                "id" => $order_id,
                "plan" => $plan_name,
                "cliente" => $cliente_email,
                "fecha" => date("c")
            ]);
            
            if (file_put_contents("/buzon/orden_" . $order_id . ".json", $contenido)) {
                echo json_encode(["status" => "success", "order_id" => $order_id, "mensaje" => "Orden creada."]);
            } else {
                throw new Exception("No se pudo escribir en el buzón.");
            }

        } catch (Exception $e) { echo json_encode(["status" => "error", "mensaje" => "Error: " . $e->getMessage()]); }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYLO | Kubernetes for All</title>
    <!-- Bootstrap 5 & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --sylo-primary: #0d6efd; --sylo-dark: #212529; }
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8f9fa; scroll-behavior: smooth; }
        
        /* Estilos Navbar */
        .navbar-brand { font-weight: 800; letter-spacing: 1px; font-size: 1.5rem; }
        .nav-link { font-weight: 500; transition: color 0.3s; }
        .nav-link:hover { color: var(--sylo-primary) !important; }
        
        /* Hero */
        .hero {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white; padding: 100px 0; margin-bottom: 60px;
            clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
        }
        
        /* Cards */
        .card-price { transition: transform 0.3s; border: none; border-radius: 15px; overflow: hidden; }
        .card-price:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
        .price-tag { font-size: 3rem; font-weight: 800; color: var(--sylo-dark); }
        .feature-icon { width: 25px; text-align: center; margin-right: 10px; }
        .infra-icon { font-size: 3.5rem; color: var(--sylo-primary); margin-bottom: 15px; opacity: 0.9; }
        
        /* Barra Progreso */
        .progress { height: 25px; border-radius: 15px; background-color: #e9ecef; overflow: hidden; }
        .progress-bar { transition: width 0.4s ease; font-weight: bold; }
        .progress-bar.bg-error { background-color: #6c757d !important; }

        /* Terminal Verde */
        .success-terminal {
            background-color: #1e1e1e; color: #0f0; font-family: 'Courier New', monospace;
            padding: 20px; border-radius: 8px; border-left: 5px solid #0f0; text-align: left;
            position: relative; word-wrap: break-word;
        }
        .copy-btn { position: absolute; top: 10px; right: 10px; cursor: pointer; color: #aaa; }
        .copy-btn:hover { color: white; }
        
        /* Errores Form */
        .is-invalid-custom { border-color: #dc3545; }
        #pass-error { display: none; }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="fas fa-cubes text-primary me-2"></i>SYLO
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="#nosotros">Acerca de Nosotros</a></li>
                    <li class="nav-item"><a class="nav-link" href="#servicios">Planes y Servicios</a></li>
                    
                    <!-- GITHUB -->
                    <li class="nav-item ms-3">
                        <a class="nav-link text-dark" href="https://github.com/leonardsyloadm-cyber/SYLO-Kubernetes-for-all" target="_blank" title="Ver Repositorio">
                            <i class="fab fa-github fa-2x"></i>
                        </a>
                    </li>

                    <!-- USUARIO / LOGIN -->
                    <li class="nav-item ms-3 border-start ps-3">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <div class="dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-user-check me-2"></i> <?php echo $_SESSION['username']; ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                    <li><h6 class="dropdown-header"><?php echo $_SESSION['company']; ?></h6></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="#" onclick="logout()">Cerrar Sesión</a></li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <button class="user-icon-btn btn btn-link text-dark p-0" onclick="abrirLoginSinError()">
                                <i class="fas fa-user-circle fa-2x"></i>
                            </button>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ACERCA DE NOSOTROS -->
    <section id="nosotros" class="hero text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-4">Automatización Inteligente</h1>
            <p class="lead mb-5 w-75 mx-auto opacity-75">
                En <strong>SYLO</strong>, eliminamos las barreras de entrada a la nube. 
                Ofrecemos una plataforma de orquestación basada en <strong>Kubernetes</strong> para desplegar infraestructuras complejas en segundos.
            </p>
            <div class="row mt-5 justify-content-center">
                <div class="col-md-3">
                    <i class="fas fa-microchip infra-icon"></i>
                    <h5>Bare Metal</h5>
                    <p class="small text-white-50">Potencia pura sin virtualización innecesaria.</p>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-sitemap infra-icon"></i>
                    <h5>Redes HA</h5>
                    <p class="small text-white-50">Enrutamiento inteligente y balanceo de carga.</p>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-shield-alt infra-icon"></i>
                    <h5>Persistencia</h5>
                    <p class="small text-white-50">Datos replicados y seguros en tiempo real.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- PLANES Y SERVICIOS -->
    <section id="servicios" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-6">Nuestros Planes</h2>
                <p class="text-muted">Escalabilidad adaptada a tus necesidades</p>
            </div>
            
            <div class="row justify-content-center">
                
                <!-- BRONCE -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 p-3">
                        <div class="card-header-custom text-center text-warning fw-bold fs-4 pt-3">BRONCE</div>
                        <div class="card-body text-center">
                            <div class="price-tag">5€<span class="fs-6 text-muted">/mes</span></div>
                            <p class="text-muted small mb-4">Para pruebas y desarrollo.</p>
                            <ul class="list-unstyled text-start ps-4 mb-4 small">
                                <li><i class="fas fa-cube feature-icon text-warning"></i> Kubernetes Simple (1 Nodo)</li>
                                <li><i class="fas fa-memory feature-icon text-secondary"></i> 2 vCPU / 2 GB RAM</li>
                                <li><i class="fas fa-terminal feature-icon text-dark"></i> Acceso SSH Root</li>
                                <li><i class="fas fa-hdd feature-icon text-muted"></i> Sin Persistencia</li>
                            </ul>
                            <button onclick="intentarCompra('Bronce')" class="btn btn-outline-warning w-100 rounded-pill fw-bold">Desplegar Bronce</button>
                        </div>
                    </div>
                </div>

                <!-- PLATA -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 border-primary border-2 shadow p-3">
                        <div class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-primary">POPULAR</div>
                        <div class="card-header-custom text-center text-primary fw-bold fs-4 pt-3">PLATA</div>
                        <div class="card-body text-center">
                            <div class="price-tag text-primary">15€<span class="fs-6 text-muted">/mes</span></div>
                            <p class="text-muted small mb-4">Para bases de datos críticas.</p>
                            <ul class="list-unstyled text-start ps-4 mb-4 small">
                                <li><i class="fas fa-database feature-icon text-primary"></i> <strong>K8s + MySQL Cluster</strong></li>
                                <li><i class="fas fa-sync feature-icon text-success"></i> Replicación Maestro-Esclavo</li>
                                <li><i class="fas fa-microchip feature-icon text-danger"></i> 4 vCPU / 4 GB RAM</li>
                                <li><i class="fas fa-shield-alt feature-icon text-danger"></i> Alta Disponibilidad (HA)</li>
                            </ul>
                            <button onclick="intentarCompra('Plata')" class="btn btn-primary w-100 rounded-pill fw-bold shadow">Desplegar Plata</button>
                        </div>
                    </div>
                </div>

                <!-- ORO -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 p-3">
                        <div class="card-header-custom text-center fw-bold fs-4 pt-3" style="color: #d4af37;">ORO</div>
                        <div class="card-body text-center">
                            <div class="price-tag">30€<span class="fs-6 text-muted">/mes</span></div>
                            <p class="text-muted small mb-4">La solución completa.</p>
                            <ul class="list-unstyled text-start ps-4 mb-4 small">
                                <li><i class="fas fa-rocket feature-icon text-danger"></i> <strong>Infraestructura Total</strong></li>
                                <li><i class="fas fa-network-wired feature-icon text-primary"></i> Web HA + DB Replicada</li>
                                <li><i class="fas fa-server feature-icon text-dark"></i> 6 vCPU / 8 GB RAM</li>
                                <li><i class="fas fa-headset feature-icon text-info"></i> Soporte Prioritario 24/7</li>
                            </ul>
                            <button onclick="intentarCompra('Oro')" class="btn btn-outline-dark w-100 rounded-pill fw-bold">Desplegar Oro</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- MODAL PROGRESO -->
    <div class="modal fade" id="progressModal" data-bs-backdrop="static" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header bg-light border-0"><h5 class="modal-title fw-bold">🚀 Construyendo Infraestructura...</h5></div><div class="modal-body p-4 text-center"><h4 id="progress-text" class="mb-3 text-primary fw-bold">Iniciando...</h4><div class="progress mb-3"><div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width: 0%">0%</div></div><p class="text-muted small" id="progress-detail">Contactando orquestador...</p></div></div></div></div>

    <!-- MODAL ÉXITO -->
    <div class="modal fade" id="successModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header bg-success text-white"><h5 class="modal-title fw-bold">✅ ¡Despliegue Finalizado!</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><p class="lead">Tu servidor está activo. Guarda estas credenciales:</p><div class="success-terminal position-relative"><i class="fas fa-copy copy-btn fa-lg" title="Copiar" onclick="copiarDatos()"></i><div id="ssh-details"><p class="mb-1 text-muted"># Acceso Principal:</p><p class="fw-bold mb-3 text-white" id="ssh-cmd">Cargando...</p><p class="mb-1 text-muted"># Detalles / Contraseña:</p><p class="fw-bold mb-0 text-white" id="ssh-pass" style="white-space: pre-wrap;">Cargando...</p></div></div><div class="alert alert-warning mt-3"><strong>IMPORTANTE:</strong> Copia la contraseña ahora.</div></div></div></div></div>

    <!-- MODAL AUTH -->
    <div class="modal fade" id="authModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-primary">Acceso SYLO</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4">
        <ul class="nav nav-tabs nav-fill mb-4"><li class="nav-item"><button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-pane">Login</button></li><li class="nav-item"><button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-pane">Registro</button></li></ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="login-pane"><form onsubmit="handleLogin(event)"><div class="mb-3"><input type="text" id="login_email" class="form-control" placeholder="Usuario o Email" required></div><div class="mb-3 password-container position-relative"><input type="password" id="login_pass" class="form-control" placeholder="Contraseña" required><i class="fas fa-eye toggle-password position-absolute top-50 end-0 translate-middle-y me-3 cursor-pointer" onclick="togglePass('login_pass', this)"></i></div><div class="d-grid"><button type="submit" class="btn btn-primary btn-lg rounded-pill">Entrar</button></div></form></div>
            <div class="tab-pane fade" id="register-pane"><form onsubmit="handleRegister(event)"><div class="row mb-2"><div class="col"><input type="text" id="reg_name" class="form-control" placeholder="Nombre" required></div><div class="col"><input type="text" id="reg_user" class="form-control" placeholder="Usuario" required></div></div><div class="mb-2"><input type="text" id="reg_company" class="form-control" placeholder="Empresa" required></div><div class="mb-2"><input type="email" id="reg_email" class="form-control" placeholder="Email" required></div><div class="mb-2 position-relative"><input type="password" id="reg_pass" class="form-control" placeholder="Contraseña" required onkeyup="checkPass()"><i class="fas fa-eye toggle-password position-absolute top-50 end-0 translate-middle-y me-3" onclick="togglePass('reg_pass', this)"></i></div><div class="mb-2 position-relative"><input type="password" id="reg_pass2" class="form-control" placeholder="Repetir Contraseña" required onkeyup="checkPass()"></div><div id="pass-error" class="text-danger small fw-bold mb-2" style="display:none;">❌ No coinciden</div><div class="d-grid"><button type="submit" id="btn-reg" class="btn btn-success btn-lg rounded-pill">Crear Cuenta</button></div></form></div>
        </div><div id="authMessage" class="mt-3 text-center fw-bold small"></div></div></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLogged = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        const authModal = new bootstrap.Modal(document.getElementById('authModal'));
        const progressModal = new bootstrap.Modal(document.getElementById('progressModal'));
        const successModal = new bootstrap.Modal(document.getElementById('successModal'));
        let intervalId, currPct = 0, targetPct = 0;

        function abrirLoginSinError() { document.getElementById('authMessage').innerHTML = ""; authModal.show(); }
        function togglePass(id, icon) { const el = document.getElementById(id); if(el.type==="password"){el.type="text";icon.classList.replace("fa-eye","fa-eye-slash")}else{el.type="password";icon.classList.replace("fa-eye-slash","fa-eye")} }
        function checkPass() { const p1=document.getElementById('reg_pass').value, p2=document.getElementById('reg_pass2').value, err=document.getElementById('pass-error'), btn=document.getElementById('btn-reg'); if(p1&&p2&&p1!==p2){err.style.display='block';btn.disabled=true;document.getElementById('reg_pass2').classList.add('is-invalid-custom');}else{err.style.display='none';btn.disabled=false;document.getElementById('reg_pass2').classList.remove('is-invalid-custom');} }

        async function intentarCompra(plan) {
            if (!isLogged) { document.getElementById('authMessage').innerHTML = "<span class='text-danger bg-light px-2 py-1 rounded'>⚠️ Inicia sesión para desplegar.</span>"; authModal.show(); return; }
            if(confirm(`¿Desplegar plan ${plan}?`)) {
                try {
                    const res = await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'comprar', plan: plan }) });
                    const data = await res.json();
                    if (data.status === 'success') { progressModal.show(); startPolling(data.order_id); } else { alert("❌ " + data.mensaje); }
                } catch(e) { alert("Error de red."); }
            }
        }

        function startPolling(orderId) {
            currPct = 0; targetPct = 0;
            const bar = document.getElementById('progress-bar'), txt = document.getElementById('progress-text'), det = document.getElementById('progress-detail');
            function animate() { if(currPct < targetPct) { currPct += 0.5; if(currPct > targetPct) currPct = targetPct; } bar.style.width = currPct + "%"; bar.innerText = Math.floor(currPct) + "%"; if(currPct < 100) requestAnimationFrame(animate); } animate();
            
            intervalId = setInterval(async () => {
                try {
                    const res = await fetch(`index.php?check_status=${orderId}`);
                    const st = await res.json();
                    targetPct = st.percent; txt.innerText = st.message; det.innerText = "Fase: " + st.status;
                    
                    if (st.status === 'error') { clearInterval(intervalId); bar.classList.add('bg-error'); bar.style.width = "100%"; txt.innerText = "❌ Error Crítico"; alert("Fallo en el despliegue. Revisa el Orquestador."); }
                    
                    if (st.percent >= 100 && st.status === 'completed') {
                        clearInterval(intervalId);
                        setTimeout(() => {
                            progressModal.hide();
                            document.getElementById('ssh-cmd').innerText = st.ssh_cmd;
                            document.getElementById('ssh-pass').innerText = st.ssh_pass;
                            successModal.show();
                        }, 1000);
                    }
                } catch(e) {}
            }, 1000);
        }

        async function handleLogin(e) { e.preventDefault(); const res = await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'login', email_user: document.getElementById('login_email').value, password: document.getElementById('login_pass').value }) }); const data = await res.json(); if(data.status === 'success') location.reload(); else document.getElementById('authMessage').innerHTML = `<span class='text-danger'>${data.mensaje}</span>`; }
        async function handleRegister(e) { e.preventDefault(); if(document.getElementById('reg_pass').value !== document.getElementById('reg_pass2').value) { checkPass(); return; } const payload = { action: 'register', full_name: document.getElementById('reg_name').value, username: document.getElementById('reg_user').value, company: document.getElementById('reg_company').value, email: document.getElementById('reg_email').value, password: document.getElementById('reg_pass').value }; const res = await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) }); const data = await res.json(); if(data.status === 'success') { document.getElementById('authMessage').innerHTML = `<span class='text-success'>${data.mensaje}</span>`; setTimeout(() => document.getElementById('login-tab').click(), 1500); } else { document.getElementById('authMessage').innerHTML = `<span class='text-danger'>${data.mensaje}</span>`; } }
        async function logout() { await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'logout' }) }); location.reload(); }
        function copiarDatos() { navigator.clipboard.writeText(document.getElementById('ssh-cmd').innerText + "\n\n" + document.getElementById('ssh-pass').innerText); alert("¡Credenciales Copiadas!"); }
    </script>
</body>
</html>