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

// --- API ROUTER (MANEJO DE PETICIONES) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    header('Content-Type: application/json');

    // ---------------------------------------------------------
    // ACCIÓN 1: REGISTRO DE NUEVO USUARIO
    // ---------------------------------------------------------
    if ($action === 'register') {
        $user = htmlspecialchars($input['username']);
        $pass = $input['password'];
        $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
        $name = htmlspecialchars($input['full_name']);
        $company = htmlspecialchars($input['company']);

        // VALIDACIÓN DE CONTRASEÑA (Tu requisito específico)
        // ^(?=.*[A-Z])(?=.*[_\-]).{6,}$ 
        // Significa: Al menos una Mayúscula, Al menos un _ o -, Mínimo 6 chars
        if (!preg_match('/^(?=.*[A-Z])(?=.*[_\-]).{6,}$/', $pass)) {
            echo json_encode(["status" => "error", "mensaje" => "La contraseña es débil. Mínimo 6 caracteres, una Mayúscula y un símbolo (_ o -)."]);
            exit;
        }

        if (!$email) {
            echo json_encode(["status" => "error", "mensaje" => "Email inválido."]);
            exit;
        }

        try {
            // Encriptamos contraseña (NUNCA guardar en texto plano)
            $hash = password_hash($pass, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, company_name) VALUES (:user, :name, :email, :hash, :comp)");
            $stmt->execute(['user'=>$user, 'name'=>$name, 'email'=>$email, 'hash'=>$hash, 'comp'=>$company]);
            
            echo json_encode(["status" => "success", "mensaje" => "¡Cuenta creada! Ahora puedes iniciar sesión."]);
        } catch (PDOException $e) {
            // Código 23000 es duplicado (username o email ya existen)
            if ($e->getCode() == 23000) {
                echo json_encode(["status" => "error", "mensaje" => "El usuario o el correo ya están registrados."]);
            } else {
                echo json_encode(["status" => "error", "mensaje" => "Error DB: " . $e->getMessage()]);
            }
        }
        exit;
    }

    // ---------------------------------------------------------
    // ACCIÓN 2: LOGIN
    // ---------------------------------------------------------
    if ($action === 'login') {
        $email_user = $input['email_user']; // Puede entrar con usuario o email
        $pass = $input['password'];

        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :eu OR username = :eu");
        $stmt->execute(['eu' => $email_user]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password_hash'])) {
            // ¡LOGIN CORRECTO! Guardamos datos en sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['company'] = $user['company_name'];
            $_SESSION['email'] = $user['email'];
            
            echo json_encode(["status" => "success", "mensaje" => "Bienvenido a SYLO, " . $user['full_name']]);
        } else {
            echo json_encode(["status" => "error", "mensaje" => "Usuario o contraseña incorrectos."]);
        }
        exit;
    }

    // ---------------------------------------------------------
    // ACCIÓN 3: LOGOUT
    // ---------------------------------------------------------
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(["status" => "success", "mensaje" => "Sesión cerrada."]);
        exit;
    }

    // ---------------------------------------------------------
    // ACCIÓN 4: COMPRAR (DESPLEGAR) - AHORA PROTEGIDO
    // ---------------------------------------------------------
    if ($action === 'comprar') {
        
        // SEGURIDAD: Si no hay sesión, bloqueamos
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(["status" => "auth_required", "mensaje" => "Debes iniciar sesión para desplegar infraestructura."]);
            exit;
        }

        $plan_name = htmlspecialchars($input['plan']);
        // Usamos los datos reales de la sesión del usuario conectado
        $user_id = $_SESSION['user_id'];
        $cliente_email = $_SESSION['email'];

        try {
            // Buscar Plan
            $stmt = $conn->prepare("SELECT id FROM plans WHERE name = :name");
            $stmt->execute(['name' => $plan_name]);
            $plan_row = $stmt->fetch(PDO::FETCH_ASSOC);
            $plan_id = $plan_row['id'];

            // Crear Orden
            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (:uid, :pid, 'pending')");
            $stmt->execute(['uid' => $user_id, 'pid' => $plan_id]);
            $order_id = $conn->lastInsertId();

            // Escribir en Buzón para el Orquestador
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
    <title>SYLOBI | Plataforma Cloud</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --sylo-primary: #0d6efd; --sylo-dark: #212529; }
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8f9fa; }
        .hero { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 80px 0; clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%); }
        .card-price:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
        /* Estilo para pestañas del modal */
        .nav-tabs .nav-link { color: #555; }
        .nav-tabs .nav-link.active { color: var(--sylo-primary); font-weight: bold; border-bottom: 3px solid var(--sylo-primary); }
    </style>
</head>
<body>

    <!-- NAVBAR DINÁMICA -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#"><i class="fas fa-cubes text-primary me-2"></i>SYLOBI</a>
            
            <div class="d-flex align-items-center">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- SI ESTÁ LOGUEADO: Muestra nombre y menú -->
                    <div class="dropdown">
                        <button class="btn btn-outline-primary dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-check me-2"></i> <?php echo $_SESSION['username']; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li><h6 class="dropdown-header text-muted">Empresa: <?php echo $_SESSION['company']; ?></h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="logout()"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <!-- SI NO ESTÁ LOGUEADO: Botón de Acceso -->
                    <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#authModal">
                        <i class="fas fa-sign-in-alt me-2"></i> Acceso Clientes
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-4">Tu Infraestructura en un Clic</h1>
            <p class="lead opacity-75">Kubernetes gestionado y Bases de Datos replicadas sin dolores de cabeza.</p>
        </div>
    </section>

    <!-- PRECIOS -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5"><h2 class="fw-bold">Planes Cloud</h2></div>
            <div class="row justify-content-center">
                <!-- BRONCE -->
                <div class="col-md-4 mb-4">
                    <div class="card card-price h-100 p-3">
                        <div class="card-body text-center">
                            <h4 class="text-warning fw-bold">BRONCE</h4>
                            <h1 class="my-3">5€</h1>
                            <button onclick="intentarCompra('Bronce')" class="btn btn-outline-warning w-100 rounded-pill mt-3">Desplegar</button>
                        </div>
                    </div>
                </div>
                <!-- PLATA -->
                <div class="col-md-4 mb-4">
                    <div class="card card-price h-100 border-primary border-2 shadow p-3">
                        <div class="card-body text-center">
                            <h4 class="text-primary fw-bold">PLATA</h4>
                            <h1 class="my-3">15€</h1>
                            <button onclick="intentarCompra('Plata')" class="btn btn-primary w-100 rounded-pill mt-3 fw-bold shadow">Desplegar</button>
                        </div>
                    </div>
                </div>
                <!-- ORO -->
                <div class="col-md-4 mb-4">
                    <div class="card card-price h-100 p-3">
                        <div class="card-body text-center">
                            <h4 style="color: #d4af37;" class="fw-bold">ORO</h4>
                            <h1 class="my-3">30€</h1>
                            <button onclick="intentarCompra('Oro')" class="btn btn-outline-dark w-100 rounded-pill mt-3">Desplegar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- MODAL DE AUTENTICACIÓN (LOGIN / REGISTRO) -->
    <div class="modal fade" id="authModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="fas fa-lock me-2"></i>Portal de Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <!-- Pestañas -->
                    <ul class="nav nav-tabs nav-fill mb-4" id="authTabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-pane">Iniciar Sesión</button></li>
                        <li class="nav-item"><button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-pane">Crear Cuenta</button></li>
                    </ul>

                    <div class="tab-content">
                        <!-- FORMULARIO LOGIN -->
                        <div class="tab-pane fade show active" id="login-pane">
                            <form onsubmit="handleLogin(event)">
                                <div class="mb-3">
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="fas fa-envelope"></i></span>
                                        <input type="text" id="login_email" class="form-control" placeholder="Usuario o Email" required>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="fas fa-key"></i></span>
                                        <input type="password" id="login_pass" class="form-control" placeholder="Contraseña" required>
                                    </div>
                                </div>
                                <div class="d-grid"><button type="submit" class="btn btn-primary btn-lg rounded-pill">Entrar</button></div>
                            </form>
                        </div>

                        <!-- FORMULARIO REGISTRO -->
                        <div class="tab-pane fade" id="register-pane">
                            <form onsubmit="handleRegister(event)">
                                <div class="row mb-2">
                                    <div class="col"><input type="text" id="reg_name" class="form-control" placeholder="Nombre Completo" required></div>
                                    <div class="col"><input type="text" id="reg_user" class="form-control" placeholder="Usuario" required></div>
                                </div>
                                <div class="mb-2">
                                    <input type="text" id="reg_company" class="form-control" placeholder="Empresa" required>
                                </div>
                                <div class="mb-2">
                                    <input type="email" id="reg_email" class="form-control" placeholder="Correo Electrónico" required>
                                </div>
                                <div class="mb-3">
                                    <input type="password" id="reg_pass" class="form-control" placeholder="Contraseña" required>
                                    <div class="form-text small text-danger">
                                        * Mín 6 chars, 1 Mayúscula, 1 Símbolo (_ o -)
                                    </div>
                                </div>
                                <div class="d-grid"><button type="submit" class="btn btn-success btn-lg rounded-pill">Registrarse</button></div>
                            </form>
                        </div>
                    </div>

                    <!-- Mensajes de error/éxito -->
                    <div id="authMessage" class="mt-3 text-center fw-bold small"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Estado de sesión inyectado por PHP
        const isLogged = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;

        // --- LÓGICA DE COMPRA ---
        async function intentarCompra(plan) {
            if (!isLogged) {
                // Si no está logueado, abrimos modal y mostramos aviso
                const modal = new bootstrap.Modal(document.getElementById('authModal'));
                modal.show();
                document.getElementById('authMessage').innerHTML = "<span class='text-danger bg-light px-2 py-1 rounded'>⚠️ Inicia sesión para desplegar este plan.</span>";
                return;
            }

            if(confirm(`¿Confirmas el despliegue del plan ${plan}?`)) {
                document.body.style.cursor = 'wait';
                try {
                    const res = await fetch('index.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ action: 'comprar', plan: plan })
                    });
                    const data = await res.json();
                    
                    if (data.status === 'auth_required') {
                        location.reload(); // Sesión caducada
                    } else {
                        alert((data.status === 'success' ? "✅ " : "❌ ") + data.mensaje);
                    }
                } catch(e) { alert("Error de red."); }
                finally { document.body.style.cursor = 'default'; }
            }
        }

        // --- LÓGICA DE LOGIN ---
        async function handleLogin(e) {
            e.preventDefault();
            const email = document.getElementById('login_email').value;
            const pass = document.getElementById('login_pass').value;
            
            const msgDiv = document.getElementById('authMessage');
            msgDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';

            const res = await fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'login', email_user: email, password: pass })
            });
            const data = await res.json();

            if(data.status === 'success') {
                msgDiv.innerHTML = `<span class='text-success'>${data.mensaje}</span>`;
                setTimeout(() => location.reload(), 1000); // Recargar para mostrar menú usuario
            } else {
                msgDiv.innerHTML = `<span class='text-danger'>${data.mensaje}</span>`;
            }
        }

        // --- LÓGICA DE REGISTRO ---
        async function handleRegister(e) {
            e.preventDefault();
            const dataPayload = {
                action: 'register',
                full_name: document.getElementById('reg_name').value,
                username: document.getElementById('reg_user').value,
                company: document.getElementById('reg_company').value,
                email: document.getElementById('reg_email').value,
                password: document.getElementById('reg_pass').value
            };

            const msgDiv = document.getElementById('authMessage');
            msgDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando cuenta...';

            const res = await fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(dataPayload)
            });
            const data = await res.json();

            if(data.status === 'success') {
                msgDiv.innerHTML = `<span class='text-success'>${data.mensaje}</span>`;
                // Cambiar a pestaña login automáticamente
                setTimeout(() => document.getElementById('login-tab').click(), 1500);
            } else {
                msgDiv.innerHTML = `<span class='text-danger'>${data.mensaje}</span>`;
            }
        }

        // --- LÓGICA DE LOGOUT ---
        async function logout() {
            await fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'logout' })
            });
            location.reload();
        }
    </script>
</body>
</html>