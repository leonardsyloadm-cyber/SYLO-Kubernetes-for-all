<?php
// --- LÓGICA "FIRE AND FORGET" (DISPARAR Y OLVIDAR) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data) {
        $plan = htmlspecialchars($data['plan']);
        $cliente = htmlspecialchars($data['cliente']);
        $id = time();
        
        // Creamos la señal (Archivo JSON)
        $orden = json_encode([
            "id" => $id,
            "plan" => $plan,
            "cliente" => $cliente,
            "timestamp" => date("c")
        ]);
        
        // Guardamos la señal en el volumen compartido
        // La web NO espera a que se cree el cluster. Solo deja la nota.
        $archivo = "/buzon/orden_" . $id . ".json";
        
        if (file_put_contents($archivo, $orden)) {
            $mensaje = "¡ORDEN ENVIADA! El orquestador ha recibido la señal para el plan $plan.";
            $status = "success";
        } else {
            $mensaje = "Error: No se pudo escribir en el buzón de señales.";
            $status = "error";
        }
        
        // Respuesta inmediata al navegador
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Tus estilos anteriores se mantienen igual */
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8f9fa; }
        .hero { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 100px 0; }
        .card-price:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.15); }
    </style>
</head>
<body>

    <!-- (EL HTML VISUAL ES IGUAL QUE ANTES, SOLO PONGO EL SCRIPT JS CAMBIADO) -->
    
    <nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-cube text-primary"></i> SYLOBI</a>
        </div>
    </nav>

    <section class="hero text-center">
        <div class="container">
            <h1 class="display-4 fw-bold">Kubernetes para Todos</h1>
            <p class="lead">Automatización real. Sin esperas.</p>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center">
                <!-- PLAN PLATA -->
                <div class="col-md-4">
                    <div class="card card-price h-100 border-primary border-2">
                        <div class="card-body text-center">
                            <h3 class="text-primary">PLATA</h3>
                            <p>K8s + DB Replicada</p>
                            <h2 class="my-4">15€</h2>
                            <button onclick="comprar('Plata')" class="btn btn-primary w-100">Desplegar Ahora</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        async function comprar(plan) {
            if(confirm(`¿Lanzar despliegue del plan ${plan}?`)) {
                try {
                    const response = await fetch('index.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ plan: plan, cliente: 'Usuario Web' })
                    });
                    const data = await response.json();
                    
                    if(data.status === 'success') {
                        alert("✅ " + data.mensaje + "\n\nMira tu terminal del Orquestador para ver el progreso.");
                    } else {
                        alert("❌ " + data.mensaje);
                    }
                } catch (e) {
                    alert("Error de conexión con la web.");
                }
            }
        }
    </script>
</body>
</html>