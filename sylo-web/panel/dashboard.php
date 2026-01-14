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
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="toast-container" id="toastContainer"></div>
<div class="sidebar">
    <div class="brand"><i class="bi bi-cpu-fill text-primary me-2"></i><strong>SYLO</strong>_OS</div>
    <div class="d-flex flex-column gap-1 p-2">
        <a href="../public/index.php" class="nav-link"><i class="bi bi-plus-lg me-3"></i> Nuevo Servicio</a>
        <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#billingModal"><i class="bi bi-credit-card me-3"></i> Facturación</a>
        <div class="mt-4 px-4 mb-2 text-light-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 1px; opacity: 0.6;">MIS CLÚSTERES</div>
        <?php foreach($clusters as $c): 
            $cls = ($current && $c['id']==$current['id'])?'active':'';
            $pstyle = getSidebarStyle($c['plan_name']); 
        ?>
            <a href="?id=<?=$c['id']?>" class="nav-link <?=$cls?>" style="<?=$cls ? $pstyle : ''?>">
                <i class="bi bi-hdd-rack me-3"></i> <span>ID: <?=$c['id']?> (<?=$c['plan_name']?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:auto; padding:20px; border-top:1px solid #1e293b;">
        <a href="php/data.php?action=logout" class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-power me-2"></i> Desconectar</a>
    </div>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h3 class="fw-bold mb-0 text-white">Panel de Control</h3>
            <small class="text-light-muted">Bienvenido, <?=htmlspecialchars($user_info['username']??'Usuario')?></small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-dark border border-secondary position-relative" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-person-circle"></i>
            </button>
        </div>
    </div>

    <?php if (!$current): ?>
        <div class="text-center py-5"><i class="bi bi-cloud-slash display-1 text-muted opacity-25"></i><h3 class="mt-3 text-muted">Sin servicios activos</h3><a href="../public/index.php" class="btn btn-primary mt-2">Desplegar Infraestructura</a></div>
    <?php else: ?>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex align-items-center justify-content-between p-4 card-clean bg-gradient-dark">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-black bg-opacity-25 p-3 rounded-circle border border-secondary shadow-lg">
                        <?=getOSIconHtml($os_image)?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-white">Servidor #<?=$current['id']?></h4>
                        <div class="d-flex gap-2 align-items-center mt-1">
                            <small class="text-light-muted font-monospace"><i class="bi bi-hdd-network me-1"></i> <?=getOSNamePretty($os_image)?></small>
                            <span class="text-secondary">|</span>
                            <small class="text-light-muted"><?=htmlspecialchars($current['cluster_alias'])?></small>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2 rounded-pill shadow-sm"><i class="bi bi-circle-fill small me-2"></i>ONLINE</span>
                    <span class="badge px-3 py-2 rounded-pill shadow-sm" style="<?=getPlanStyle($current['plan_name'])?>">
                        PLAN <?=strtoupper($current['plan_name'])?>
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
                            <span class="text-light-muted text-uppercase small fw-bold tracking-wider">Uso CPU</span>
                            <i class="bi bi-cpu text-primary fs-4"></i>
                        </div>
                        <div class="metric-value text-white display-6 fw-bold"><span id="cpu-val">0</span>%</div>
                        <div class="progress-thin mt-3" style="height: 6px;"><div id="cpu-bar" class="progress-bar bg-primary shadow-glow" style="width:0%"></div></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="metric-card h-100 p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="text-light-muted text-uppercase small fw-bold tracking-wider">Memoria RAM</span>
                            <i class="bi bi-memory text-success fs-4"></i>
                        </div>
                        <div class="metric-value text-white display-6 fw-bold"><span id="ram-val">0</span>%</div>
                        <div class="progress-thin mt-3" style="height: 6px;"><div id="ram-bar" class="progress-bar bg-success shadow-glow" style="width:0%"></div></div>
                    </div>
                </div>
            </div>
            
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between mb-3 align-items-center border-bottom border-secondary border-opacity-10 pb-3">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-terminal me-2 text-warning"></i>Accesos de Sistema</h6>
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

            <!-- SYLO TOOLBELT SECTION -->
            <div class="card-clean mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold m-0 text-white"><i class="bi bi-tools me-2 text-info"></i>Software Instalado</h6>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if(!empty($installed_tools)): ?>
                        <?php foreach($installed_tools as $tool): ?>
                            <span class="badge bg-dark border border-secondary text-white py-2 px-3 fw-normal d-flex align-items-center shadow-sm">
                                <i class="bi bi-check2-circle text-success me-2"></i> <?=htmlspecialchars($tool)?>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 w-100 text-center border border-dashed border-secondary rounded text-muted small bg-black bg-opacity-25">
                            <i class="bi bi-box-seam me-2"></i>No se han detectado herramientas instaladas.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-clean">
                <h6 class="fw-bold mb-3 text-white"><i class="bi bi-clock-history me-2 text-info"></i>Historial de Actividad</h6>
                <div class="table-responsive rounded border border-secondary border-opacity-25">
                    <table class="table table-dark table-hover mb-0 small">
                        <tbody id="activity-log-body">
                            <tr><td class="text-light-muted text-center py-3">Esperando eventos...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Web, Energy, Backups, Destroy -->
        <div class="col-lg-4">
            <div class="card-clean h-100">
                <div class="d-flex justify-content-between mb-4 align-items-center border-bottom border-secondary border-opacity-10 pb-3">
                    <h6 class="fw-bold m-0 text-white">Centro de Mando</h6>
                    <button onclick="manualRefresh()" id="btn-refresh" class="btn btn-sm btn-dark border border-secondary text-light-muted hover-white"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                
                <?php if($has_web): ?>
                    <label class="small text-light-muted mb-2 d-block">Despliegue Web</label>
                    <a href="<?= $web_url ? htmlspecialchars($web_url) : 'javascript:void(0);' ?>" target="<?= $web_url ? '_blank' : '_self' ?>" id="btn-ver-web" class="btn btn-primary w-100 mb-2 fw-bold position-relative overflow-hidden <?= $web_url ? '' : 'disabled' ?>">
                        <div id="web-loader-fill" style="position:absolute;top:0;left:0;height:100%;width:0%;background:rgba(255,255,255,0.2);"></div>
                        <span id="web-btn-text"><i class="bi bi-box-arrow-up-right me-2"></i><?= $web_url ? 'Ver Sitio Web' : 'Cargando...' ?></span>
                    </a>
                    <div class="d-flex gap-2 mb-4">
                        <button class="btn btn-dark border border-secondary flex-fill text-light-muted" onclick="openEditor()"><i class="bi bi-code-slash"></i> Editar</button>
                        <button class="btn btn-dark border border-secondary flex-fill text-light-muted" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-upload"></i> Subir</button>
                    </div>
                <?php endif; ?>

                <label class="small text-light-muted mb-2 d-block">Control de Energía</label>
                <form method="POST" action="php/data.php" id="energyForm">
                    <input type="hidden" name="order_id" value="<?=$current['id']?>">
                    <?php if(in_array($current['status'], ['active', 'running', 'completed', 'online'])): ?>
                        <button type="submit" name="action" value="restart" class="btn-action" onclick="showToast('Reiniciando sistema...', 'info')"><i class="bi bi-arrow-repeat text-warning me-3 fs-5"></i><div><div class="fw-bold text-white">Reiniciar</div><small>Aplicar cambios</small></div></button>
                        <button type="submit" name="action" value="stop" class="btn-action" onclick="showToast('Deteniendo sistema...', 'warning')"><i class="bi bi-stop-circle text-danger me-3 fs-5"></i><div><div class="fw-bold text-white">Apagar</div><small>Modo hibernación</small></div></button>
                    <?php else: ?>
                        <button type="submit" name="action" value="start" class="btn-action" onclick="showToast('Iniciando sistema...', 'success')"><i class="bi bi-play-circle text-success me-3 fs-5"></i><div><div class="fw-bold text-white">Encender</div><small>Volver a online</small></div></button>
                    <?php endif; ?>
                </form>
                
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="small text-light-muted">Snapshots</label>
                        <span class="badge bg-dark border border-secondary" id="backup-count">0/<?=$backup_limit?></span>
                    </div>
                    <button class="btn-action justify-content-center text-center py-2" onclick="showBackupModal()"><i class="bi bi-camera me-2"></i>Crear Snapshot</button>
                    
                    <div id="backup-ui" style="display:none" class="mt-3"><div class="progress" style="height:4px"><div id="backup-bar" class="progress-bar bg-info" style="width:0%"></div></div><small class="text-info d-block mt-1">Creando backup...</small></div>
                    
                    <div id="delete-ui" style="display:none" class="mt-3"><div class="progress" style="height:4px"><div id="delete-bar" class="progress-bar bg-danger" style="width:0%"></div></div><small class="text-danger d-block mt-1">Eliminando...</small></div>

                    <div id="backups-list-container" class="mt-3"></div>
                </div>
                
                <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 text-center">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-danger btn-sm" onclick="destroyK8s()">
                            <i class="bi bi-radioactive me-2"></i>Destruir Kubernetes
                        </button>
                        <button class="btn btn-link text-danger btn-sm text-decoration-none opacity-50 hover-opacity-100" onclick="confirmTerminate()">
                            Eliminar Servicio
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
        <div class="d-flex align-items-center gap-2"><div style="width:10px;height:10px;background:#10b981;border-radius:50%"></div><span class="fw-bold text-white">Soporte Sylo</span></div>
        <i class="bi bi-x-lg cursor-pointer" onclick="toggleChat()"></i>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="chat-msg support">
            Hola <?=htmlspecialchars($_SESSION['username']??'Usuario')?>, si tienes preguntas, estas son las más frecuentes:
            <br><br>
            1️⃣ ¿Cómo entro a mi servidor? (SSH)<br>
            2️⃣ ¿Cuál es mi página web?<br>
            3️⃣ Datos de Base de Datos<br>
            4️⃣ ¿Cuántas copias puedo hacer?<br>
            5️⃣ Estado de Salud (CPU/RAM)<br><br>
            Escribe el número para ver la respuesta.
        </div>
    </div>
    <div class="chat-input-area">
        <input type="text" id="chatInput" class="form-control bg-dark border-secondary text-white" placeholder="Escribe..." onkeypress="handleChat(event)">
        <button class="btn btn-primary btn-sm" onclick="sendChat()"><i class="bi bi-send"></i></button>
    </div>
</div>

<div class="modal fade" id="backupTypeModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-hdd-fill me-2"></i>Nueva Snapshot</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="form-label small fw-bold text-light-muted">Nombre de la copia</label><input type="text" id="backup_name_input" class="form-control" placeholder="Ej: Antes de cambios..." maxlength="20"></div><div class="d-flex flex-column gap-2"><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="full" checked class="form-check-input mt-0"><div><div class="fw-bold text-white">Completa (Full)</div><div class="small text-muted" style="font-size:0.75rem">Copia total del disco.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="diff" class="form-check-input mt-0"><div><div class="fw-bold text-white">Diferencial</div><div class="small text-muted" style="font-size:0.75rem">Cambios desde última Full.</div></div></label><label class="backup-option d-flex align-items-center gap-3"><input type="radio" name="backup_type" value="incr" class="form-check-input mt-0"><div><div class="fw-bold text-white">Incremental</div><div class="small text-muted" style="font-size:0.75rem">Solo lo nuevo hoy.</div></div></label></div><div id="limit-alert" class="alert alert-danger small mt-3 mb-0" style="display:none"><i class="bi bi-exclamation-octagon-fill me-1"></i> <strong>Límite alcanzado.</strong><br>Elimina una copia para continuar.</div><div id="normal-alert" class="alert alert-warning small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i> Límite: <strong><?=$backup_limit?> copias</strong>.</div></div><div class="modal-footer border-0 px-4 pb-4"><button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button><button type="button" id="btn-start-backup" onclick="doBackup()" class="btn btn-primary rounded-pill px-4 fw-bold">Iniciar Copia</button></div></div></div></div>
<div class="modal fade" id="editorModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header bg-dark text-white border-bottom border-secondary"><h5 class="modal-title">Editor HTML</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div id="editor"></div></div><div class="modal-footer bg-dark border-top border-secondary"><button class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-primary rounded-pill fw-bold" onclick="saveWeb()"><i class="bi bi-save me-2"></i>Publicar</button></div></div></div></div>
<div class="modal fade" id="uploadModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0"><h5 class="modal-title">Subir Web</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="uploadForm" enctype="multipart/form-data"><input type="file" id="htmlFile" name="html_file" class="form-control mb-3" required><button type="submit" class="btn btn-success w-100 rounded-pill">Subir</button></form></div></div></div></div>
<div class="modal fade" id="profileModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Perfil</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST" action="php/data.php"><input type="hidden" name="action" value="update_profile"><div class="modal-body px-4 pt-4"><div class="mb-3"><label class="small text-light-muted">Nombre</label><input type="text" name="full_name" class="form-control" value="<?=htmlspecialchars($user_info['full_name']??'')?>"></div><div class="mb-3"><label class="small text-light-muted">Email</label><input type="email" name="email" class="form-control" value="<?=htmlspecialchars($user_info['email']??'')?>" required></div><button type="submit" class="btn btn-primary w-100 rounded-pill">Guardar</button></div></form></div></div></div>
<div class="modal fade" id="billingModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0"><div class="modal-header border-0"><h5 class="modal-title">Facturación</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php foreach($clusters as $c): ?><div class="d-flex justify-content-between mb-2"><span>#<?=$c['id']?> <?=$c['plan_name']?></span><span class="text-success"><?=number_format(calculateWeeklyPrice($c),2)?>€</span></div><?php endforeach; ?><hr><div class="d-flex justify-content-between fs-5 text-white"><strong>Total</strong><strong class="text-primary"><?=number_format($total_weekly,2)?>€</strong></div></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const orderId = <?=$current['id']??0?>; 
    const planCpus = <?=$plan_cpus?>; 
    const backupLimit = <?=$backup_limit?>;
    const initialCode = <?php echo json_encode($html_code); ?>;
</script>
<script src="js/control.js"></script>
</body>
</html>
