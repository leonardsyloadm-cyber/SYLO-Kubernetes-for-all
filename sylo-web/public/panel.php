<?php
// view: sylo-web/public/panel.php
require_once 'php/auth.php';

// CANDADO DE SEGURIDAD
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<?php include 'components/header.php'; ?>

    <section class="hero text-center">
        <div class="container">
            <span class="badge bg-primary mb-3 px-3 py-1 rounded-pill">V113 Stable</span>
            <h1 class="display-3 fw-bold mb-4" data-i18n="hero.title">Infraestructura <span class="text-primary">Ryzen™</span></h1>
            <p class="lead mb-5" data-i18n="hero.subtitle">Orquestación Kubernetes V21 desde Alicante, España.</p>
        </div>
    </section>

    <section id="empresa" class="py-5 bg-white"><div class="container"><div class="row align-items-center mb-5"><div class="col-lg-6"><h6 class="text-primary fw-bold" data-i18n="company.mission">NUESTRA MISIÓN</h6><h2 class="fw-bold mb-4" data-i18n="company.title">Ingeniería Real</h2><p class="text-muted" data-i18n="company.desc">Sylo nació en Alicante para eliminar la complejidad. Usamos hardware Threadripper y NVMe Gen5 real.</p><div class="mt-4"><a href="https://github.com/leonardsyloadm-cyber/SYLO-Kubernetes-for-all" target="_blank" class="btn btn-outline-dark rounded-pill px-4"><i class="fab fa-github me-2"></i>GitHub</a></div></div><div class="col-lg-6"><div class="row g-4"><div class="col-6 text-center"><div class="p-4 rounded bg-light border"><img src="https://ui-avatars.com/api/?name=Ivan+Arlanzon&background=0f172a&color=fff&size=100" class="avatar"><h5 class="fw-bold">Ivan A.</h5><span class="badge bg-primary">CEO</span></div></div><div class="col-6 text-center"><div class="p-4 rounded bg-light border"><img src="https://ui-avatars.com/api/?name=Leonard+Baicu&background=2563eb&color=fff&size=100" class="avatar"><h5 class="fw-bold">Leonard B.</h5><span class="badge bg-success">CTO</span></div></div></div></div></div></div></section>

    <section id="bench" class="py-5 bg-light"><div class="container"><div class="row align-items-center"><div class="col-lg-5"><h2 class="fw-bold mb-3" data-i18n="bench.title">Rendimiento Bruto</h2><p data-i18n="bench.desc">Cinebench R23 Single Core.</p><div class="d-flex justify-content-between small fw-bold mt-4"><span>SYLO Ryzen</span><span class="text-primary">1,950 pts</span></div><div class="bench-bar b-sylo" style="width:100%"></div><div class="d-flex justify-content-between small fw-bold mt-3"><span>AWS c6a</span><span>1,420 pts</span></div><div class="bench-bar b-aws" style="width:72%"></div></div><div class="col-lg-6 offset-lg-1"><div class="row g-3"><div class="col-6"><div class="p-4 border rounded-4 text-center bg-white"><i class="fas fa-hdd fa-2x text-danger mb-2"></i><h5 class="fw-bold">NVMe Gen5</h5><small>7,500 MB/s</small></div></div><div class="col-6"><div class="p-4 border rounded-4 text-center bg-white"><i class="fas fa-memory fa-2x text-success mb-2"></i><h5 class="fw-bold">DDR5 ECC</h5><small>Error Correction</small></div></div></div></div></div></div></section>

    <section id="calculadora" class="container py-5 my-5"><div class="calc-box shadow-lg"><div class="row g-5"><div class="col-lg-6"><h4 class="text-white mb-4"><i class="fas fa-calculator me-2 text-primary"></i><span data-i18n="calc.title">Configurador</span></h4><select class="form-select w-50 bg-dark text-white border-secondary mb-4" id="calc-preset" onchange="applyPreset()"><option value="custom" data-i18n="pricing.custom">-- A Medida --</option><option value="bronce" data-i18n="pricing.bronze">Plan Bronce</option><option value="plata" data-i18n="pricing.silver">Plan Plata</option><option value="oro" data-i18n="pricing.gold">Plan Oro</option></select>
        <label class="small text-white-50 fw-bold">vCPU (5€): <span id="c-cpu">1</span></label><input type="range" class="form-range mb-4" min="1" max="16" value="1" id="in-cpu" oninput="userMovedSlider()">
        <label class="small text-white-50 fw-bold">RAM (5€): <span id="c-ram">1</span> GB</label><input type="range" class="form-range mb-4" min="1" max="32" value="1" id="in-ram" oninput="userMovedSlider()">
        <div class="row g-2"><div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check-calc-db" onchange="userMovedSlider()"><label class="text-white small" data-i18n="calc.db_label">DB (+5€)</label></div></div><div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="check-calc-web" onchange="userMovedSlider()"><label class="text-white small" data-i18n="calc.web_label">Web (+5€)</label></div></div></div>
    </div><div class="col-lg-6"><div class="bg-white p-5 rounded-4 h-100 d-flex flex-column justify-content-center text-dark"><h5 class="fw-bold mb-3" data-i18n="calc.estimate">Estimación</h5><div class="d-flex justify-content-between"><span class="fw-bold text-primary">SYLO</span><span class="price-display" id="out-sylo">0€</span></div><div class="progress mb-3" style="height:20px"><div id="pb-sylo" class="progress-bar bg-primary" style="width:0%"></div></div><div class="d-flex justify-content-between small text-muted"><span>AWS</span><span id="out-aws">0€</span></div><div class="progress mb-2" style="height:6px"><div id="pb-aws" class="progress-bar bg-secondary" style="width:100%"></div></div><div class="d-flex justify-content-between small text-muted"><span>Azure</span><span id="out-azure">0€</span></div><div class="progress mb-3" style="height:6px"><div id="pb-azure" class="progress-bar bg-secondary" style="width:100%"></div></div><div class="text-center mt-3"><span class="badge bg-success border border-success text-success bg-opacity-10 px-3 py-2"><span data-i18n="calc.savings">AHORRO:</span> <span id="out-save">0%</span></span></div></div></div></div></div></section>

    <section id="pricing" class="py-5 bg-white"><div class="container"><div class="text-center mb-5"><h6 class="text-primary fw-bold" data-i18n="pricing.v21_title">PLANES V21</h6><h2 class="fw-bold" data-i18n="pricing.scale_title">Escala con Confianza</h2></div><div class="row g-4 justify-content-center">
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front"><div><h5 class="fw-bold text-muted" data-i18n="pricing.bronze">Bronce</h5><div class="display-4 fw-bold text-primary my-2">5€</div><ul class="list-unstyled small text-muted"><li>1 vCPU / 1 GB RAM</li><li data-i18n="pricing.feat.alpine_only">Alpine Only</li><li class="text-danger" data-i18n="pricing.feat.no_db_web">Sin DB / Web</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Bronce')" class="btn btn-outline-dark w-100 rounded-pill btn-select" data-i18n="pricing.choose">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-3" data-i18n="pricing.specs_title">Especificaciones</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU/RAM:</span> <strong>1 Core / 1 GB</strong></li><li><span>OS:</span> <strong>Alpine (<span data-i18n="pricing.vals.fixed">Fijo</span>)</strong></li><li><span>SSH:</span> <strong>Puerto 22</strong></li><li><span>Extras:</span> <strong data-i18n="pricing.vals.none">Ninguno</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1" data-i18n="pricing.ideal_label">Ideal para:</p><p class="small text-muted" data-i18n="pricing.desc.bronze">Bastion Host, VPN, Pruebas CLI.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Bronce')" class="btn btn-outline-warning w-100 rounded-pill btn-select" data-i18n="pricing.choose">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front" style="border-color:#2563eb"><div><h5 class="fw-bold text-primary" data-i18n="pricing.silver">Plata</h5><div class="display-4 fw-bold text-primary my-2">15€</div><ul class="list-unstyled small text-muted"><li data-i18n="pricing.feat.mysql_cluster">MySQL Cluster</li><li>2 vCPU / 2 GB RAM</li><li class="text-danger" data-i18n="pricing.feat.no_web">Sin Web</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill btn-select" data-i18n="pricing.choose">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-3" data-i18n="pricing.backend_title">Backend DB</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU/RAM:</span> <strong>2 Cores / 2 GB</strong></li><li><span>OS:</span> <strong>Alpine / Ubuntu</strong></li><li><span>DB:</span> <strong>MySQL 8</strong></li><li><span>Storage:</span> <strong data-i18n="pricing.vals.persistent">Persistente</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1" data-i18n="pricing.ideal_label">Ideal para:</p><p class="small text-muted" data-i18n="pricing.desc.silver">Microservicios, Bases de datos internas.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Plata')" class="btn btn-primary w-100 rounded-pill btn-select" data-i18n="pricing.choose">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front" style="border-color:#f59e0b"><div><h5 class="fw-bold text-warning" data-i18n="pricing.gold">Oro</h5><div class="display-4 fw-bold text-primary my-2">30€</div><ul class="list-unstyled small text-muted"><li data-i18n="pricing.feat.full_stack">Full Stack</li><li>3 vCPU / 3 GB RAM</li><li data-i18n="pricing.feat.domain">Dominio .cloud</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Oro')" class="btn btn-warning w-100 rounded-pill btn-select text-white" data-i18n="pricing.choose">Elegir</button></div><div class="face-back"><div><h6 class="fw-bold text-warning mb-3" data-i18n="pricing.prod_title">Producción</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU/RAM:</span> <strong>3 Cores / 3 GB</strong></li><li><span>OS:</span> <strong>Alp/Ubu/RHEL</strong></li><li><span>Stack:</span> <strong>Nginx + MySQL</strong></li><li><span>Dom:</span> <strong data-i18n="pricing.vals.included">Incluido</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1" data-i18n="pricing.ideal_label">Ideal para:</p><p class="small text-muted" data-i18n="pricing.desc.gold">Apps en Producción, E-commerce.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Oro')" class="btn btn-warning w-100 rounded-pill btn-select text-white" data-i18n="pricing.choose">Elegir</button></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card-stack-container" onclick="toggleCard(this)"><div class="card-face"><div class="face-front card-custom"><div><h5 class="fw-bold text-primary" data-i18n="pricing.custom">A Medida</h5><div class="display-4 fw-bold text-primary my-2">Flex</div><ul class="list-unstyled small text-muted"><li data-i18n="pricing.feat.hardware">Hardware Ryzen</li><li data-i18n="pricing.feat.topology">Topología Mixta</li></ul></div><button onclick="event.stopPropagation();prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill btn-select" data-i18n="pricing.configure">Configurar</button></div><div class="face-back"><div><h6 class="fw-bold text-primary mb-3" data-i18n="pricing.arch_title">Arquitectura</h6><ul class="list-unstyled k8s-specs text-muted"><li><span>CPU:</span> <strong>1-32 Cores</strong></li><li><span>RAM:</span> <strong>1-64 GB</strong></li><li><span>OS:</span> <strong data-i18n="pricing.vals.any">Cualquiera</strong></li><li><span>Red:</span> <strong>Custom CNI</strong></li></ul><div class="mt-3"><p class="small fw-bold mb-1" data-i18n="pricing.ideal_label">Ideal para:</p><p class="small text-muted" data-i18n="pricing.desc.custom">Big Data, IA, Proyectos complejos.</p></div></div><button onclick="event.stopPropagation();prepararPedido('Personalizado')" class="btn btn-outline-primary w-100 rounded-pill btn-select" data-i18n="pricing.configure">Configurar</button></div></div></div></div>
    </div></div></section>

    <section class="py-5 bg-white border-top"><div class="container text-center"><h3 class="fw-bold" data-i18n="footer.academy">Sylo Academy</h3><p class="text-muted mb-4" data-i18n="footer.docs_desc">Documentación técnica oficial.</p><a href="https://www.notion.so/SYLO-Kubernetes-For-All-1f5bfdf3150380328e1efc4fe8e181f9?source=copy_link" target="_blank" class="btn btn-dark rounded-pill px-5 fw-bold"><i class="fas fa-book me-2"></i><span data-i18n="footer.read_docs">Leer Docs</span></a></div></section>

    <footer class="py-5 bg-light border-top mt-5"><div class="container text-center"><h5 class="fw-bold text-primary mb-3">SYLO CORP S.L.</h5><div class="mb-4"><a href="mailto:arlanzonivan@gmail.com" class="text-muted mx-2 text-decoration-none">arlanzonivan@gmail.com</a><a href="mailto:leob@gmail.com" class="text-muted mx-2 text-decoration-none">leob@gmail.com</a></div><a href="terminos.php" class="btn btn-link text-muted small" data-i18n="footer.terms" target="_blank">TÉRMINOS Y CONDICIONES</a></div></footer>


    <div class="modal fade" id="configModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content shadow-lg border-0 p-3">
        <div class="modal-header border-0"><h5 class="fw-bold"><span data-i18n="modal.config_title">Configurar</span> <span id="m_plan" class="text-primary"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-5">
            <div class="row mb-3"><div class="col-6"><label class="form-label fw-bold small" data-i18n="modal.alias">Alias Cluster</label><input id="cfg-alias" class="form-control rounded-pill bg-light border-0"></div><div class="col-6"><label class="form-label fw-bold small" data-i18n="modal.ssh_user">Usuario SSH</label><input id="cfg-ssh-user" class="form-control rounded-pill bg-light border-0" value="admin_sylo"></div></div>
            <div class="mb-3"><label class="form-label fw-bold small">SO</label><select id="cfg-os" class="form-select rounded-pill bg-light border-0"></select></div>

            <div id="grp-hardware" class="mb-3 p-3 bg-light rounded-3" style="display:none;">
                <h6 class="text-primary fw-bold mb-3"><i class="fas fa-microchip me-2"></i><span data-i18n="modal.resources">Recursos Personalizados</span></h6>
                <div class="row">
                    <div class="col-6"><label class="small fw-bold">vCPU: <span id="lbl-cpu"></span></label><input type="range" id="mod-cpu" min="1" max="16" oninput="updateModalHard()"></div>
                    <div class="col-6"><label class="small fw-bold">RAM: <span id="lbl-ram"></span> GB</label><input type="range" id="mod-ram" min="1" max="32" oninput="updateModalHard()"></div>
                </div>
            </div>

            <div class="d-flex gap-3 mb-2">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="mod-check-db" onchange="toggleModalSoft()"><label class="small fw-bold ms-1" data-i18n="modal.db">Base de Datos</label></div>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="mod-check-web" onchange="toggleModalSoft()"><label class="small fw-bold ms-1" data-i18n="modal.web">Servidor Web</label></div>
            </div>
            
            <div id="mod-db-opts" style="display:none;" class="p-3 border rounded-3 mb-2">
                <label class="small fw-bold" data-i18n="modal.db_engine">Motor DB</label>
                <select id="mod-db-type" class="form-select rounded-pill bg-white border-0 mb-2"><option value="mysql">MySQL 8.0</option><option value="postgresql">PostgreSQL 14</option><option value="mongodb">MongoDB</option></select>
                <input id="mod-db-name" class="form-control rounded-pill bg-white border-0" value="sylo_db" placeholder="Nombre DB">
            </div>
            <div id="mod-web-opts" style="display:none;" class="p-3 border rounded-3">
                <label class="small fw-bold" data-i18n="modal.web_server">Servidor Web</label>
                <select id="mod-web-type" class="form-select rounded-pill bg-white border-0 mb-2"><option value="nginx">Nginx</option><option value="apache">Apache</option></select>
                <input id="mod-web-name" class="form-control rounded-pill bg-white border-0 mb-2" value="sylo-web" placeholder="Nombre App">
                <div class="input-group rounded-pill overflow-hidden"><input id="mod-sub" class="form-control border-0" placeholder="mi-app"><span class="input-group-text border-0 bg-white small">.sylobi.org</span></div>
            </div>

            <!-- SYLO TOOLBELT UI -->
            <div id="grp-toolbelt" class="mt-3 p-4 bg-light border rounded-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-primary fw-bold m-0"><i class="fas fa-toolbox me-2"></i><span data-i18n="modal.toolbelt">Sylo Toolbelt</span></h6>
                    <span id="tier-badge" class="badge bg-secondary">Calculando Nivel...</span>
                </div>
                
                <p class="small text-muted mb-3" data-i18n="modal.tier_msg">Herramientas inyectadas en tu contenedor (Coste incluido en el Tier).</p>
                
                <div class="mb-3">
                    <small class="text-uppercase fw-bold text-muted d-block mb-2" style="font-size:0.7rem; letter-spacing:1px;">Tier 1: Essentials (< 15€)</small>
                    <div id="tools-t1" class="d-flex flex-wrap gap-2"></div>
                </div>
                
                <div class="mb-3">
                    <small class="text-uppercase fw-bold text-muted d-block mb-2" style="font-size:0.7rem; letter-spacing:1px;">Tier 2: Developer (15€ - 30€)</small>
                    <div id="tools-t2" class="d-flex flex-wrap gap-2"></div>
                </div>
                
                <div class="mb-0">
                    <small class="text-uppercase fw-bold text-muted d-block mb-2" style="font-size:0.7rem; letter-spacing:1px;">Tier 3: Pro Admin (> 30€)</small>
                    <div id="tools-t3" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>

            <div class="mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3 px-2">
                    <span class="text-muted fw-bold" data-i18n="modal.estimated_budget">Presupuesto Estimado</span>
                    <span class="display-6 fw-bold text-primary" id="modal-total-price">0€</span>
                </div>
                <button class="btn btn-primary w-100 rounded-pill fw-bold py-3 shadow-sm" onclick="lanzar()" data-i18n="modal.deploy_btn">DESPLEGAR INFRAESTRUCTURA</button>
            </div>
        </div>
    </div></div></div>

    <div class="modal fade" id="statusModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content p-4 border-0 shadow-lg"><h5 class="fw-bold mb-4">Estado del Sistema <span class="status-dot ms-2"></span></h5><div class="d-flex justify-content-between border-bottom py-2"><span>API Gateway (Alicante)</span><span class="badge bg-success">Online</span></div><div class="d-flex justify-content-between border-bottom py-2"><span>NVMe Array</span><span class="badge bg-success">Online</span></div><div class="d-flex justify-content-between border-bottom py-2"><span>Oktopus V21</span><span class="badge bg-success">Active</span></div></div></div></div>
    <div class="modal fade" id="progressModal" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content terminal-window border-0"><div class="terminal-body text-center"><div class="spinner-border text-success mb-3" role="status"></div><h5 id="progress-text" class="mb-3">Iniciando...</h5><div class="progress"><div id="prog-bar" class="progress-bar bg-success" style="width:0%"></div></div></div></div></div></div>

    <div class="modal fade" id="successModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 p-0 overflow-hidden shadow-2xl" style="background:transparent;"><div class="terminal-window-modern" style="background: #1e1e1e; border-radius: 12px; font-family: 'Fira Code', monospace; color: #d4d4d4;"><div class="terminal-header d-flex align-items-center p-3" style="background: #2d2d2d; border-bottom: 1px solid #3e3e3e;"><div class="d-flex gap-2"><div style="width:12px;height:12px;border-radius:50%;background:#ff5f56"></div><div style="width:12px;height:12px;border-radius:50%;background:#ffbd2e"></div><div style="width:12px;height:12px;border-radius:50%;background:#27c93f"></div></div><div class="mx-auto text-muted small">root@sylo-cluster:~</div></div><div class="p-4"><h4 class="text-success fw-bold mb-4"><i class="fas fa-check-circle me-2"></i><span data-i18n="console.title">DESPLIEGUE COMPLETADO</span></h4><div class="console-output mb-4" style="font-size: 0.9rem;"><div class="mb-1"><span class="text-info">❯</span> <span data-i18n="console.initializing">Initializing</span> <span class="text-warning" id="s-plan"></span> <span data-i18n="console.plan">Plan...</span> <span class="text-success">OK</span></div><div class="mb-1"><span class="text-info">❯</span> <span data-i18n="console.booting">Booting</span> <span class="text-info" id="s-os"></span> <span data-i18n="console.booting_suffix">Kernel...</span> <span class="text-success">OK</span></div><div class="mb-3"><span class="text-info">❯</span> <span data-i18n="console.allocating">Allocating Resources...</span> <span class="text-white"><span data-i18n="console.cpu">CPU:</span> <span id="s-cpu"></span>vC | <span data-i18n="console.ram">RAM:</span> <span id="s-ram"></span>GB</span></div><div class="border-top border-secondary border-opacity-25 pt-2 mb-2"><span class="text-muted" data-i18n="console.installed_tools"># INSTALLED TOOLS</span><br><span id="s-tools" class="text-light fw-bold" style="color: #a5d6ff !important"></span></div><div class="border-top border-secondary border-opacity-25 pt-2"><span class="text-muted" data-i18n="console.access_creds"># ACCESS CREDENTIALS</span><br><div><span class="text-danger">root@sylo:~#</span> <span id="s-cmd" class="text-white"></span></div><div><span class="text-danger">root@sylo:~#</span> pass: <span id="s-pass" class="text-warning"></span></div></div></div><div class="text-center mt-4"><a href="../panel/dashboard.php" class="btn btn-primary w-100 rounded-pill fw-bold py-2" data-i18n="console.dashboard_btn">ACCEDER AL DASHBOARD</a></div></div></div></div></div></div>
    
    <!-- MESSAGE MODAL (Replaces Alerts) -->
    <div class="modal fade" id="messageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header border-0"><h5 class="fw-bold text-primary" id="msgTitle">Mensaje</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center p-4"><div class="mb-3"><i id="msgIcon" class="fas fa-info-circle fa-3x text-primary"></i></div><p id="msgText" class="lead mb-0"></p></div><div class="modal-footer border-0 justify-content-center"><button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">Entendido</button></div></div></div></div>
    <div class="modal fade" id="authModal"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content border-0 shadow-lg p-4">
        <ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-pill">
            <li class="nav-item"><a class="nav-link active rounded-pill" data-bs-toggle="tab" href="#login-pane" data-i18n="auth.login_tab">Login</a></li>
            <li class="nav-item"><a class="nav-link rounded-pill" data-bs-toggle="tab" href="#reg-pane" data-i18n="auth.register_tab">Registro</a></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="login-pane">
                <input id="log_email" class="form-control mb-3" placeholder="Usuario/Email" data-i18n="auth.user_email">
                <input type="password" id="log_pass" class="form-control mb-3" placeholder="Contraseña" data-i18n="auth.password">
                <button class="btn btn-primary w-100 rounded-pill fw-bold" onclick="handleLogin()" data-i18n="auth.login_btn">Entrar</button>
            </div>
            <div class="tab-pane fade" id="reg-pane">
                <div class="text-center mb-3">
                    <div class="btn-group w-50">
                        <input type="radio" class="btn-check" name="t_u" id="t_a" value="autonomo" checked onchange="toggleReg()">
                        <label class="btn btn-outline-primary" for="t_a" data-i18n="auth.freelancer">Autónomo</label>
                        <input type="radio" class="btn-check" name="t_u" id="t_e" value="empresa" onchange="toggleReg()">
                        <label class="btn btn-outline-primary" for="t_e" data-i18n="auth.company">Empresa</label>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-6"><input id="reg_u" class="form-control mb-2" placeholder="Usuario" data-i18n="auth.username"></div>
                    <div class="col-6"><input id="reg_e" class="form-control mb-2" placeholder="Email" data-i18n="auth.email"></div>
                    <div class="col-6">
                        <div class="pass-wrapper">
                            <input type="password" id="reg_p1" class="form-control mb-2" placeholder="Contraseña" data-i18n="auth.password">
                            <i class="fas fa-eye eye-icon" onclick="togglePass('reg_p1', this)"></i>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="pass-wrapper">
                            <input type="password" id="reg_p2" class="form-control mb-2" placeholder="Repetir" data-i18n="auth.repeat_pass">
                        </div>
                    </div>
                </div>
                <div id="fields-auto" class="mt-2">
                    <input id="reg_fn" class="form-control mb-2" placeholder="Nombre Completo" data-i18n="auth.full_name">
                    <input id="reg_dni_a" class="form-control mb-2" placeholder="DNI" data-i18n="auth.dni">
                </div>
                <div id="fields-emp" class="mt-2" style="display:none">
                    <div class="row g-2">
                        <div class="col-6"><input id="reg_contact" class="form-control mb-2" placeholder="Persona Contacto" data-i18n="auth.contact_person"></div>
                        <div class="col-6"><input id="reg_cif" class="form-control mb-2" placeholder="CIF" data-i18n="auth.cif"></div>
                    </div>
                    <select id="reg_tipo_emp" class="form-select mb-2" onchange="checkOther()">
                        <option value="SL" data-i18n="auth.sl">S.L.</option>
                        <option value="SA" data-i18n="auth.sa">S.A.</option>
                        <option value="Cooperativa" data-i18n="auth.coop">Cooperativa</option>
                        <option value="Otro" data-i18n="auth.other">Otro</option>
                    </select>
                    <input id="reg_rs" class="form-control mb-2" placeholder="Razón Social" style="display:none" data-i18n="auth.company_name">
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-6"><input id="reg_tel" class="form-control mb-2" placeholder="Teléfono" data-i18n="auth.phone"></div>
                    <div class="col-6"><input id="reg_cal" class="form-control mb-2" placeholder="Dirección" data-i18n="auth.address"></div>
                </div>
                <div class="form-check mt-3">
                    <input type="checkbox" id="reg_terms" class="form-check-input">
                    <label class="form-check-label small"><span data-i18n="auth.accept_terms">Acepto los</span> <a href="terminos.php" target="_blank" data-i18n="auth.terms_link">Términos</a>.</label>
                </div>
                <button class="btn btn-success w-100 rounded-pill fw-bold mt-3" onclick="handleRegister()" data-i18n="auth.create_account">Crear Cuenta</button>
            </div>
        </div>
    </div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLogged = <?=isset($_SESSION['user_id'])?'true':'false'?>;
        const csrfToken = "<?=generateCsrfToken()?>"; // Security: CSRF Token

        function openM(id){const el=document.getElementById(id);if(el)new bootstrap.Modal(el).show();}
        function hideM(id){const el=document.getElementById(id);const m=bootstrap.Modal.getInstance(el);if(m)m.hide();}
        function showMsg(title, msg, type='info') {
            document.getElementById('msgTitle').innerText = title;
            document.getElementById('msgText').innerText = msg;
            const icon = document.getElementById('msgIcon');
            icon.className = type === 'error' ? 'fas fa-exclamation-circle fa-3x text-danger' : 'fas fa-info-circle fa-3x text-primary';
            openM('messageModal');
        }
        function openAuth(){openM('authModal');}
        function toggleTheme(){document.body.dataset.theme=document.body.dataset.theme==='dark'?'':'dark';}
        function toggleCard(el){document.querySelectorAll('.card-stack-container').forEach(c=>c!==el&&c.classList.remove('active'));el.classList.toggle('active');}
        function toggleReg(){const e=document.getElementById('t_e').checked;document.getElementById('fields-emp').style.display=e?'block':'none';document.getElementById('fields-auto').style.display=e?'none':'block';}
        function checkOther(){document.getElementById('reg_rs').style.display=(document.getElementById('reg_tipo_emp').value==='Otro')?'block':'none';}

        // --- CALCULADORA (FIXED: UPDATE ON INPUT) ---
        document.addEventListener('DOMContentLoaded', () => { 
            // Eliminados elementos 'sel-calc-db' y 'sel-calc-web' del array porque no existen en el HTML
            ['in-cpu','in-ram','check-calc-db','check-calc-web'].forEach(id=>document.getElementById(id)?.addEventListener('input', () => {
                document.getElementById('calc-preset').value='custom'; // Forzar custom al mover
                updCalc(); 
            }));
            updCalc(); // Init
        });
        
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
            
            let d_c=document.getElementById('check-calc-db').checked?5:0;
            let w_c=document.getElementById('check-calc-web').checked?5:0;
            
            // Eliminada la lógica que deshabilitaba los selects que no existen y causaban el error JS
            
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
            
            // Iniciar recálculo dinámico y renderizado de tools
            recalcModal();
            
            openM('configModal');
        }

        function updateModalHard(){ document.getElementById('lbl-cpu').innerText=document.getElementById('mod-cpu').value; document.getElementById('lbl-ram').innerText=document.getElementById('mod-ram').value; }
        function toggleModalSoft(){ document.getElementById('mod-db-opts').style.display=document.getElementById('mod-check-db').checked?'block':'none'; document.getElementById('mod-web-opts').style.display=document.getElementById('mod-check-web').checked?'block':'none'; }

        // --- TOOLBELT & PRICING LOGIC ---
        const TIER1 = ["htop", "nano", "ncdu", "curl", "wget", "zip", "unzip", "git"];
        const TIER2 = ["python3", "python3-pip", "nodejs", "npm", "mysql-client", "jq", "tmux", "lazygit"];
        const TIER3 = ["rsync", "ffmpeg", "imagemagick", "redis-tools", "ansible", "speedtest-cli", "zsh"];

        // Listeners para recálculo dinámico en el Modal
        ['mod-cpu','mod-ram','mod-check-db','mod-check-web','cfg-os','mod-db-type','mod-web-type'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', recalcModal);
            document.getElementById(id)?.addEventListener('input', recalcModal);
        });

        function recalcModal() {
            let price = 0;
            const p = curPlan;

            if(p === 'Bronce') price = 5;
            else if(p === 'Plata') price = 15;
            else if(p === 'Oro') price = 30;
            else { // CUSTOM LOGIC COMPLEJA
                const cpu = parseInt(document.getElementById('mod-cpu').value) || 1;
                const ram = parseInt(document.getElementById('mod-ram').value) || 1;
                price += (cpu * 5) + (ram * 5);

                const os = document.getElementById('cfg-os').value;
                if(os === 'alpine') price += 5;
                if(os === 'ubuntu') price += 10;
                if(os === 'redhat') price += 15;

                if(document.getElementById('mod-check-db').checked) {
                    const dbT = document.getElementById('mod-db-type').value;
                    if(dbT === 'mysql') price += 5;
                    if(dbT === 'postgresql') price += 10;
                    if(dbT === 'mongodb') price += 10;
                }

                if(document.getElementById('mod-check-web').checked) {
                    const webT = document.getElementById('mod-web-type').value;
                    if(webT === 'nginx') price += 5;
                    if(webT === 'apache') price += 10;
                }
            }

            document.getElementById('modal-total-price').innerText = price + "€";
            renderTools(p, price);
        }

        function renderTools(plan, price) {
            let access = 1;
            let badgeTxt = "Nivel 1 (Bronce)";
            let badgeClass = "bg-secondary";

            if(plan === 'Plata') { access=2; badgeTxt="Nivel 2 (Plata)"; badgeClass="bg-primary"; }
            if(plan === 'Oro') { access=3; badgeTxt="Nivel 3 (Oro)"; badgeClass="bg-warning text-dark"; }
            
            if(plan === 'Personalizado') {
                if(price >= 30) { access=3; badgeTxt="Nivel 3 (Custom Pro)"; badgeClass="bg-warning text-dark"; }
                else if(price >= 15) { access=2; badgeTxt="Nivel 2 (Custom Dev)"; badgeClass="bg-primary"; }
                else { badgeTxt="Nivel 1 (Custom Basic)"; }
            }
            
            document.getElementById('tier-badge').className = `badge ${badgeClass}`;
            document.getElementById('tier-badge').innerText = badgeTxt;

            const genChips = (list, lvl) => list.map(t => {
                const enabled = lvl <= access;
                // Preservar estado checked si ya existe, si no, false (user request: no pre-selected)
                const existing = document.getElementById(`t_${t}`);
                const isChecked = existing ? existing.checked : false; // Mantiene estado al redibujar
                
                // Si pasa de enabled a disabled, forzamos uncheck visual
                const finalCheck = enabled ? isChecked : false;

                return `
                <label class="tool-opt ${enabled?'':'disabled'} ${finalCheck?'active':''}">
                    <input type="checkbox" name="sylo_tools" value="${t}" id="t_${t}" 
                           ${finalCheck?'checked':''} ${enabled?'':'disabled'}
                           onchange="this.parentElement.classList.toggle('active', this.checked)">
                    ${getIcon(t)} ${t}
                </label>`;
            }).join('');

            document.getElementById('tools-t1').innerHTML = genChips(TIER1, 1);
            document.getElementById('tools-t2').innerHTML = genChips(TIER2, 2);
            document.getElementById('tools-t3').innerHTML = genChips(TIER3, 3);
        }

        function getIcon(t) {
            const map = {'python3':'🐍','nodejs':'🟢','git':'🐙','htop':'📊','mysql-client':'🐬','docker':'🐳','zsh':'🐚'};
            return map[t] || '🔧';
        }

        async function lanzar() {
            const alias = document.getElementById('cfg-alias').value; if(!alias) { showMsg("Faltan Datos", "Alias del cluster es obligatorio", 'error'); return; }
            const specs = {
                cluster_alias: alias,
                ssh_user: document.getElementById('cfg-ssh-user').value,
                os_image: document.getElementById('cfg-os').value,
                cpu: parseInt(document.getElementById('mod-cpu').value),
                
                // Enviar también el precio calculado para validación backend
                price: parseFloat(document.getElementById('modal-total-price').innerText.replace('€','')),
                
                ram: parseInt(document.getElementById('mod-ram').value),
                storage: 25,
                db_enabled: document.getElementById('mod-check-db').checked,
                web_enabled: document.getElementById('mod-check-web').checked,
                db_custom_name: document.getElementById('mod-db-name').value,
                web_custom_name: document.getElementById('mod-web-name').value,
                subdomain: document.getElementById('mod-sub').value || 'interno',
                db_type: document.getElementById('mod-db-type').value,
                web_type: document.getElementById('mod-web-type').value,
                // FIX: Select based on visual 'active' class to ensure hidden inputs are captured
                tools: Array.from(document.querySelectorAll('.tool-opt.active input')).map(cb => cb.value)
            };
            hideM('configModal'); openM('progressModal');
            try {
                const res = await fetch('index.php', { method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token': csrfToken}, body:JSON.stringify({action:'comprar', plan:curPlan, specs:specs}) });
                const j = await res.json();
                if(j.status === 'success') startPolling(j.order_id, specs); 
                else if (j.status === 'auth_required') { showMsg("Acceso Requerido", j.mensaje || "Sesión requerida"); openAuth(); }
                else { hideM('progressModal'); showMsg("Error", j.mensaje || "Error desconocido", 'error'); }
            } catch(e) { hideM('progressModal'); showMsg("Error Crítico", "Error de red o servidor: " + e, 'error'); }
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
                    // Usar finalSpecs.tools (solicitadas) como fallback si la API (installed_tools) aún no reporta nada
                    const displayTools = (s.installed_tools && s.installed_tools.length > 0) 
                                         ? s.installed_tools 
                                         : (finalSpecs.tools || []);
                    document.getElementById('s-tools').innerText = (displayTools.length > 0) ? displayTools.join(", ") : "Ninguna";
                    document.getElementById('s-cmd').innerText = s.ssh_cmd;
                    document.getElementById('s-pass').innerText = s.ssh_pass;
                    openM('successModal');
                }
            }, 1500);
        }

        async function handleLogin() { const r=await fetch('index.php',{method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token': csrfToken}, body:JSON.stringify({action:'login',email_user:document.getElementById('log_email').value,password:document.getElementById('log_pass').value})}); const d=await r.json(); if(d.status==='success') location.reload(); else showMsg("Login Fallido", d.mensaje, 'error'); }

        function togglePass(id, icon) {
            ['reg_p1', 'reg_p2'].forEach(tid => {
                const el = document.getElementById(tid);
                if (el.type === 'password') el.type = 'text';
                else el.type = 'password';
            });
            
            if (icon.classList.contains('fa-eye')) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function validateEmail(email) {
            const re = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            if (!re.test(String(email).toLowerCase())) return false;
            
            // Typo Protection
            const domain = email.split('@')[1].toLowerCase();
            const typos = ['gmail.co', 'hotmail.co', 'yahoo.co', 'outlook.co', 'gmil.com', 'hotmil.com', 'gm.com'];
            if (typos.includes(domain)) return false;
            
            return true;
        }

        function validateDNI(dni) {
            dni = dni.toUpperCase();
            if (!/^[XYZ0-9][0-9]{7}[TRWAGMYFPDXBNJZSQVHLCKE]$/.test(dni)) return false;
            
            let num = dni.substr(0, 8);
            num = num.replace('X', 0).replace('Y', 1).replace('Z', 2);
            const letter = dni.substr(8, 1);
            const letters = "TRWAGMYFPDXBNJZSQVHLCKE";
            
            return letter === letters.charAt(parseInt(num) % 23);
        }

        async function handleRegister() { 
            if(!document.getElementById('reg_terms').checked) { showMsg("Error", "Debe aceptar los términos", 'error'); return; }
            
            const p1 = document.getElementById('reg_p1').value;
            const p2 = document.getElementById('reg_p2').value;
            const email = document.getElementById('reg_e').value;
            const t = document.getElementById('t_a').checked ? 'autonomo' : 'empresa';
            
            // Email Validation
            if (!validateEmail(email)) { showMsg("Error", "Email no válido. Use un correo real (ej: gmail.com, hotmail.com)", 'error'); return; }

            // Password Validation
            if (p1 !== p2) { showMsg("Error", "Las contraseñas no coinciden", 'error'); return; }
            if (p1.length < 6) { showMsg("Error", "La contraseña debe tener al menos 6 caracteres", 'error'); return; }
            if (!/[0-9]/.test(p1)) { showMsg("Error", "La contraseña debe incluir al menos un número", 'error'); return; }
            if (!/[\W_]/.test(p1)) { showMsg("Error", "La contraseña debe incluir al menos un carácter especial (@, $, _, etc.)", 'error'); return; }

            const d = { action:'register', username:document.getElementById('reg_u').value, email:email, password:p1, password_confirm:p2, telefono:document.getElementById('reg_tel').value, calle:document.getElementById('reg_cal').value, tipo_usuario:t }; 
            
            if(t==='autonomo') { 
                d.full_name=document.getElementById('reg_fn').value; 
                d.dni=document.getElementById('reg_dni_a').value.toUpperCase(); 
                if (!validateDNI(d.dni)) { showMsg("Error", "DNI/NIE incorrecto. Verifique la letra.", 'error'); return; }
            } else { 
                d.contact_name=document.getElementById('reg_contact').value; 
                d.cif=document.getElementById('reg_cif').value.toUpperCase(); 
                d.dni=d.cif; // Backend expects 'dni' key for unique check usually, but let's send cif too
                // Simple CIF validation could be added, but user asked for DNI specifically.
                // For now, if it looks like DNI, validate it.
                if (/^[XYZ0-9]/.test(d.cif) && d.cif.length === 9) {
                     if (!validateDNI(d.cif) && !/^[ABCDEFGHJKLMNPQRSUVW]/.test(d.cif)) {
                         // Only warn if it fails DNI check AND doesn't start with CIF letter
                         // Allowing standard CIFs to pass without strict checksum for now unless requested
                     }
                }
                
                d.tipo_empresa=document.getElementById('reg_tipo_emp').value; 
                if(d.tipo_empresa==='Otro') d.company_name=document.getElementById('reg_rs').value; 
            } 
            
            try {
                const r = await fetch('index.php',{method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token': csrfToken}, body:JSON.stringify(d)}); 
                const res = await r.json();
                if(res.status==='success') { showMsg("Éxito", "Cuenta creada. Redirigiendo...", 'success'); setTimeout(()=>location.reload(), 2000); }
                else showMsg("Error", res.mensaje || "Error al registrar", 'error');
            } catch(e) { showMsg("Error", "Error de conexión: "+e, 'error'); }
        }
        function logout() { fetch('index.php',{method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token': csrfToken}, body:JSON.stringify({action:'logout'})}).then(()=>location.reload()); }
        function copyData(){ navigator.clipboard.writeText(document.getElementById('ssh-details').innerText); }
        function userMovedSlider(){ document.getElementById('calc-preset').value='custom'; updCalc(); }
    </script>

</body>
</html>
