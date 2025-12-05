<?php
session_start(); // INICIAR SESIÓN PHP

// --- CONFIGURACIÓN BASE DE DATOS ---
$servername = "kylo-main-db";
$username_db = "sylo_app";
$password_db = "sylo_app_pass";
$dbname = "kylo_main_db";

// Conexión Global
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username_db, $password_db);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(["status" => "error", "mensaje" => "Error fatal de conexión a Base de Datos."]));
}

// --- API ROUTER ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    header('Content-Type: application/json');

    // 1. REGISTRO
    if ($action === 'register') {
        $user = htmlspecialchars($input['username']);
        $pass = $input['password'];
        $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
        $name = htmlspecialchars($input['full_name']);
        $company = htmlspecialchars($input['company']);

        // VALIDACIÓN DE CONTRASEÑA
        if (!preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{6,}$/', $pass)) {
            echo json_encode(["status" => "error", "mensaje" => "La contraseña debe tener mín. 6 caracteres, una Mayúscula y un símbolo especial (!@#$...)."]);
            exit;
        }

        // VALIDACIÓN DE EMAIL (FORMATO + DOMINIO REAL)
        if (!$email) {
            echo json_encode(["status" => "error", "mensaje" => "El formato del email es inválido."]);
            exit;
        }

        // Separamos el dominio (ej: gmail.com)
        $domain = substr(strrchr($email, "@"), 1);
        
        // Preguntamos al DNS si el dominio tiene servidores de correo (MX)
        if (!checkdnsrr($domain, "MX")) {
            echo json_encode(["status" => "error", "mensaje" => "El dominio del correo no existe o no acepta emails."]);
            exit;
        }

        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, company_name) VALUES (:user, :name, :email, :hash, :comp)");
            $stmt->execute(['user'=>$user, 'name'=>$name, 'email'=>$email, 'hash'=>$hash, 'comp'=>$company]);
            echo json_encode(["status" => "success", "mensaje" => "¡Registro exitoso! Inicia sesión."]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                echo json_encode(["status" => "error", "mensaje" => "Usuario o email ya registrados."]);
            } else {
                echo json_encode(["status" => "error", "mensaje" => "Error DB: " . $e->getMessage()]);
            }
        }
        exit;
    }

    // 2. LOGIN
    if ($action === 'login') {
        $email_user = $input['email_user'];
        $pass = $input['password'];

        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :eu OR username = :eu");
        $stmt->execute(['eu' => $email_user]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['company'] = $user['company_name'];
            $_SESSION['email'] = $user['email'];
            echo json_encode(["status" => "success", "mensaje" => "Bienvenido a SYLO, " . $user['full_name']]);
        } else {
            echo json_encode(["status" => "error", "mensaje" => "Credenciales incorrectas."]);
        }
        exit;
    }

    // 3. LOGOUT
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(["status" => "success", "mensaje" => "Sesión cerrada."]);
        exit;
    }

    // 4. COMPRAR
    if ($action === 'comprar') {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(["status" => "auth_required", "mensaje" => "Debes iniciar sesión."]);
            exit;
        }

        $plan_name = htmlspecialchars($input['plan']);
        $user_id = $_SESSION['user_id'];
        $cliente_email = $_SESSION['email'];

        try {
            $stmt = $conn->prepare("SELECT id FROM plans WHERE name = :name");
            $stmt->execute(['name' => $plan_name]);
            $plan_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $plan_id = $plan_row['id'];

            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (:uid, :pid, 'pending')");
            $stmt->execute(['uid' => $user_id, 'pid' => $plan_id]);
            $order_id = $conn->lastInsertId();

            $contenido_pedido = json_encode([
                "id" => $order_id,
                "plan" => $plan_name,
                "cliente" => $cliente_email,
                "db_user_id" => $user_id,
                "fecha" => date("Y-m-d H:i:s")
            ]);
            
            $archivo_buzon = "/buzon/orden_" . $order_id . ".json";
            file_put_contents($archivo_buzon, $contenido_pedido);

            echo json_encode(["status" => "success", "mensaje" => "¡ORDEN #$order_id CONFIRMADA! Iniciando despliegue de $plan_name..."]);

        } catch (Exception $e) {
            echo json_encode(["status" => "error", "mensaje" => "Error interno: " . $e->getMessage()]);
        }
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --sylo-primary: #0d6efd; --sylo-dark: #212529; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8f9fa; scroll-behavior: smooth; }
        
        /* Navbar */
        .navbar-brand { font-weight: 800; letter-spacing: 1px; font-size: 1.5rem; }
        .nav-link { font-weight: 500; color: #555 !important; transition: color 0.3s; }
        .nav-link:hover { color: var(--sylo-primary) !important; }
        
        /* Icono Usuario */
        .user-icon-btn { background: none; border: none; color: #333; cursor: pointer; transition: transform 0.2s; }
        .user-icon-btn:hover { color: var(--sylo-primary); transform: scale(1.1); }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white; padding: 120px 0; clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
        }
        
        /* Tarjetas de Servicios */
        .card-price {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none; border-radius: 15px; background: white;
        }
        .card-price:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
        .price-tag { font-size: 3rem; font-weight: 800; color: var(--sylo-dark); margin: 20px 0; }
        
        /* Iconos de Infraestructura */
        .infra-icon { font-size: 4rem; color: var(--sylo-primary); opacity: 0.8; margin-bottom: 20px; }

        /* Estilo iconos lista precios */
        .feature-list li i { width: 25px; text-align: center; }
        
        /* Password Toggle */
        .password-container { position: relative; }
        .toggle-password {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #6c757d;
        }
        
        /* Error de contraseña no coincide */
        .password-mismatch { border-color: #dc3545 !important; background-image: none !important; }
        #pass-error-msg { color: #dc3545; font-size: 0.85em; display: none; margin-top: 5px; font-weight: bold; }
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
                    
                    <li class="nav-item ms-3">
                        <a class="nav-link" href="https://github.com/leonardsyloadm-cyber/SYLO-Kubernetes-for-all" target="_blank" title="Repositorio GitHub">
                            <i class="fab fa-github fa-2x"></i>
                        </a>
                    </li>

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
                            <button class="user-icon-btn" onclick="abrirLoginSinError()">
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
                <h2 class="fw-bold display-6">Nuestros Servicios</h2>
                <p class="text-muted">Escalabilidad adaptada a tu crecimiento</p>
            </div>
            
            <div class="row justify-content-center">
                
                <!-- BRONCE -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 p-3">
                        <div class="card-body text-center">
                            <h4 class="text-warning fw-bold"><i class="fas fa-medal me-2"></i>BRONCE</h4>
                            <h1 class="my-3">5€<span class="fs-6 text-muted">/mes</span></h1>
                            <p class="text-muted small">Para pruebas y desarrollo.</p>
                            <ul class="list-unstyled feature-list text-start ps-4 mb-4">
                                <li><i class="fas fa-cube text-secondary"></i> Kubernetes Simple (1 Nodo)</li>
                                <li><i class="fas fa-memory text-info"></i> 2 vCPU / 2 GB RAM</li>
                                <li><i class="fas fa-redo text-success"></i> Auto-reinicio de Pods</li>
                                <li><i class="fas fa-terminal text-dark"></i> Acceso SSH</li>
                            </ul>
                            <button onclick="intentarCompra('Bronce')" class="btn btn-outline-warning w-100 rounded-pill fw-bold">Desplegar</button>
                        </div>
                    </div>
                </div>

                <!-- PLATA -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 border-primary border-2 shadow p-3">
                        <div class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-primary">POPULAR</div>
                        <div class="card-body text-center">
                            <h4 class="text-primary fw-bold"><i class="fas fa-gem me-2"></i>PLATA</h4>
                            <h1 class="my-3">15€<span class="fs-6 text-muted">/mes</span></h1>
                            <p class="text-muted small">Para bases de datos críticas.</p>
                            <ul class="list-unstyled feature-list text-start ps-4 mb-4">
                                <li><i class="fas fa-database text-primary"></i> <strong>K8s + MySQL Cluster</strong></li>
                                <li><i class="fas fa-copy text-success"></i> Replicación Maestro-Esclavo</li>
                                <li><i class="fas fa-microchip text-danger"></i> 4 vCPU / 4 GB RAM</li>
                                <li><i class="fas fa-heartbeat text-danger"></i> Alta Disponibilidad (HA)</li>
                            </ul>
                            <button onclick="intentarCompra('Plata')" class="btn btn-primary w-100 rounded-pill fw-bold shadow">Desplegar</button>
                        </div>
                    </div>
                </div>

                <!-- ORO -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 p-3">
                        <div class="card-body text-center">
                            <h4 style="color: #d4af37;" class="fw-bold"><i class="fas fa-crown me-2"></i>ORO</h4>
                            <h1 class="my-3">30€<span class="fs-6 text-muted">/mes</span></h1>
                            <p class="text-muted small">La solución completa.</p>
                            <ul class="list-unstyled feature-list text-start ps-4 mb-4">
                                <li><i class="fas fa-rocket text-danger"></i> <strong>Infraestructura Total</strong></li>
                                <li><i class="fas fa-network-wired text-primary"></i> Web HA + DB Replicada</li>
                                <li><i class="fas fa-server text-dark"></i> 6 vCPU / 8 GB RAM</li>
                                <li><i class="fas fa-headset text-info"></i> Soporte Prioritario 24/7</li>
                            </ul>
                            <button onclick="intentarCompra('Oro')" class="btn btn-outline-dark w-100 rounded-pill fw-bold">Desplegar</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- MODAL DE AUTENTICACIÓN -->
    <div class="modal fade" id="authModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary">Acceso SYLO</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <ul class="nav nav-tabs nav-fill mb-4" id="authTabs">
                        <li class="nav-item"><button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-pane">Login</button></li>
                        <li class="nav-item"><button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-pane">Registro</button></li>
                    </ul>

                    <div class="tab-content">
                        <!-- Login -->
                        <div class="tab-pane fade show active" id="login-pane">
                            <form onsubmit="handleLogin(event)">
                                <div class="mb-3"><input type="text" id="login_email" class="form-control" placeholder="Usuario o Email" required></div>
                                <div class="mb-3 password-container">
                                    <input type="password" id="login_pass" class="form-control" placeholder="Contraseña" required>
                                    <i class="fas fa-eye toggle-password" onclick="togglePassword('login_pass', this)"></i>
                                </div>
                                <div class="d-grid"><button type="submit" class="btn btn-primary btn-lg rounded-pill">Entrar</button></div>
                            </form>
                        </div>
                        <!-- Registro -->
                        <div class="tab-pane fade" id="register-pane">
                            <form onsubmit="handleRegister(event)">
                                <div class="row mb-2">
                                    <div class="col"><input type="text" id="reg_name" class="form-control" placeholder="Nombre" required></div>
                                    <div class="col"><input type="text" id="reg_user" class="form-control" placeholder="Usuario" required></div>
                                </div>
                                <div class="mb-2"><input type="text" id="reg_company" class="form-control" placeholder="Empresa" required></div>
                                <div class="mb-2"><input type="email" id="reg_email" class="form-control" placeholder="Email" required></div>
                                
                                <!-- Contraseña -->
                                <div class="mb-2 password-container">
                                    <input type="password" id="reg_pass" class="form-control" placeholder="Contraseña" required onkeyup="checkPassMatch()">
                                    <i class="fas fa-eye toggle-password" onclick="togglePassword('reg_pass', this)"></i>
                                </div>
                                <!-- Repetir Contraseña -->
                                <div class="mb-3 password-container">
                                    <input type="password" id="reg_pass_confirm" class="form-control" placeholder="Repetir Contraseña" required onkeyup="checkPassMatch()">
                                    <i class="fas fa-eye toggle-password" onclick="togglePassword('reg_pass_confirm', this)"></i>
                                </div>
                                <div id="pass-error-msg">❌ Las contraseñas no coinciden</div>

                                <div class="form-text small mb-3 text-muted">
                                    * Mín 6 chars, 1 Mayúscula, 1 Símbolo especial
                                </div>
                                <div class="d-grid"><button type="submit" id="btn-register" class="btn btn-success btn-lg rounded-pill">Crear Cuenta</button></div>
                            </form>
                        </div>
                    </div>
                    <div id="authMessage" class="mt-3 text-center fw-bold small"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLogged = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        const authModal = new bootstrap.Modal(document.getElementById('authModal'));

        function abrirLoginSinError() {
            document.getElementById('authMessage').innerHTML = ""; 
            authModal.show();
        }

        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }

        function checkPassMatch() {
            const pass = document.getElementById('reg_pass');
            const conf = document.getElementById('reg_pass_confirm');
            const msg = document.getElementById('pass-error-msg');
            const btn = document.getElementById('btn-register');

            if (pass.value && conf.value && pass.value !== conf.value) {
                conf.classList.add('password-mismatch');
                msg.style.display = 'block';
                btn.disabled = true;
            } else {
                conf.classList.remove('password-mismatch');
                msg.style.display = 'none';
                btn.disabled = false;
            }
        }

        async function intentarCompra(plan) {
            if (!isLogged) {
                document.getElementById('authMessage').innerHTML = "<span class='text-danger bg-light px-2 py-1 rounded'>⚠️ Inicia sesión para comprar.</span>";
                authModal.show();
                return;
            }
            if(confirm(`¿Desplegar plan ${plan}?`)) {
                document.body.style.cursor = 'wait';
                try {
                    const res = await fetch('index.php', {
                        method: 'POST', headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: 'comprar', plan: plan })
                    });
                    const data = await res.json();
                    alert(data.status === 'success' ? "✅ " + data.mensaje : "❌ " + data.mensaje);
                } catch(e) { alert("Error de red."); }
                finally { document.body.style.cursor = 'default'; }
            }
        }

        async function handleLogin(e) {
            e.preventDefault();
            const res = await fetch('index.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'login', email_user: document.getElementById('login_email').value, password: document.getElementById('login_pass').value })
            });
            const data = await res.json();
            if(data.status === 'success') { location.reload(); } 
            else { document.getElementById('authMessage').innerHTML = `<span class='text-danger'>${data.mensaje}</span>`; }
        }

        async function handleRegister(e) {
            e.preventDefault();
            if(document.getElementById('reg_pass').value !== document.getElementById('reg_pass_confirm').value) {
                checkPassMatch(); return;
            }
            const dataPayload = {
                action: 'register',
                full_name: document.getElementById('reg_name').value,
                username: document.getElementById('reg_user').value,
                company: document.getElementById('reg_company').value,
                email: document.getElementById('reg_email').value,
                password: document.getElementById('reg_pass').value
            };
            const res = await fetch('index.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(dataPayload)
            });
            const data = await res.json();
            if(data.status === 'success') {
                document.getElementById('authMessage').innerHTML = `<span class='text-success'>${data.mensaje}</span>`;
                setTimeout(() => document.getElementById('login-tab').click(), 1500);
            } else {
                document.getElementById('authMessage').innerHTML = `<span class='text-danger'>${data.mensaje}</span>`;
            }
        }

        async function logout() {
            await fetch('index.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ action: 'logout' }) });
            location.reload();
        }
    </script>
</body>
</html>