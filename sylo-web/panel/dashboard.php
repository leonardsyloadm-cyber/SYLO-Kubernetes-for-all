<?php
// view: sylo-web/panel/dashboard.php
require_once 'php/data.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Panel SYLO | <?php echo htmlspecialchars($user_info['full_name'] ?: $_SESSION['username']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.12/ace.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<!-- ... existing code ... -->

<div class="sidebar">
    <div class="brand"><i class="bi bi-cpu-fill text-primary me-2"></i><strong>SYLO</strong>_OS</div>
    <div class="d-flex flex-column gap-1 p-2">
        <a href="../public/index.php" class="nav-link"><i class="bi bi-plus-lg me-3"></i> <span data-i18n="dashboard.new_service">Nuevo Servicio</span></a>
        <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#billingModal"><i class="bi bi-credit-card me-3"></i> <span data-i18n="dashboard.billing">Facturación</span></a>
        <div class="mt-4 px-4 mb-2 text-light-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 1px; opacity: 0.6;" data-i18n="dashboard.my_clusters">MIS CLÚSTERES</div>
        <?php foreach($clusters as $c): 
            $cls = ($current && $c['id']==$current['id'])?'active':'';
            $pstyle = getSidebarStyle($c['plan_name']); 
        ?>
            <a href="?id=<?=$c['id']?>" class="nav-link <?=$cls?>" style="<?=$cls ? $pstyle : ''?>">
                <i class="bi bi-hdd-rack me-3"></i> <span>ID: <?=$c['id']?> (<span data-i18n="plan.<?=strtolower(str_replace(' ','_',$c['plan_name']))?>"><?=$c['plan_name']?></span>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:auto; padding:20px; border-top:1px solid #1e293b;">
        <a href="php/data.php?action=logout" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-power me-2"></i> <span data-i18n="dashboard.logout">Desconectar</span></a>
    </div>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h3 class="fw-bold mb-0 text-white" data-i18n="dashboard.title">Panel de Control</h3>
            <small class="text-light-muted"><span data-i18n="dashboard.welcome">Bienvenido</span>, <?=htmlspecialchars($user_info['username']??'Usuario')?></small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <script src="../public/js/lang.js"></script>
            <div class="lang-selector">
                <button class="lang-btn lang-toggle-btn text-white" style="border-color:rgba(255,255,255,0.2)">
                    <span class="current-lang-flag">🇪🇸</span> <span class="current-lang-label fw-bold small">ES</span> <i class="bi bi-chevron-down small ms-1" style="font-size:0.7em"></i>
                </button>
                <div class="lang-dropdown">
                    <button class="lang-opt" onclick="SyloLang.setLanguage('es')"><span class="me-2">🇪🇸</span> Español</button>
                    <button class="lang-opt" onclick="SyloLang.setLanguage('en')"><span class="me-2">🇬🇧</span> English</button>
                    <button class="lang-opt" onclick="SyloLang.setLanguage('fr')"><span class="me-2">🇫🇷</span> Français</button>
                    <button class="lang-opt" onclick="SyloLang.setLanguage('de')"><span class="me-2">🇩🇪</span> Deutsch</button>
                </div>
            </div>
            <button class="btn btn-dark border border-secondary position-relative" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-person-circle"></i>
            </button>
        </div>
    </div>

    <?php if (!$current): ?>
        <div class="text-center py-5"><i class="bi bi-cloud-slash display-1 text-muted opacity-25"></i><h3 class="mt-3 text-muted" data-i18n="dashboard.empty_state_title">Sin servicios activos</h3><a href="../public/index.php" class="btn btn-primary mt-2" data-i18n="dashboard.empty_state_btn">Desplegar Infraestructura</a></div>
    <?php else: ?>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex align-items-center justify-content-between p-4 card-clean bg-gradient-dark">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-black bg-opacity-25 p-3 rounded-circle border border-secondary shadow-lg">
                        <?=getOSIconHtml($os_image)?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-white"><span data-i18n="dashboard.server_prefix">Servidor</span> #<?=$current['id']?></h4>
                        <div class="d-flex gap-2 align-items-center mt-1">
                            <small class="text-light-muted font-monospace"><i class="bi bi-hdd-network me-1"></i> <?=getOSNamePretty($os_image)?></small>
                            <span class="text-secondary">|</span>
                            <small class="text-light-muted"><?=htmlspecialchars($current['cluster_alias'])?></small>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2 align-items-center">
                    <?php
                        // Determine Badge Style & Text based on DB status
                        $s = strtolower($current['status'] ?? 'pending');
                        $is_online = in_array($s, ['active', 'running', 'online']);
                        $is_stopped = in_array($s, ['stopped', 'offline', 'terminated', 'cancelled']);
                        
                        $badgeClass = $is_online 
                            ? 'bg-success bg-opacity-10 text-success border-success' 
                            : ($is_stopped ? 'bg-danger bg-opacity-10 text-danger border-danger' : 'bg-warning bg-opacity-10 text-warning border-warning');
                            
                        $iconClass = $is_online ? 'bi-circle-fill' : ($is_stopped ? 'bi-stop-circle-fill' : 'bi-hourglass-split');
                        
                        // Map status to i18n key or use uppercase
                        $statusKey = 'dashboard.' . ($is_online ? 'online' : ($s == 'stopped' ? 'stopped' : $s));
                        if($s == 'active') $statusKey = 'dashboard.online';
                    ?>
                    <span class="badge <?=$badgeClass?> border px-3 py-2 rounded-pill shadow-sm"><i class="bi <?=$iconClass?> small me-2"></i><span data-i18n="<?=$statusKey?>" id="status-badge-text"><?=strtoupper($s)?></span></span>
                    <span class="badge px-3 py-2 rounded-pill shadow-sm" style="<?=getPlanStyle($current['plan_name'])?>">
                        PLAN <span data-i18n="plan.<?=strtolower(str_replace(' ','_',$current['plan_name']))?>"><?=strtoupper($current['plan_name'])?></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT COLUMN: Metrics, Terminal, Tools, Logs -->
        <div class="col-lg-8">
            
            <!-- METRICS ROW -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="metric-card h-100 p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="text-light-muted text-uppercase small fw-bold tracking-wider" data-i18n="dashboard.cpu_usage">Uso CPU</span>
                            <i class="bi bi-cpu text-primary fs-4"></i>
                        </div>
                        <div class="metric-value text-white display-6 fw-bold"><span id="cpu-val">0</span>%</div>
                        <div class="progress-thin mt-3" style="height: 6px;"><div id="cpu-bar" class="progress-bar bg-primary shadow-glow" style="width:0%"></div></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="metric-card h-100 p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="text-light-muted text-uppercase small fw-bold tracking-wider" data-i18n="dashboard.ram_usage">Memoria RAM</span>
                            <i class="bi bi-memory text-success fs-4"></i>
                        </div>
                        <div class="metric-value text-white display-6 fw-bold"><span id="ram-val">0</span>%</div>
                        <div class="progress-thin mt-3" style="height: 6px;"><div id="ram-bar" class="progress-bar bg-success shadow-glow" style="width:0%"></div></div>
                    </div>
                </div>
            </div>
            
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between mb-3 align-items-center border-bottom border-secondary border-opacity-10 pb-3">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-terminal me-2 text-warning"></i><span data-i18n="dashboard.system_access">Accesos de Sistema</span></h6>
                    <button onclick="copyAllCreds()" class="btn btn-sm btn-dark border-secondary hover-white"><i class="bi bi-copy me-1"></i> Copiar</button>
                </div>
                <div class="terminal-container p-3 rounded bg-black" id="all-creds-box" style="font-family: 'Fira Code', monospace; font-size: 0.9rem;">
                    <?php if($has_web): ?>
                        <div class="mb-2"><span class="text-secondary select-none">WEB:</span> <a href="<?=htmlspecialchars($web_url??'#')?>" target="_blank" class="text-info text-decoration-none hover-underline" id="disp-web-url"><?=htmlspecialchars($web_url??'Esperando IP...')?></a></div>
                    <?php endif; ?>
                    <?php if($has_db): ?>
                        <div class="mt-3 text-secondary small select-none"># DATABASE CLUSTER</div>
                        <div><span class="text-secondary select-none">MASTER:</span> <span class="text-white">mysql-master-0 (Write)</span></div>
                        <div><span class="text-secondary select-none">SLAVE:</span>  <span class="text-white">mysql-slave-0 (Read)</span></div>
                    <?php endif; ?>
                    <div class="mt-3 text-secondary small select-none"># SSH ROOT ACCESS</div>
                    <div><span class="text-secondary select-none">CMD:</span>  <span class="text-success" id="disp-ssh-cmd"><?=htmlspecialchars($creds['ssh_cmd'] ?? 'Connecting...')?></span></div>
                    <div><span class="text-secondary select-none">PASS:</span> <span class="text-warning" id="disp-ssh-pass"><?=htmlspecialchars($creds['ssh_pass'] ?? 'sylo1234')?></span></div>
                </div>
            </div>

            <!-- SYLO BASTION TERMINAL SECTION -->
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-hdd-network me-2 text-success"></i><span data-i18n="dashboard.web_terminal">Consola Web (Bastion)</span></h6>
                    <button class="btn btn-sm btn-dark border-secondary hover-white" onclick="initTerminal()" id="btn-reconnect-term"><i class="bi bi-plug me-1"></i> Conectar</button>
                </div>
                <!-- Terminal Container -->
                <div id="terminal-container" style="height: 300px; background: #000; border-radius: 6px; padding: 10px; overflow: hidden;">
                    <div class="text-secondary small font-monospace">Haga clic en 'Conectar' para iniciar sesión SSH...</div>
                </div>
            </div>

            <!-- SYLO TOOLBELT SECTION -->
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-tools me-2 text-info"></i><span data-i18n="dashboard.installed_software">Software Instalado</span></h6>
                </div>
                <div class="d-flex flex-wrap gap-2" id="installed-software-container">
                    <?php if(!empty($installed_tools)): ?>
                        <?php foreach($installed_tools as $tool): ?>
                            <span class="badge bg-dark border border-secondary text-white py-2 px-3 fw-normal d-flex align-items-center shadow-sm">
                                <i class="bi bi-check2-circle text-success me-2"></i> <?=htmlspecialchars($tool)?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 w-100 text-center border border-dashed border-secondary rounded text-muted small bg-black bg-opacity-25">
                            <i class="bi bi-info-circle me-2"></i>No installed software detected.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- MARKETPLACE SECTION -->
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-shop me-2 text-warning"></i><span data-i18n="dashboard.marketplace">Marketplace</span></h6>
                </div>
                
                <?php
                // Check if monitoring is already installed
                $has_monitoring = false;
                if (!empty($installed_tools)) {
                    $has_monitoring = in_array('monitoring', $installed_tools);
                }
                ?>
                
                <div class="row g-3">
                    <!-- MONITORING PRO CARD -->
                    <div class="col-md-6">
                        <div class="p-4 rounded border border-secondary bg-black bg-opacity-25 h-100 d-flex flex-column">
                            <div class="d-flex align-items-start mb-3">
                                <div class="bg-warning bg-opacity-10 p-3 rounded-circle border border-warning me-3">
                                    <i class="bi bi-graph-up text-warning fs-4"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="fw-bold text-white mb-1">Monitoring Pro</h5>
                                    <small class="text-muted">Prometheus + Grafana</small>
                                </div>
                                <?php if ($has_monitoring): ?>
                                    <span class="badge bg-success">Instalado</span>
                                <?php endif; ?>
                            </div>
                            
                            <p class="text-light-muted small mb-3">
                                Stack completo de observabilidad con métricas en tiempo real, dashboards interactivos y alertas personalizadas.
                            </p>
                            
                            <ul class="list-unstyled text-small text-white opacity-75 mb-4">
                                <li><i class="bi bi-check2 text-success me-2"></i>Prometheus para métricas</li>
                                <li><i class="bi bi-check2 text-success me-2"></i>Grafana con dashboards</li>
                                <li><i class="bi bi-check2 text-success me-2"></i>Acceso vía subdominio</li>
                                <li><i class="bi bi-check2 text-success me-2"></i>Retención 7 días</li>
                            </ul>
                            
                            <div class="mt-auto">
                                <?php if ($has_monitoring): ?>
                                    <a href="http://localhost:80<?=$current['id']?>" target="_blank" class="btn btn-success w-100 mb-2">
                                        <i class="bi bi-box-arrow-up-right me-2"></i>Abrir Grafana
                                    </a>
                                    <a href="http://localhost:90<?=$current['id']?>" target="_blank" class="btn btn-danger w-100 mb-2 bg-gradient text-white" style="background-color: #e6522c; border-color: #e6522c;">
                                        <i class="bi bi-fire me-2"></i>Abrir Prometheus
                                    </a>
                                    <button class="btn btn-outline-danger w-100 btn-sm" onclick="uninstallMonitoring()">
                                        <i class="bi bi-trash me-2"></i>Desinstalar
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-warning w-100 fw-bold" onclick="installMonitoring()">
                                        <i class="bi bi-download me-2"></i>Instalar Ahora
                                    </button>
                                    <small class="text-muted d-block mt-2 text-center">Instalación ~2 minutos</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- FUTURE: Add more marketplace items here -->
                    <div class="col-md-6">
                        <div class="p-4 rounded border border-secondary border-opacity-25 bg-black bg-opacity-10 h-100 d-flex align-items-center justify-content-center">
                            <div class="text-center text-muted">
                                <i class="bi bi-plus-circle fs-1 opacity-25"></i>
                                <p class="mt-2 mb-0 small">Más herramientas próximamente</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-clean">
                <h6 class="fw-bold mb-3 text-white"><i class="bi bi-clock-history me-2 text-info"></i><span data-i18n="dashboard.activity_log">Historial de Actividad</span></h6>
                <div class="table-responsive rounded border border-secondary border-opacity-25">
                    <table class="table table-dark table-hover mb-0 small">
                        <tbody id="activity-log-body">
                            <tr id="log-empty-row"><td class="text-light-muted text-center py-3"><span data-i18n="dashboard.waiting_events">Esperando eventos...</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Web, Energy, Backups, Destroy -->
        <div class="col-lg-4">
            <div class="card-clean h-100">
                <div class="d-flex justify-content-between mb-4 align-items-center border-bottom border-secondary border-opacity-10 pb-3">
                    <h6 class="fw-bold m-0 text-white" data-i18n="dashboard.command_center">Centro de Mando</h6>
                    <button onclick="manualRefresh()" id="btn-refresh" class="btn btn-sm btn-dark border border-secondary text-light-muted hover-white"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                
                <?php if($has_web): ?>
                    <label class="small text-light-muted mb-2 d-block" data-i18n="dashboard.web_deploy">Despliegue Web</label>
                    <a href="<?= $web_url ? htmlspecialchars($web_url) : 'javascript:void(0);' ?>" target="<?= $web_url ? '_blank' : '_self' ?>" id="btn-ver-web" class="btn btn-primary w-100 mb-2 fw-bold position-relative overflow-hidden <?= $web_url ? '' : 'disabled' ?>">
                        <div id="web-loader-fill" style="position:absolute;top:0;left:0;height:100%;width:0%;background:rgba(255,255,255,0.2);"></div>
                        <span id="web-btn-text"><i class="bi bi-box-arrow-up-right me-2"></i><span data-i18n="dashboard.view_site"><?= $web_url ? 'Ver Sitio Web' : 'Cargando...' ?></span></span>
                    </a>
                    <div class="d-flex gap-2 mb-4">
                        <button class="btn btn-dark border border-secondary flex-fill text-light-muted" onclick="openEditor()"><i class="bi bi-code-slash"></i> <span data-i18n="dashboard.edit_web">Editar</span></button>
                        <button class="btn btn-dark border border-secondary flex-fill text-light-muted" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload"></i> <span data-i18n="dashboard.upload_web">Subir</span></button>
                    </div>
                <?php endif; ?>

                <label class="small text-light-muted mb-2 d-block" data-i18n="dashboard.energy_control">Control de Energía</label>
                <div id="energyForm" class="mb-3">
                    <?php 
                        $isActive = in_array($current['status'], ['active', 'running', 'completed', 'online']); 
                    ?>
                    
                    <!-- Controls for Active State -->
                    <div id="controls-active" style="display: <?=$isActive ? 'block' : 'none'?>;">
                        <button type="button" class="btn-action" onclick="sendPowerAction('restart')"><i class="bi bi-arrow-repeat text-warning me-3 fs-5"></i><div><div class="fw-bold text-white" data-i18n="dashboard.restart">Reiniciar</div><small data-i18n="dashboard.restart_desc">Aplicar cambios</small></div></button>
                        <button type="button" class="btn-action" onclick="sendPowerAction('stop')"><i class="bi bi-stop-circle text-danger me-3 fs-5"></i><div><div class="fw-bold text-white" data-i18n="dashboard.stop">Apagar</div><small data-i18n="dashboard.stop_desc">Modo hibernación</small></div></button>
                    </div>

                    <!-- Controls for Inactive State -->
                    <div id="controls-inactive" style="display: <?=$isActive ? 'none' : 'block'?>;">
                        <button type="button" class="btn-action" onclick="sendPowerAction('start')"><i class="bi bi-play-circle text-success me-3 fs-5"></i><div><div class="fw-bold text-white" data-i18n="dashboard.start">Encender</div><small data-i18n="dashboard.start_desc">Volver a online</small></div></button>
                    </div>
                </div>
                
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="small text-light-muted" data-i18n="dashboard.snapshots">Snapshots</label>
                        <span class="badge bg-dark border border-secondary" id="backup-count">0/<?=$backup_limit?></span>
                    </div>
                    <button class="btn-action justify-content-center text-center py-2" onclick="showBackupModal()"><i class="bi bi-camera me-2"></i><span data-i18n="dashboard.create_snapshot">Crear Snapshot</span></button>
                    
                    <div id="backup-ui" style="display:none" class="mt-3"><div class="progress" style="height:4px"><div id="backup-bar" class="progress-bar bg-info" style="width:0%"></div></div><small id="backup-status-text" class="text-info d-block mt-1" data-i18n="backend.starting">Starting...</small></div>
                    
                    <div id="delete-ui" style="display:none" class="mt-3"><div class="progress" style="height:4px"><div id="delete-bar" class="progress-bar bg-danger" style="width:0%"></div></div><small class="text-danger d-block mt-1" data-i18n="backend.deleting">Deleting...</small></div>

                    <div id="backups-list-container" class="mt-3"></div>
                </div>
                
                <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 text-center">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#changePlanModal">
                            <i class="bi bi-sliders me-2"></i><span data-i18n="dashboard.change_plan">Cambiar Plan</span>
                        </button>
                        <button class="btn btn-outline-danger btn-sm" onclick="destroyK8s()">
                            <i class="bi bi-radioactive me-2"></i><span data-i18n="dashboard.destroy_k8s">Destruir Kubernetes</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="chat-widget" onclick="toggleChat()"><i class="bi bi-chat-fill"></i></div>
<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <div class="d-flex align-items-center gap-2"><div style="width:10px;height:10px;background:#10b981;border-radius:50%"></div><span class="fw-bold text-white" data-i18n="chat.title">Soporte Sylo</span></div>
        <i class="bi bi-x-lg cursor-pointer" onclick="toggleChat()"></i>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="chat-msg support">
            <span data-i18n="chat.greeting">Hola, si tienes preguntas, estas son las más frecuentes:</span>
            <br><br>
            <span data-i18n="chat.q1">1️⃣ ¿Cómo entro a mi servidor? (SSH)</span><br>
            <span data-i18n="chat.q2">2️⃣ ¿Cuál es mi página web?</span><br>
            <span data-i18n="chat.q3">3️⃣ Datos de Base de Datos</span><br>
            <span data-i18n="chat.q4">4️⃣ ¿Cuántas copias puedo hacer?</span><br>
            <span data-i18n="chat.q5">5️⃣ Estado de Salud (CPU/RAM)</span><br><br>
            <span data-i18n="chat.instruction">Escribe el número para ver la respuesta.</span>
        </div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="chatInput" class="form-control bg-dark border-secondary text-white" placeholder="Escribe..." data-i18n="chat.placeholder" onkeypress="handleChat(event)">
        <button class="btn btn-primary btn-sm" onclick="sendChat()"><i class="bi bi-send"></i></button>
    </div>
</div>

<div class="modal fade" id="backupTypeModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-hdd-fill me-2"></i><span data-i18n="snapshot.title">Nueva Snapshot</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="form-label small fw-bold text-light-muted" data-i18n="snapshot.name_label">Nombre de la copia</label><input type="text" id="backup_name_input" class="form-control" placeholder="Ej: Antes de cambios..." data-i18n="snapshot.placeholder" maxlength="20"></div><div class="d-flex flex-column gap-2"><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="full" checked class="form-check-input mt-0"><div><div class="fw-bold text-white" data-i18n="snapshot.type_full">Completa (Full)</div><div class="small text-muted" style="font-size:0.75rem" data-i18n="snapshot.desc_full">Copia total del disco.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="diff" class="form-check-input mt-0"><div><div class="fw-bold text-white" data-i18n="snapshot.type_diff">Diferencial</div><div class="small text-muted" style="font-size:0.75rem" data-i18n="snapshot.desc_diff">Cambios desde última Full.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="incr" class="form-check-input mt-0"><div><div class="fw-bold text-white" data-i18n="snapshot.type_incr">Incremental</div><div class="small text-muted" style="font-size:0.75rem" data-i18n="snapshot.desc_incr">Solo lo nuevo hoy.</div></div></label></div><div id="limit-alert" class="alert alert-danger small mt-3 mb-0" style="display:none"><i class="bi bi-exclamation-octagon-fill me-1"></i> <strong data-i18n="snapshot.limit_reached">Límite alcanzado.</strong><br>Elimina una copia para continuar.</div><div id="normal-alert" class="alert alert-warning small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i> <span data-i18n="snapshot.limit">Límite:</span> <strong><?=$backup_limit?> <span data-i18n="snapshot.copies">copias</span></strong>.</div></div><div class="modal-footer border-0 px-4 pb-4"><button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal" data-i18n="common.cancel">Cancelar</button><button type="button" id="btn-start-backup" onclick="doBackup()" class="btn btn-primary rounded-pill px-4 fw-bold" data-i18n="snapshot.start_btn">Iniciar Copia</button></div></div></div></div>
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header bg-dark text-white border-bottom border-secondary"><h5 class="modal-title" data-i18n="editor.title">Editor HTML</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div id="editor"></div></div><div class="modal-footer bg-dark border-top border-secondary"><button class="btn btn-secondary rounded-pill" data-bs-dismiss="modal" data-i18n="common.close">Cerrar</button><button class="btn btn-primary rounded-pill fw-bold" onclick="saveWeb()" data-i18n="editor.publish"><i class="bi bi-save me-2"></i>Publicar</button></div></div></div></div>
<div class="modal fade" id="uploadModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0"><h5 class="modal-title" data-i18n="upload.title">Subir Web</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="uploadForm" enctype="multipart/form-data"><input type="file" id="htmlFile" name="html_file" class="form-control mb-3" required><button type="submit" class="btn btn-success w-100 rounded-pill" data-i18n="upload.btn">Subir</button></form></div></div></div></div>
<div class="modal fade" id="profileModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-person-lines-fill me-2"></i><span data-i18n="profile.title">Perfil</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST" action="php/data.php"><input type="hidden" name="action" value="update_profile"><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="small text-light-muted" data-i18n="profile.name">Nombre</label><input type="text" name="full_name" class="form-control" value="<?=htmlspecialchars($user_info['full_name']??'')?>"></div><div class="mb-3"><label class="small text-light-muted" data-i18n="profile.email">Email</label><input type="email" name="email" class="form-control" value="<?=htmlspecialchars($user_info['email']??'')?>" required></div><button type="submit" class="btn btn-primary w-100 rounded-pill" data-i18n="common.save">Guardar</button></div></form></div></div></div>
<div class="modal fade" id="billingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0"><h5 class="modal-title" data-i18n="billing.title">Facturación</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php foreach($clusters as $c): ?><div class="d-flex justify-content-between mb-2"><span>#<?=$c['id']?> <span data-i18n="plan.<?=strtolower(str_replace(' ','_',$c['plan_name']))?>"><?=$c['plan_name']?></span></span><span class="text-success"><?=number_format(calculateWeeklyPrice($c),2)?>€</span></div><?php endforeach; ?><hr><div class="d-flex justify-content-between fs-5 text-white"><strong data-i18n="common.total">Total</strong><strong class="text-primary"><?=number_format($total_weekly,2)?>€</strong></div></div></div></div></div>

<!-- CHANGE PLAN MODAL (ENHANCED UI) -->
<div class="modal fade" id="changePlanModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0" style="background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px);">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-rocket-takeoff-fill me-2 text-warning"></i>Mejorar Plan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="php/data.php" id="planForm">
                <input type="hidden" name="action" value="change_plan">
                <input type="hidden" name="order_id" value="<?=$current['id']?>">
                <input type="hidden" name="new_plan" id="selectedPlanInput" value="<?=$current['plan_name']?>">
                
                <div class="modal-body px-4 pt-4">
                    <!-- PLANS GRID -->
                    <div class="row g-3 mb-4">
                        <!-- BRONZE -->
                        <div class="col-md-4">
                            <div class="plan-card p-4 h-100 d-flex flex-column justify-content-between <?= ($current['plan_name'] == 'Bronce') ? 'current-plan' : '' ?>" onclick="selectPlan('Bronce', this)">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="plan-icon bg-bronze"><i class="bi bi-hdd-network"></i></div>
                                        <?php if($current['plan_name'] == 'Bronce'): ?><span class="badge bg-secondary">Actual</span><?php endif; ?>
                                    </div>
                                    <h4 class="fw-bold mb-1">Plan Bronce</h4>
                                    <p class="text-light-muted small mb-3">Para proyectos pequeños.</p>
                                    <ul class="list-unstyled text-small text-white opacity-75">
                                        <li><i class="bi bi-check2 text-success me-2"></i>1 vCPU Core</li>
                                        <li><i class="bi bi-check2 text-success me-2"></i>1 GB RAM</li>
                                        <li><i class="bi bi-x-lg text-secondary me-2"></i>Sin Base de Datos</li>
                                    </ul>
                                </div>
                                <div class="mt-3 text-center w-100 p-2 rounded border border-secondary border-opacity-25 hover-bg">
                                    <small>Seleccionar</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- SILVER -->
                        <div class="col-md-4">
                            <div class="plan-card p-4 h-100 d-flex flex-column justify-content-between <?= ($current['plan_name'] == 'Plata') ? 'current-plan' : '' ?>" onclick="selectPlan('Plata', this)">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="plan-icon bg-silver"><i class="bi bi-cpu-fill"></i></div>
                                        <?php if($current['plan_name'] == 'Plata'): ?><span class="badge bg-secondary">Actual</span><?php endif; ?>
                                    </div>
                                    <h4 class="fw-bold mb-1">Plan Plata</h4>
                                    <p class="text-light-muted small mb-3">Equilibrio perfecto.</p>
                                    <ul class="list-unstyled text-small text-white opacity-75">
                                        <li><i class="bi bi-check2 text-success me-2"></i>2 vCPU Cores</li>
                                        <li><i class="bi bi-check2 text-success me-2"></i>2 GB RAM</li>
                                        <li><i class="bi bi-x-lg text-secondary me-2"></i>Sin Base de Datos</li>
                                    </ul>
                                </div>
                                <div class="mt-3 text-center w-100 p-2 rounded border border-secondary border-opacity-25 hover-bg">
                                    <small>Seleccionar</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- GOLD -->
                        <div class="col-md-4">
                            <div class="plan-card p-4 h-100 d-flex flex-column justify-content-between <?= ($current['plan_name'] == 'Oro') ? 'current-plan' : '' ?>" onclick="selectPlan('Oro', this)">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="plan-icon bg-gold"><i class="bi bi-lightning-charge-fill"></i></div>
                                        <?php if($current['plan_name'] == 'Oro'): ?><span class="badge bg-secondary">Actual</span><?php endif; ?>
                                    </div>
                                    <h4 class="fw-bold mb-1 text-warning">Plan Oro</h4>
                                    <p class="text-light-muted small mb-3">Máximo rendimiento.</p>
                                    <ul class="list-unstyled text-small text-white opacity-75">
                                        <li><i class="bi bi-check2 text-warning me-2"></i>4 vCPU Cores</li>
                                        <li><i class="bi bi-check2 text-warning me-2"></i>4 GB RAM</li>
                                        <li><i class="bi bi-check2 text-warning me-2"></i>Base de Datos Incluida</li>
                                    </ul>
                                </div>
                                <div class="mt-3 text-center w-100 p-2 rounded border border-warning border-opacity-50 text-warning hover-bg">
                                    <small>Seleccionar</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- CUSTOM OPTION -->
                    <div class="plan-card p-3 mb-3 d-flex align-items-center justify-content-between cursor-pointer <?= ($current['plan_name'] == 'Personalizado') ? 'current-plan' : '' ?>" onclick="selectPlan('Personalizado', this)">
                       <div class="d-flex align-items-center gap-3">
                           <div class="plan-icon bg-custom text-white"><i class="bi bi-sliders"></i></div>
                           <div>
                               <h6 class="fw-bold mb-0">Configuración Personalizada</h6>
                               <small class="text-muted">Define tus propios recursos (CPU, RAM, Servicios)</small>
                           </div>
                       </div>
                       <i class="bi bi-chevron-right text-muted"></i>
                    </div>

                    <div id="custom-plan-options" class="p-4 border border-secondary rounded-4 bg-black bg-opacity-50 mb-3" style="display:none; animation: fadeIn 0.3s ease;">
                        <h6 class="text-white mb-4 border-bottom border-secondary pb-2"><i class="bi bi-tools me-2"></i>Ajustes Avanzados</h6>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="small text-muted mb-2">CPU Cores</label>
                                <div class="d-flex align-items-center gap-3">
                                    <input type="range" class="form-range flex-grow-1" min="1" max="8" step="1" value="<?=$current['custom_cpu'] ?? 1?>" oninput="document.getElementById('cpuVal').innerText = this.value">
                                    <span class="badge bg-primary fs-6"><span id="cpuVal"><?=$current['custom_cpu'] ?? 1?></span> vCPU</span>
                                    <input type="hidden" name="custom_cpu" id="customCpuInput" value="<?=$current['custom_cpu'] ?? 1?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="small text-muted mb-2">Memoria RAM</label>
                                <div class="d-flex align-items-center gap-3">
                                    <input type="range" class="form-range flex-grow-1" min="1" max="16" step="1" value="<?=$current['custom_ram'] ?? 1?>" oninput="document.getElementById('ramVal').innerText = this.value">
                                    <span class="badge bg-info fs-6"><span id="ramVal"><?=$current['custom_ram'] ?? 1?></span> GB</span>
                                    <input type="hidden" name="custom_ram" id="customRamInput" value="<?=$current['custom_ram'] ?? 1?>">
                                </div>
                            </div>
                            <div class="col-12 d-flex gap-4 mt-3">
                                <div class="form-check form-switch p-3 rounded bg-dark border border-secondary flex-grow-1">
                                    <input class="form-check-input" type="checkbox" name="custom_db" value="1" id="chkDb" <?= (!empty($current['db_enabled'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-white ms-2 cursor-pointer" for="chkDb">
                                        <i class="bi bi-database-fill me-2 text-warning"></i>Base de Datos
                                        <div class="small text-muted mt-1">Habilita MySQL/PostgreSQL persistente.</div>
                                    </label>
                                </div>
                                <div class="form-check form-switch p-3 rounded bg-dark border border-secondary flex-grow-1">
                                    <input class="form-check-input" type="checkbox" name="custom_web" value="1" id="chkWeb" <?= (!empty($current['web_enabled'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label text-white ms-2 cursor-pointer" for="chkWeb">
                                        <i class="bi bi-globe me-2 text-info"></i>Servidor Web
                                        <div class="small text-muted mt-1">Habilita Nginx/Apache.</div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" onclick="submitPlanChange()" class="btn btn-warning rounded-pill px-5 fw-bold shadow-glow">Aplicar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function selectPlan(planInfo, element) {
    // Update Input
    document.getElementById('selectedPlanInput').value = planInfo;
    
    // Update Visuals
    document.querySelectorAll('.plan-card').forEach(el => {
        el.classList.remove('selected');
        el.style.borderColor = 'rgba(51, 65, 85, 0.5)';
        el.style.background = 'rgba(15, 23, 42, 0.7)';
    });
    
    element.classList.add('selected');
    
    // Toggle Custom
    const cust = document.getElementById('custom-plan-options');
    if (planInfo === 'Personalizado') {
        cust.style.display = 'block';
    } else {
        cust.style.display = 'none';
    }
}

// Sync ranges to hidden inputs if needed (or just use name on range)
// Actually range inputs above didn't have name, fixing that via JS or adding name attr. 
// Adding name attr directly to range inputs is better, but I used hidden. Let's just give names to ranges.
document.querySelectorAll('input[type=range]').forEach(input => {
    input.addEventListener('input', e => {
        if(e.target.nextElementSibling.nextElementSibling) 
            e.target.nextElementSibling.nextElementSibling.value = e.target.value;
    });
});

// Init on load
document.addEventListener('DOMContentLoaded', () => {
    // Highlight current
    const current = <?=json_encode($current['plan_name']??'')?>;
    // Find card with onclick having current
    // ... logic handled by PHP echoing 'current-plan' class which we will style, 
    // but better to trigger click to set state.
    // Simplifying: User clicks to select.
});
</script>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const orderId = <?=$current['id']??0?>; 
    const planCpus = <?=$plan_cpus?>; 
    const backupLimit = <?=$backup_limit?>;
    const initialCode = <?php echo json_encode($html_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
    <!-- Cloud Loader Overlay REPLACED BY Power Modal -->
    <div class="modal fade" id="powerModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border); backdrop-filter: blur(10px);">
                <div class="modal-header border-bottom border-secondary border-opacity-25">
                    <h5 class="modal-title" data-i18n="power.title">Control de Energía</h5>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-cpu fs-1 text-primary animate-pulse"></i>
                    </div>
                    <h5 id="power-status-text" class="mb-3" data-i18n="power.requesting">Solicitando acción...</h5>
                    <div class="progress" style="height: 10px; background: rgba(255,255,255,0.1);">
                        <div id="power-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
let term = null;
let ws = null;
let fitAddon = null;

function initTerminal() {
    if (term) { term.dispose(); term = null; }
    if (ws) { ws.close(); ws = null; }

    const container = document.getElementById('terminal-container');
    container.innerHTML = ''; // Limpiar

    term = new Terminal({
        cursorBlink: true,
        theme: { background: '#000000', foreground: '#00ff00' },
        fontSize: 14,
        fontFamily: "'Fira Code', monospace"
    });
    
    fitAddon = new FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(container);
    fitAddon.fit();

    // FIX: Re-focus terminal on click
    container.addEventListener('click', () => {
        if (term) term.focus();
    });

    term.writeln("🔌 Conectando a Sylo Bastion...");

    // Determine WS URL (Localhost workaround if needed)
    // Assuming API is on port 8001
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const host = window.location.hostname; // 'localhost' or '127.0.0.1' or IP
    const wsUrl = `${protocol}//${host}:8001/api/console/sylo-cliente-${orderId}`;

    ws = new WebSocket(wsUrl);

    ws.onopen = () => {
        term.writeln("\r\n✅ CONEXIÓN ESTABLECIDA\r\n");
        term.focus();
    };

    ws.onmessage = (event) => {
        term.write(event.data);
    };

    ws.onclose = () => {
        term.writeln("\r\n⚠️ CONEXIÓN CERRADA");
    };

    ws.onerror = (e) => {
        term.writeln("\r\n❌ ERROR DE CONEXIÓN");
    };

    term.onData(data => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(data);
        }
    });

    // Resize observer
    window.addEventListener('resize', () => fitAddon.fit());
}

// ============= MONITORING MARKETPLACE FUNCTIONS =============
function installMonitoring() {
    if (!confirm('¿Instalar Prometheus + Grafana en este clúster?\n\nEsto consumirá ~300MB de RAM adicionales.')) {
        return;
    }
    
    console.log('Iniciando instalación de Monitoring Pro...');
    
    fetch('php/data.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'install_monitoring',
            deployment_id: orderId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Instalación iniciada. La página se recargará en 2 minutos.');
            setTimeout(() => location.reload(), 120000);
        } else {
            alert('Error: ' + (data.error || 'Desconocido'));
        }
    })
    .catch(e => alert('Error de red: ' + e.message));
}

function uninstallMonitoring() {
    if (!confirm('¿Desinstalar Monitoring Pro?\n\nSe perderán todas las métricas almacenadas.')) {
        return;
    }
    
    fetch('php/data.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'uninstall_monitoring',
            deployment_id: orderId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Desinstalación completada');
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Error: ' + (data.error || 'Desconocido'));
        }
    });
}

</script>
<script src="js/control.js?v=<?=time()?>"></script>
</body>
</html>
