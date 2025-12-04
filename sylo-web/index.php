<?php
// --- LÓGICA DE BACKEND (PHP) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data) {
        $plan = htmlspecialchars($data['plan']); // "Bronce" o "Plata"
        $cliente = htmlspecialchars($data['cliente']);
        $id_pedido = time();
        
        // Estructura del pedido
        $contenido_pedido = json_encode([
            "id" => $id_pedido,
            "plan" => $plan,
            "cliente" => $cliente,
            "fecha" => date("Y-m-d H:i:s")
        ]);
        
        // ESCRIBIMOS EN EL BUZÓN COMPARTIDO
        $archivo = "/buzon/pedido_" . $id_pedido . ".json";
        
        if (file_put_contents($archivo, $contenido_pedido) !== false) {
             $mensaje = "¡ORDEN RECIBIDA! El despliegue del plan '$plan' ha comenzado en segundo plano (Pedido #$id_pedido).";
             $mensaje .= "\nEl sistema Worker iniciará la creación del clúster en breve.";
             $status = "success";
        } else {
             $mensaje = "Error crítico: No se pudo escribir en el buzón de pedidos (/buzon).";
             $status = "error";
        }
        
        // Respuesta inmediata
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
        .feature-list { padding: 0 20px; }
        .feature-list li { margin-bottom: 12px; color: #555; display: flex; align-items: center; }
        
        /* Botón GitHub */
        .github-btn { color: #333; transition: color 0.3s; }
        .github-btn:hover { color: var(--sylo-primary); }

        .log-display {
            font-family: monospace;
            white-space: pre-wrap;
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            padding: 10px;
            max-height: 300px;
            overflow-y: scroll;
            margin-top: 10px;
            font-size: 0.8rem;
        }
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
                    
                    <!-- ENLACE AL REPOSITORIO GITHUB -->
                    <li class="nav-item ms-3">
                        <a class="nav-link github-btn" href="https://github.com/leonardsyloadm-cyber/SYLO-Kubernetes-for-all" target="_blank" title="Ver Código en GitHub">
                            <i class="fab fa-github fa-2x"></i>
                        </a>
                    </li>
                    
                    <li class="nav-item ms-3 border-start ps-3">
                        <button class="btn btn-link text-dark p-0" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ACERCA DE NOSOTROS (HERO) -->
    <section id="nosotros" class="hero text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-4">Infraestructura Cloud Automatizada</h1>
            <p class="lead mb-5 w-75 mx-auto opacity-75">
                SYLOBI elimina la complejidad de la nube. Ofrecemos despliegue instantáneo de clústeres Kubernetes y bases de datos auto-replicadas.
                <br>Sin configuraciones manuales. Sin costes ocultos. Solo código.
            </p>
            <a href="#servicios" class="btn btn-light btn-lg px-5 fw-bold text-primary rounded-pill shadow">Ver Planes</a>
        </div>
    </section>

    <!-- SECCIÓN SERVICIOS Y PRECIOS -->
    <section id="servicios" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-6">Nuestros Servicios</h2>
                <p class="text-muted">Elige la potencia que necesita tu proyecto</p>
                
                <!-- Alerta de Estado -->
                <div id="statusAlert" class="mt-4 alert alert-info" style="display:none; text-align: left;">
                    <strong>Estado del Aprovisionamiento:</strong>
                    <div id="logContent" class="log-display"></div>
                </div>
            </div>

            <div class="row justify-content-center align-items-center">
                
                <!-- PLAN BRONCE (K8s Simple) -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100">
                        <div class="card-header bg-transparent border-0 pt-4 text-center text-warning fw-bold">BRONCE</div>
                        <div class="card-body text-center">
                            <div class="price-tag">5€<span class="text-muted-small">/mes</span></div>
                            <p class="text-muted mb-4">Ideal para pruebas y desarrollo ligero.</p>
                            <ul class="list-unstyled feature-list text-start ps-4">
                                <li><i class="fas fa-cubes text-warning me-2"></i> <strong>Kubernetes Simple</strong> (Minikube)</li>
                                <li><i class="fas fa-microchip text-secondary me-2"></i> 1 vCPU / 1 GB RAM</li>
                                <li><i class="fas fa-hdd text-muted me-2"></i> Sin almacenamiento persistente</li>
                                <li><i class="fas fa-terminal text-success me-2"></i> Acceso SSH</li>
                            </ul>
                            <button onclick="comprar('Bronce')" class="btn btn-outline-warning w-100 mt-4 rounded-pill fw-bold">Desplegar Bronce</button>
                        </div>
                    </div>
                </div>

                <!-- PLAN PLATA (K8s + DB HA) -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100 border-primary border-2 shadow-lg" style="transform: scale(1.05);">
                        <div class="card-header bg-transparent border-0 pt-4 text-center text-secondary fw-bold">PLATA <span class="badge bg-primary ms-2">Top</span></div>
                        <div class="card-body text-center">
                            <div class="price-tag text-primary">15€<span class="text-muted-small">/mes</span></div>
                            <p class="text-muted mb-4">Para aplicaciones en producción.</p>
                            <ul class="list-unstyled feature-list text-start ps-4">
                                <li><i class="fas fa-server text-primary me-2"></i> <strong>K8s + MySQL Cluster</strong></li>
                                <li><i class="fas fa-sync text-success me-2"></i> Replicación Maestro-Esclavo</li>
                                <li><i class="fas fa-microchip text-secondary me-2"></i> 4 vCPU / 4 GB RAM</li>
                                <li><i class="fas fa-shield-alt text-success me-2"></i> Alta Disponibilidad</li>
                            </ul>
                            <button onclick="comprar('Plata')" class="btn btn-primary w-100 mt-4 rounded-pill fw-bold">Desplegar Plata</button>
                        </div>
                    </div>
                </div>

                <!-- PLAN ORO (Full Stack) -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card card-price h-100">
                        <div class="card-header bg-transparent border-0 pt-4 text-center" style="color: #d4af37; font-weight: bold;">ORO</div>
                        <div class="card-body text-center">
                            <div class="price-tag">30€<span class="text-muted-small">/mes</span></div>
                            <p class="text-muted mb-4">Máxima potencia y soporte.</p>
                            <ul class="list-unstyled feature-list text-start ps-4">
                                <li><i class="fas fa-rocket text-danger me-2"></i> <strong>Infraestructura Total</strong></li>
                                <li><i class="fas fa-network-wired text-primary me-2"></i> Web + DB Replicada + Balanceador</li>
                                <li><i class="fas fa-microchip text-secondary me-2"></i> 6 vCPU / 8 GB RAM</li>
                                <li><i class="fas fa-headset text-info me-2"></i> Soporte 24/7</li>
                            </ul>
                            <button onclick="comprar('Oro')" class="btn btn-outline-dark w-100 mt-4 rounded-pill fw-bold" disabled>Próximamente</button>
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
                        <div class="d-grid"><button type="button" onclick="alert('Sistema KYLO DB en mantenimiento')" class="btn btn-primary">Entrar</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        async function comprar(plan) {
            if(confirm(`¿Confirmas la contratación del plan ${plan}?`)) {
                
                const statusAlert = document.getElementById('statusAlert');
                const logContent = document.getElementById('logContent');

                // 1. Mostrar estado de carga inmediato
                logContent.innerHTML = '⏳ Procesando orden...';
                statusAlert.className = 'mt-4 alert alert-info';
                statusAlert.style.display = 'block';
                
                try {
                    const response = await fetch('index.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ plan: plan, cliente: 'Usuario Web' })
                    });
                    const data = await response.json();
                    
                    if (data.status === 'success') {
                        statusAlert.className = 'mt-4 alert alert-success';
                    } else {
                        statusAlert.className = 'mt-4 alert alert-danger';
                    }

                    logContent.innerHTML = data.mensaje;
                    statusAlert.scrollIntoView({ behavior: 'smooth' });
                    
                } catch (e) {
                    console.error("Error:", e);
                    logContent.innerHTML = "❌ Error de conexión.";
                    statusAlert.className = 'mt-4 alert alert-danger';
                }
            }
        }
    </script>
</body>
</html>