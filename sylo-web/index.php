<?php
// --- CONFIGURACIÓN DE LA BASE DE DATOS ---
// Nombre del host del servicio/contenedor de la base de datos
$servername = "kylo-main-db"; 
$username = "sylo_app";
$password = "sylo_app_pass";
$dbname = "kylo_main_db";

// --- LÓGICA DEL BACKEND ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Leemos el JSON que envía el JavaScript
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data) {
        $plan_name = htmlspecialchars($data['plan']);
        // Simulamos que el cliente es el email (en el futuro vendrá del login)
        $cliente_email = htmlspecialchars($data['cliente']);
        
        try {
            // 1. CONECTAR A LA BASE DE DATOS (PDO)
            $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
            // Activamos el modo de excepciones para capturar errores
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 2. BUSCAR EL ID DEL PLAN EN LA TABLA 'plans'
            // Usamos sentencias preparadas (prepare/execute) para EVITAR INYECCIÓN SQL
            $stmt = $conn->prepare("SELECT id FROM plans WHERE name = :name");
            $stmt->execute(['name' => $plan_name]);
            $plan_row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plan_row) {
                throw new Exception("El plan '$plan_name' no existe en el catálogo.");
            }
            $plan_id = $plan_row['id'];

            // 3. GUARDAR O ACTUALIZAR EL USUARIO EN 'users'
            // Si el email ya existe, actualizamos el ID para obtenerlo
            $stmt = $conn->prepare("INSERT INTO users (email, password_hash, company_name) VALUES (:email, 'hash_dummy', 'Cliente Web') 
                                    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            $stmt->execute(['email' => $cliente_email]);
            $user_id = $conn->lastInsertId();

            // 4. CREAR LA ORDEN DE COMPRA EN 'orders'
            $stmt = $conn->prepare("INSERT INTO orders (user_id, plan_id, status) VALUES (:uid, :pid, 'pending')");
            $stmt->execute(['uid' => $user_id, 'pid' => $plan_id]);
            $order_id = $conn->lastInsertId(); // Obtenemos el ID único de la orden

            // 5. ESCRIBIR EN EL BUZÓN (La señal para el Orquestador)
            // Ahora el JSON incluye el ID real de la base de datos para trazabilidad
            $contenido_pedido = json_encode([
                "id" => $order_id,
                "plan" => $plan_name,
                "cliente" => $cliente_email,
                "db_user_id" => $user_id,
                "fecha" => date("Y-m-d H:i:s")
            ]);
            
            $archivo_buzon = "/buzon/orden_" . $order_id . ".json";
            
            if (!file_put_contents($archivo_buzon, $contenido_pedido)) {
                throw new Exception("Error crítico escribiendo en el sistema de archivos (/buzon).");
            }

            $mensaje = "¡COMPRA REGISTRADA! Orden #$order_id guardada en Base de Datos.\nEl orquestador ha recibido la señal.";
            $status = "success";

        } catch(PDOException $e) {
            // Error de conexión o consulta SQL
            $mensaje = "Error de Base de Datos: " . $e->getMessage();
            $status = "error";
        } catch(Exception $e) {
            // Otros errores
            $mensaje = "Error: " . $e->getMessage();
            $status = "error";
        }
        
        // Devolvemos respuesta JSON al navegador
        header('Content-Type: application/json');
        echo json_encode(["mensaje" => $mensaje, "status" => $status]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYLOBI | Kubernetes for All</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --sylo-primary: #0d6efd; --sylo-dark: #212529; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8f9fa; }
        
        .navbar-brand { font-weight: 800; letter-spacing: 1px; font-size: 1.5rem; }
        .hero {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white; padding: 100px 0; margin-bottom: 50px;
            clip-path: polygon(0 0, 100% 0, 100% 90%, 0 100%);
        }
        .card-price {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none; border-radius: 15px; background: white;
        }
        .card-price:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
        .price-tag { font-size: 3rem; font-weight: 800; color: var(--sylo-dark); margin: 20px 0; }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <span class="fa-stack fa-lg me-2" style="font-size: 0.8em;">
                    <i class="fas fa-cube fa-stack-2x text-primary" style="opacity: 0.4; transform: translate(-5px, 5px);"></i>
                    <i class="fas fa-cube fa-stack-2x text-primary"></i>
                </span>
                SYLOBI
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="#nosotros">Acerca de nosotros</a></li>
                    <li class="nav-item"><a class="nav-link" href="#servicios">Servicios y Precios</a></li>
                    <li class="nav-item ms-3"><a class="nav-link" href="#" target="_blank"><i class="fab fa-github fa-xl"></i></a></li>
                    <li class="nav-item ms-3 border-start ps-3">
                        <button class="btn btn-link text-dark p-0" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section id="nosotros" class="hero text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-4">Kubernetes para Todos</h1>
            <p class="lead mb-5 w-75 mx-auto opacity-75">
                SYLOBI democratiza la nube. Despliega tus aplicaciones y bases de datos en alta disponibilidad en segundos.
            </p>
        </div>
    </section>

    <!-- SECCIÓN PRECIOS -->
    <section id="servicios" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-6">Nuestros Servicios</h2>
                <p class="text-muted">Elige la potencia que necesita tu proyecto</p>
            </div>

            <div class="row justify-content-center align-items-center">
                
                <!-- BRONCE -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100">
                        <div class="card-header bg-transparent border-0 pt-4 text-center text-warning fw-bold">BRONCE</div>
                        <div class="card-body text-center">
                            <div class="price-tag">5€<span class="fs-6 text-muted">/mes</span></div>
                            <p class="text-muted mb-4">Ideal para pruebas.</p>
                            <button onclick="comprar('Bronce')" class="btn btn-outline-warning w-100 mt-4 rounded-pill fw-bold">Elegir Bronce</button>
                        </div>
                    </div>
                </div>

                <!-- PLATA -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 border-primary border-2 shadow-lg" style="transform: scale(1.05);">
                        <div class="card-header bg-transparent border-0 pt-4 text-center text-secondary fw-bold">PLATA <span class="badge bg-primary ms-2">Top</span></div>
                        <div class="card-body text-center">
                            <div class="price-tag text-primary">15€<span class="fs-6 text-muted">/mes</span></div>
                            <p class="text-muted mb-4">Para producción.</p>
                            <button onclick="comprar('Plata')" class="btn btn-primary w-100 mt-4 rounded-pill fw-bold">Elegir Plata</button>
                        </div>
                    </div>
                </div>

                <!-- ORO -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 border-warning border-1">
                        <div class="card-header bg-transparent border-0 pt-4 text-center" style="color: #d4af37; font-weight: 900;">ORO</div>
                        <div class="card-body text-center">
                            <div class="price-tag">30€<span class="fs-6 text-muted">/mes</span></div>
                            <p class="text-muted mb-4">Máxima potencia.</p>
                            <button onclick="comprar('Oro')" class="btn btn-warning text-white w-100 mt-4 rounded-pill fw-bold shadow-sm" style="background-color: #d4af37; border-color: #d4af37;">Desplegar Oro</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- MODAL LOGIN -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Acceso Clientes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form>
                        <div class="mb-3"><input type="email" class="form-control" placeholder="Email"></div>
                        <div class="mb-3"><input type="password" class="form-control" placeholder="Contraseña"></div>
                        <div class="d-grid"><button type="button" onclick="alert('Login en desarrollo')" class="btn btn-primary">Entrar</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        async function comprar(plan) {
            // Simulamos un email aleatorio (en producción vendría de la sesión de usuario)
            const emailUsuario = "cliente_" + Math.floor(Math.random() * 1000) + "@empresa.com";

            if(confirm(`¿Confirmas la contratación del plan ${plan}?`)) {
                document.body.style.cursor = 'wait';
                try {
                    const response = await fetch('index.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ plan: plan, cliente: emailUsuario })
                    });
                    const data = await response.json();
                    
                    if (data.status === 'success') {
                        alert("✅ " + data.mensaje);
                    } else {
                        alert("❌ " + data.mensaje);
                    }
                } catch (e) {
                    alert("Error de conexión con el servidor.");
                } finally {
                    document.body.style.cursor = 'default';
                }
            }
        }
    </script>
</body>
</html>