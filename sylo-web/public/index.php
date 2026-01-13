<?php
// view: sylo-web/public/index.php
require_once 'php/auth.php';
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
    
    <link rel="stylesheet" href="css/style.css">
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
                    <a href="../panel/dashboard.php" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold">CONSOLA</a>
                    <button class="btn btn-link text-danger btn-sm" onclick="logout()"><i class="fas fa-sign-out-alt"></i></button>
                <?php else: ?>
                    <button class="btn btn-outline-primary btn-sm rounded-pill px-4 fw-bold" onclick="openM('authModal')">CLIENTE</button>
                <?php endif; ?>
                
            </div>
        </div>
    </nav>

    <section class="hero text-center">
        <div class="container">
            <span class="badge bg-primary mb-3 px-3 py-1 rounded-pill">V113 Stable</span>
            <h1 class="display-3 fw-bold mb-4">Infraestructura <span class="text-primary">Ryzen™</span></h1>
            <p class="lead mb-5">Orquestación Kubernetes V21 desde Alicante, España.</p>
        </div>
    </section>

    <section id="empresa" class="py-5 bg-white"><div class="container"><div class="row align-items-center mb-5"><div class="col-lg-6"><h6 class="text-primary fw-bold">NUESTRA MISIÓN</h6><h2 class="fw-bold mb-4">Ingeniería Real</h2><p class="text-muted">Sylo nació en Alicante para eliminar la complejidad. Usamos hardware Threadripper y NVMe Gen5 real.</p><div class="mt-4"><a href="https://github.com/leonardsyloadm-cyber/SYLO-Kubernetes-for-all" target="_blank" class="btn btn-outline-dark rounded-pill px-4"><i class="fab fa-github me-2"></i>GitHub</a></div></div><div class="col-lg-6"><div class="row g-4"><div class="col-6 text-center"><div class="p-4 rounded bg-light border"><img src="https://ui-avatars.com/api/?name=Ivan+Arlanzon&background=0f172a&color=fff&size=100" class="avatar"><h5 class="fw-bold">Ivan A.</h5><span class="badge bg-primary">CEO</span></div></div><div class="col-6 text-center"><div class="p-4 rounded bg-light border"><img src="https://ui-avatars.com/api/?name=Leonard+Baicu&background=2563eb&color=fff&size=100" class="avatar"><h5 class="fw-bold">Leonard B.</h5><span class="badge bg-success">CTO</span></div></div></div></div></div></div></section>

    <section id="bench" class="py-5 bg-light"><div class="container"><div class="row align-items-center"><div class="col-lg-5"><h2 class="fw-bold mb-3">Rendimiento Bruto</h2><p>Cinebench R23 Single Core.</p><div class="d-flex justify-content-between small fw-bold mt-4"><span>SYLO Ryzen</span><span class="text-primary">1,950 pts</span></div><div class="bench-bar b-sylo" style="width:100%"></div><div class="d-flex justify-content-between small fw-bold mt-3"><span>AWS c6a</span><span>1,420 pts</span></div><div class="bench-bar b-aws" style="width:72%"></div></div><div class="col-lg-6 offset-lg-1"><div class="row g-3"><div class="col-6"><div class="p-4 border rounded-4 text-center bg-white"><i class="fas fa-hdd fa-2x text-danger mb-2"></i><h5 class="fw-bold">NVMe Gen5</h5><small>7,500 MB/s</small></div></div><div class="col-6"><div class="p-4 border rounded-4 text-center bg-white"><i class="fas fa-memory fa-2x text-success mb-2"></i><h5 class="fw-bold">DDR5 ECC</h5><small>Error Correction</small></div></div></div></div></div></div></section>

    <section id="calculadora" class="container py-5 my-5"><div class="calc-box shadow-lg"><div class="row g-5"><div class="col-lg-6"><h4 class="text-white mb-4"><i class="fas fa-calculator me-2 text-primary"></i>Configurador</h4><select class="form-select w-50 bg-dark text-white border-secondary mb-4" id="calc-preset" onchange="applyPreset()"><option value="custom">-- A Medida --</option><option value="bronce">Plan Bronce</option><option value="plata">Plan Plata</option><option value="oro">Plan Oro</option></select>
        <label class="small text-white-50 fw-bold">vCPU (5€): <span id="c-cpu">1</span></label><input type="range" class="form-range mb-4" min="1" max="16" value="1" id="in-cpu" oninput="userMovedSlider()">
        <label class="small text-white-50 fw-bold">RAM (5€): <span id="c-ram">1</span> GB</label><input type="range" class="form-range mb-4" min="1" max="32" value="1" id="in-ram" oninput="userMovedSlider()">
        <div class="row g-2"><div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check-calc-db" onchange="userMovedSlider()"><label class="text-white small">DB (+5€)</label></div></div><div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check-calc-web" onchange="userMovedSlider()"><label class="text-white small">Web (+5€)</label></div></div></div>
    </div><div class="col-lg-6"><div class="bg-white p-5 rounded-4 h-100 d-flex flex-column justify-content-center text-dark"><h5 class="fw-bold mb-3">Estimación</h5><div class="d-flex justify-content-between"><span class="fw-bold text-primary">SYLO</span><span class="price-display" id="out-sylo">0€</span></div><div class="progress mb-3" style="height:20px"><div id="pb-sylo" class="progress-bar bg-primary" style="width:0%"></div></div><div class="d-flex justify-content-between small text-muted"><span>AWS</span><span id="out-aws">0€</span></div><div class="progress mb-2" style="height:6px"><div id="pb-aws" class="progress-bar bg-secondary" style="width:100%"></div></div><div class="d-flex justify-content-between small text-muted"><span>Azure</span><span id="out-azure">0€</span></div><div class="progress mb-3" style="height:6px"><div id="pb-azure" class="progress-bar bg-secondary" style="width:100%"></div></div><div class="text-center mt-3"><span class="badge bg-success border border-success text-success bg-opacity-10 px-3 py-2">AHORRO: <span id="out-save">0%</span></span></div></div></div></div></div></section>

    <section id="pricing" class="py-5 bg-white"><div class="container"><div class="text-center mb-5"><h6 class="text-primary fw-bold">PLANES V21</h6><h2 class="fw-bold">Escala con Confianza</h2></div><div class="row g-4 justify-content-center">
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front"><div><h5 class="fw-bold text-muted">Bronce</h5><div class="display-4 fw-bold text-primary my-2">5€</div><ul class="list-unstyled small text-muted"><li>1 vCPU / 1 GB RAM</li><li>Alpine Only</li><li class="text-danger">Sin DB / Web</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Bronce')" class="btn btn-outline-dark w-100 rounded-pill btn-select">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-3">Especificaciones</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU/RAM:</span> <strong>1 Core / 1 GB</strong></li><li><span>OS:</span> <strong>Alpine (Fijo)</strong></li><li><span>SSH:</span> <strong>Puerto 22</strong></li><li><span>Extras:</span> <strong>Ninguno</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1">Ideal para:</p><p class="small text-muted">Bastion Host, VPN, Pruebas CLI.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Bronce')" class="btn btn-outline-warning w-100 rounded-pill btn-select">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front" style="border-color:#2563eb"><div><h5 class="fw-bold text-primary">Plata</h5><div class="display-4 fw-bold text-primary my-2">15€</div><ul class="list-unstyled small text-muted"><li>MySQL Cluster</li><li>2 vCPU / 2 GB RAM</li><li class="text-danger">Sin Web</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill btn-select">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-3">Backend DB</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU/RAM:</span> <strong>2 Cores / 2 GB</strong></li><li><span>OS:</span> <strong>Alpine / Ubuntu</strong></li><li><span>DB:</span> <strong>MySQL 8</strong></li><li><span>Storage:</span> <strong>Persistente</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1">Ideal para:</p><p class="small text-muted">Microservicios, Bases de datos internas.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill btn-select">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front" style="border-color:#f59e0b"><div><h5 class="fw-bold text-warning">Oro</h5><div class="display-4 fw-bold text-primary my-2">30€</div><ul class="list-unstyled small text-muted"><li>Full Stack</li><li>3 vCPU / 3 GB RAM</li><li>Dominio .cloud</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Oro')" class="btn btn-warning w-100 rounded-pill btn-select text-white">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-warning mb-3">Producción</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU/RAM:</span> <strong>3 Cores / 3 GB</strong></li><li><span>OS:</span> <strong>Alp/Ubu/RHEL</strong></li><li><span>Stack:</span> <strong>Nginx + MySQL</strong></li><li><span>Dom:</span> <strong>Incluido</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1">Ideal para:</p><p class="small text-muted">Apps en Producción, E-commerce.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Oro')" class="btn btn-warning w-100 rounded-pill btn-select text-white">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front card-custom"><div><h5 class="fw-bold text-primary">A Medida</h5><div class="display-4 fw-bold text-primary my-2">Flex</div><ul class="list-unstyled small text-muted"><li>Hardware Ryzen</li><li>Topología Mixta</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill btn-select">Configurar</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-3">Arquitectura</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU:</span> <strong>1-32 Cores</strong></li><li><span>RAM:</span> <strong>1-64 GB</strong></li><li><span>OS:</span> <strong>Cualquiera</strong></li><li><span>Red:</span> <strong>Custom CNI</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1">Ideal para:</p><p class="small text-muted">Big Data, IA, Proyectos complejos.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill btn-select">Configurar</button></div></div></div></div>
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
    <div class="modal fade" id="successModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 p-4" style="background:white; border-radius:15px;"><h2 class="text-success fw-bold mb-3">✅ ÉXITO</h2><div class="success-box"><span class="text-muted"># RESUMEN</span><br>Plan: <span id="s-plan" class="text-warning"></span><br>OS: <span id="s-os" class="text-info"></span><br>CPU: <span id="s-cpu"></span> vCore | RAM: <span id="s-ram"></span> GB<div class="mt-3 border-top border-secondary pt-2">CMD: <span id="s-cmd" class="text-white"></span><br>PASS: <span id="s-pass" class="text-white"></span></div></div><div class="text-center"><a href="../panel/dashboard.php" class="btn btn-primary rounded-pill px-5 fw-bold">IR A LA CONSOLA</a></div></div></div></div>
    <div class="modal fade" id="authModal"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content border-0 shadow-lg p-4"><ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-pill"><li class="nav-item"><a class="nav-link active rounded-pill" data-bs-toggle="tab" href="#login-pane">Login</a></li><li class="nav-item"><a class="nav-link rounded-pill" data-bs-toggle="tab" href="#reg-pane">Registro</a></li></ul><div class="tab-content"><div class="tab-pane fade show active" id="login-pane"><input id="log_email" class="form-control mb-3" placeholder="Usuario/Email"><input type="password" id="log_pass" class="form-control mb-3" placeholder="Contraseña"><button class="btn btn-primary w-100 rounded-pill fw-bold" onclick="handleLogin()">Entrar</button></div><div class="tab-pane fade" id="reg-pane"><div class="text-center mb-3"><div class="btn-group w-50"><input type="radio" class="btn-check" name="t_u" id="t_a" value="autonomo" checked onchange="toggleReg()"><label class="btn btn-outline-primary" for="t_a">Autónomo</label><input type="radio" class="btn-check" name="t_u" id="t_e" value="empresa" onchange="toggleReg()"><label class="btn btn-outline-primary" for="t_e">Empresa</label></div></div><div class="row g-2"><div class="col-6"><input id="reg_u" class="form-control mb-2" placeholder="Usuario"></div><div class="col-6"><input id="reg_e" class="form-control mb-2" placeholder="Email"></div><div class="col-6"><input type="password" id="reg_p1" class="form-control mb-2" placeholder="Contraseña"></div><div class="col-6"><input type="password" id="reg_p2" class="form-control mb-2" placeholder="Repetir"></div></div><div id="fields-auto" class="mt-2"><input id="reg_fn" class="form-control mb-2" placeholder="Nombre Completo"><input id="reg_dni_a" class="form-control mb-2" placeholder="DNI"></div><div id="fields-emp" class="mt-2" style="display:none"><div class="row g-2"><div class="col-6"><input id="reg_contact" class="form-control mb-2" placeholder="Persona Contacto"></div><div class="col-6"><input id="reg_cif" class="form-control mb-2" placeholder="CIF"></div></div><select id="reg_tipo_emp" class="form-select mb-2" onchange="checkOther()"><option value="SL">S.L.</option><option value="SA">S.A.</option><option value="Cooperativa">Cooperativa</option><option value="Otro">Otro</option></select><input id="reg_rs" class="form-control mb-2" placeholder="Razón Social" style="display:none"></div><div class="row g-2 mt-1"><div class="col-6"><input id="reg_tel" class="form-control mb-2" placeholder="Teléfono"></div><div class="col-6"><input id="reg_cal" class="form-control mb-2" placeholder="Dirección"></div></div><div class="form-check mt-3"><input type="checkbox" id="reg_terms" class="form-check-input"><label class="form-check-label small">Acepto los <a href="#" onclick="viewTermsFromReg()">Términos</a>.</label></div><button class="btn btn-success w-100 rounded-pill fw-bold mt-3" onclick="handleRegister()">Crear Cuenta</button></div></div></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>const isLogged = <?=isset($_SESSION['user_id'])?'true':'false'?>;</script>
    <script src="js/main.js"></script>
</body>
</html>
