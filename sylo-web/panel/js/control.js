// behavior: sylo-web/panel/js/control.js
// frontend logic for Dashboard

// Global vars defined in dashboard.php (oid, planCpus, backupLimit) need to be accessible.


let isManualLoading = false;
let pollingInterval = null;
let completionLock = 0; // Previene leer '100%' de ejecuciones anteriores
let stateLockTimestamp = 0; // FIX RACE CONDITION: Ignore 100% for X seconds
let isBackupLoading = false;
let activePowerOp = null;
let powerModal = null;

function sendPowerAction(action) {
    activePowerOp = action;
    const modalEl = document.getElementById('powerModal');
    if (modalEl) {
        powerModal = new bootstrap.Modal(modalEl);
        powerModal.show();

        // Reset UI
        const pBar = document.getElementById('power-progress-bar');
        if (pBar) pBar.style.width = '10%';
        const txt = document.getElementById('power-status-text');

        let reqKey = 'power.requesting';
        txt.setAttribute('data-i18n', reqKey);
        txt.innerHTML = window.SyloLang?.get(reqKey) || "Solicitando...";
    }

    const formData = new FormData();
    formData.append('action', action);
    formData.append('order_id', orderId);

    fetch('php/data.php?ajax_action=1', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(d => { console.log("Power Action Sent", d); })
        .catch(e => console.error("Power Action Error", e));
}

function showToast(msg, type = 'info') {
    const icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-triangle' : 'info-circle');
    const color = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
    const html = `<div class="custom-toast" style="border-left-color:${color}"><i class="bi bi-${icon}" style="color:${color};font-size:1.2rem"></i><div><strong>${window.SyloLang?.get('js.toast_notification') || 'Notificación'}</strong><br><small>${msg}</small></div></div>`;
    const container = document.getElementById('toastContainer');
    const el = document.createElement('div'); el.innerHTML = html;
    container.appendChild(el);
    setTimeout(() => el.remove(), 4000);
    addLog(msg);
}

function addLog(msg) {
    const tbody = document.getElementById('activity-log-body');
    const time = new Date().toLocaleTimeString();
    const row = `<tr><td><span class="text-light-muted small me-2">[${time}]</span> <span class="text-white">${msg}</span></td></tr>`;
    const emptyRow = document.getElementById('log-empty-row');
    if (emptyRow) emptyRow.remove();
    tbody.innerHTML = row + tbody.innerHTML;
}

function toggleChat() { const win = document.getElementById('chatWindow'); win.style.display = win.style.display === 'flex' ? 'none' : 'flex'; }
function handleChat(e) { if (e.key === 'Enter') sendChat(); }

function sendChat() {
    const inp = document.getElementById('chatInput');
    const txt = inp.value.trim();
    if (!txt) return;
    const body = document.getElementById('chatBody');
    body.innerHTML += `<div class="chat-msg me">${txt}</div>`;
    inp.value = '';
    body.scrollTop = body.scrollHeight;
    const thinkingHTML = `<div id="sylo-thinking" class="chat-msg support thinking-bubble"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span id="thinking-text">${window.SyloLang?.get('chat.sending') || 'Enviando...'}</span></div>`;
    body.innerHTML += thinkingHTML;
    body.scrollTop = body.scrollHeight;
    const formData = new FormData();
    formData.append('action', 'send_chat');
    formData.append('order_id', orderId);
    formData.append('message', txt);
    fetch('php/data.php?ajax_action=1', { method: 'POST', body: formData });
}

let aceEditor = null;

document.addEventListener("DOMContentLoaded", function () {
    if (document.getElementById("editor")) {
        aceEditor = ace.edit("editor"); aceEditor.setTheme("ace/theme/twilight"); aceEditor.session.setMode("ace/mode/html"); aceEditor.setOptions({ fontSize: "14pt" }); aceEditor.setValue(initialCode, -1);
    }
});

const editorModalEl = document.getElementById('editorModal');
if (editorModalEl) {
    const editorModal = new bootstrap.Modal(editorModalEl);
    editorModalEl.addEventListener('shown.bs.modal', function () { if (aceEditor) aceEditor.resize(); });
    window.openEditor = function () { editorModal.show(); }
    window.editorModal = editorModal;
}

const uploadModalEl = document.getElementById('uploadModal');
if (uploadModalEl) window.uploadModal = new bootstrap.Modal(uploadModalEl);

const backupModalEl = document.getElementById('backupTypeModal');
if (backupModalEl) window.backupModal = new bootstrap.Modal(backupModalEl);

function showBackupModal() { if (window.backupModal) window.backupModal.show(); }
function copyAllCreds() { navigator.clipboard.writeText(document.getElementById('all-creds-box').innerText); showToast(window.SyloLang?.get('js.copy_success') || "Copiado!", "success"); }

function confirmTerminate() {
    if (prompt("⚠️ ZONA DE PELIGRO ⚠️\n\nEscribe: eliminar") === "eliminar") {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'php/data.php';
        form.innerHTML = `<input type="hidden" name="action" value="terminate"><input type="hidden" name="order_id" value="${orderId}">`;
        document.body.appendChild(form); form.submit();
    } else alert("Cancelado.");
}

function destroyK8s() {
    const msg = window.SyloLang?.get('js.destroy_confirm') || "☢️ ¿ESTÁS SEGURO?\nEsto borrará todos los Pods y datos de Kubernetes.\n\nEscribe: BORRAR";
    const localKeyword = (window.SyloLang?.get('js.destroy_keyword') || "BORRAR").toUpperCase();

    // Master Keywords (Always valid)
    const validKeywords = ["DELETE", "BORRAR", "SUPPRIMER", "ZERSTÖREN", localKeyword];

    let input = prompt(msg);
    if (input) {
        input = input.trim().toUpperCase();
        if (validKeywords.includes(input)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'php/data.php';
            form.innerHTML = `<input type="hidden" name="action" value="destroy_k8s"><input type="hidden" name="order_id" value="${orderId}">`;
            document.body.appendChild(form);
            form.submit();
            showToast(window.SyloLang?.get('js.destroying') || "Destruyendo recursos...", "error");
        } else {
            alert("Palabra incorrecta / Incorrect keyword.");
        }
    }
}

function doBackup() {
    const nameInput = document.getElementById('backup_name_input');
    const name = nameInput.value || "Backup";
    const typeInput = document.querySelector('input[name="backup_type"]:checked');
    const type = typeInput ? typeInput.value : 'full';

    if (window.backupModal) window.backupModal.hide();
    nameInput.value = "";

    document.getElementById('backup-ui').style.display = 'block';
    document.getElementById('backups-list-container').style.display = 'none';
    document.getElementById('backup-bar').style.width = '5%';

    fetch('php/data.php?ajax_action=1', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'backup',
            order_id: orderId,
            backup_type: type,
            backup_name: name
        })
    });

    isBackupLoading = true;
    showToast(`${window.SyloLang?.get('js.backup_start') || 'Iniciando Backup'} (${type.toUpperCase()})...`, "info");
}

function setBtnState(loading, text, percent) {
    const btn = document.getElementById('btn-ver-web');
    const fill = document.getElementById('web-loader-fill');
    const txt = document.getElementById('web-btn-text');
    if (!btn) return;

    if (loading) {
        btn.classList.add('disabled');
        btn.href = "javascript:void(0);";
        if (percent) fill.style.width = percent + '%';
        txt.innerHTML = `<i class="bi bi-arrow-repeat spin me-2"></i>${text || 'Cargando...'}`;
    } else {
        btn.classList.remove('disabled');
        fill.style.width = '0%';
        const label = window.SyloLang?.get('dashboard.view_site') || 'Ver Sitio Web';
        txt.innerHTML = `<i class="bi bi-box-arrow-up-right me-2"></i><span data-i18n="dashboard.view_site">${label}</span>`;
    }
}

function restoreBackup(file, name) {
    if (prompt(`⚠️ RESTAURAR "${name}"?\nEscribe: RESTAURAR`) === "RESTAURAR") {
        isManualLoading = true;
        setBtnState(true, 'Restaurando...', 5);
        fetch('php/data.php?ajax_action=1', { method: 'POST', body: new URLSearchParams({ action: 'restore_backup', order_id: orderId, filename: file }) });
        showToast(`Restaurando...`, "warning");
        setTimeout(() => { isManualLoading = false; }, 60000);
    } else alert("Cancelado.");
}

function deleteBackup(file, type, name) {
    if (confirm(`¿Borrar copia: ${name}?`)) {
        document.getElementById('backups-list-container').style.display = 'none';
        document.getElementById('delete-ui').style.display = 'block';
        document.getElementById('delete-bar').style.width = '100%';
        fetch('php/data.php?ajax_action=1', { method: 'POST', body: new URLSearchParams({ action: 'delete_backup', order_id: orderId, filename: file }) });
        showToast(`Eliminando...`, "warning");
    }
}

function manualRefresh() {
    const btn = document.getElementById('btn-refresh');
    if (btn) btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';
    fetch('php/data.php?ajax_action=1', { method: 'POST', body: new URLSearchParams({ action: 'refresh_status', order_id: orderId }) })
        .then(() => { loadData(); showToast(window.SyloLang?.get('js.updated') || "Datos actualizados", "success"); });
}

function saveWeb() {
    const wbtn = document.getElementById('btn-ver-web');
    isManualLoading = true;
    setBtnState(true, 'Iniciando...', 5);
    if (window.editorModal) window.editorModal.hide();
    fetch('php/data.php?ajax_action=1', { method: 'POST', body: new URLSearchParams({ action: 'update_web', order_id: orderId, html_content: aceEditor.getValue() }) });
    showToast(window.SyloLang?.get('js.publishing') || "Publicando web...", "info");
    setTimeout(() => { isManualLoading = false; }, 60000);
}

const upForm = document.getElementById('uploadForm');
if (upForm) {
    upForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const file = this.querySelector('input[type="file"]').files[0];
        if (file) { const reader = new FileReader(); reader.onload = function (e) { aceEditor.setValue(e.target.result); }; reader.readAsText(file); }
        if (window.uploadModal) window.uploadModal.hide();
        isManualLoading = true;
        completionLock = Date.now() + 2000;
        stateLockTimestamp = Date.now() + 4000; // Ignore completed status for 4s
        setBtnState(true, 'Subiendo...', 5);
        const formData = new FormData(this); formData.append('order_id', orderId); formData.append('action', 'upload_web');
        fetch('php/data.php?ajax_action=1', { method: 'POST', body: formData });
        showToast(window.SyloLang?.get('js.uploaded') || "Archivo subido", "success");
        setTimeout(() => { isManualLoading = false; }, 4000);
    });
}

// --- TRANSLATION HELPER FOR BACKEND STRINGS ---
const BACKEND_MSG_LOOKUP = {
    'Iniciando...': 'backend.starting',
    'Leyendo': 'backend.reading',
    'Comprimiendo...': 'backend.compressing',
    'Guardando': 'backend.saving',
    'Backup Completado': 'backend.completed',
    'Eliminando': 'backend.deleting',
    'Eliminado': 'backend.deleted',
    'Localizando': 'backend.locating',
    'Restaurando...': 'backend.restoring',
    'Procesando...': 'backend.processing',
    'Limpiando': 'backend.cleaning',
    'Subiendo': 'backend.uploading',
    'Aplicando': 'backend.applying',
    'Verificando...': 'backend.verifying',
    'Web Actualizada': 'backend.web_updated',
    'Orden procesada...': 'backend.processing'
};

function translateBackendMsg(msg) {
    if (!msg) return "";
    // 1. Check Exact
    if (BACKEND_MSG_LOOKUP[msg]) return window.SyloLang?.get(BACKEND_MSG_LOOKUP[msg]) || msg;
    // 2. Check Partial
    for (const [key, langKey] of Object.entries(BACKEND_MSG_LOOKUP)) {
        if (msg.includes(key)) return window.SyloLang?.get(langKey) || msg;
    }
    return msg;
}

function loadData() {
    if (!orderId) return;
    fetch(`php/data.php?ajax_data=1&id=${orderId}&t=${new Date().getTime()}`)
        .then(r => r.ok ? r.json() : null)
        .then(d => {
            const rBtn = document.getElementById('btn-refresh'); if (rBtn) rBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i>';
            if (d.terminated) { window.location.href = 'dashboard.php'; return; }
            if (!d) return;

            try {
                // Force metrics to 0 if status is offline/stopped
                if (d.status === 'stopped' || d.status === 'terminated' || d.status === 'offline') {
                    d.metrics = { cpu: 0, ram: 0 };
                }

                if (d.metrics && d.metrics.cpu !== undefined) {
                    const s = (d.status || '').toLowerCase();
                    // FIX: Operations like web_updating should NOT be considered 'stopped'
                    const isStopped = ['stopped', 'offline', 'terminated', 'cancelled'].includes(s) && !['web_updating', 'restoring'].includes(s);

                    let rawCpu = isStopped ? 0 : parseFloat(d.metrics.cpu);
                    let rawRam = isStopped ? 0 : parseFloat(d.metrics.ram);

                    let visualCpu = (rawCpu / planCpus); if (visualCpu > 100) visualCpu = 100;

                    const cVal = document.getElementById('cpu-val'); if (cVal) cVal.innerText = visualCpu.toFixed(1);
                    const cBar = document.getElementById('cpu-bar'); if (cBar) { cBar.style.transition = 'width 0.5s ease'; cBar.style.width = visualCpu + '%'; }
                    const rVal = document.getElementById('ram-val'); if (rVal) rVal.innerText = rawRam.toFixed(1);
                    const rBar = document.getElementById('ram-bar'); if (rBar) { rBar.style.transition = 'width 0.5s ease'; rBar.style.width = rawRam + '%'; }
                }
            } catch (e) { }

            try {
                if (d.status) {
                    // --- POWER & PLAN UPDATE PROGRESS POLLING ---
                    // FIX: Check if we have an active operation OR if backend reports one
                    if (activePowerOp || (d.general_progress && d.general_progress.action === 'plan_update')) {
                        const gp = d.general_progress;

                        // Auto-open modal if plan update detected and not open
                        if (gp && gp.action === 'plan_update' && !powerModal) {
                            const modalEl = document.getElementById('powerModal');
                            if (modalEl) {
                                powerModal = new bootstrap.Modal(modalEl);
                                powerModal.show();
                                // Close Plan Modal if open
                                const planModalEl = document.getElementById('changePlanModal');
                                if (planModalEl) {
                                    const pm = bootstrap.Modal.getInstance(planModalEl);
                                    if (pm) pm.hide();
                                }
                            }
                        }

                        const pBar = document.getElementById('power-progress-bar');
                        const pTxt = document.getElementById('power-status-text');

                        let targetProgress = 10;
                        let isComplete = false;
                        let customMsg = "";

                        // 1. Prioritize REAL PROGRESS from Operator (if API updated)
                        if (gp) {
                            // Check if general_progress matches our power op intent or is plan update
                            if (
                                gp.action === 'plan_update' ||
                                (gp.status && (
                                    gp.status.includes('stopping') || gp.status.includes('starting') || gp.status.includes('restarting') ||
                                    gp.status === 'stopped' || gp.status === 'active' || gp.status === 'online' || gp.status === 'terminated' ||
                                    gp.status === 'started' || gp.status === 'restarted' || gp.status === 'completed'
                                ))) {
                                targetProgress = parseInt(gp.percent) || parseInt(gp.progress) || 10;
                                if (targetProgress >= 100) isComplete = true;
                                if (gp.msg) customMsg = gp.msg;
                            }
                        }

                        // Fallbacks...
                        if (!customMsg) {
                            if (activePowerOp === 'stop' && (d.status === 'stopped' || d.status === 'offline')) isComplete = true;
                            if (activePowerOp === 'start' && (d.status === 'online' || d.status === 'active')) isComplete = true;
                        }

                        let key = 'power.requesting';
                        if (activePowerOp === 'stop') key = 'power.progress_stopping';
                        else if (activePowerOp === 'start') key = 'power.progress_starting';
                        else if (activePowerOp === 'restart') key = 'power.progress_restarting';
                        else if (gp && gp.action === 'plan_update') key = 'plan.updating';

                        pTxt.setAttribute('data-i18n', key);
                        pTxt.innerHTML = customMsg || window.SyloLang?.get(key) || "Procesando...";

                        if (pBar) pBar.style.width = targetProgress + '%';

                        if (isComplete) {
                            activePowerOp = null;
                            setTimeout(() => {
                                if (powerModal) powerModal.hide();
                                showToast(window.SyloLang?.get('power.completed') || "Operación Completada", 'success');
                                if (gp && gp.action === 'plan_update') {
                                    // FIX: Dismiss status file BEFORE reloading to prevent loop
                                    if (pollingInterval) clearInterval(pollingInterval);
                                    fetch('php/data.php?ajax_action=1', {
                                        method: 'POST',
                                        body: new URLSearchParams({ action: 'dismiss_plan', order_id: orderId })
                                    })
                                        .then(() => {
                                            window.location.reload();
                                        })
                                        .catch(() => window.location.reload());
                                }
                            }, 1000);
                        }
                    }
                    // --- END POWER POLLING ---

                    // =========================================================
                    // 🛠️ FIX GEMINI: VISUALIZAR CREACIÓN Y WEB
                    // =========================================================

                    // 1. SI ESTAMOS CREANDO (STATUS: CREATING)
                    // FIX: Don't show modal if it's just a backup (source_type check)
                    if (d.status === 'creating' && d.general_progress && d.general_progress.source_type !== 'backup') {
                        const p = parseInt(d.general_progress.progress) || 0;
                        const m = d.general_progress.message || d.general_progress.msg || "Desplegando infraestructura...";

                        // Reutilizamos el Modal de Energía para mostrar la creación
                        if (!powerModal) {
                            const modalEl = document.getElementById('powerModal');
                            if (modalEl) {
                                powerModal = new bootstrap.Modal(modalEl);
                                powerModal.show();
                            }
                        }

                        // Actualizamos la barra del modal
                        const pBar = document.getElementById('power-progress-bar');
                        const pTxt = document.getElementById('power-status-text');

                        if (pTxt) pTxt.innerText = m;
                        if (pBar) pBar.style.width = p + '%';

                        // Si llega al 100%, recargamos la página
                        if (p >= 100) {
                            setTimeout(() => {
                                if (powerModal) powerModal.hide();
                                window.location.reload();
                            }, 2000);
                        }
                    }

                    // --- FIX 4: VISUALIZAR EDICIÓN WEB (Compatibilidad con nuevo data.php) ---
                    // Auto-detect active update even if user refreshed page (isManualLoading=false)
                    if (d.general_progress) {
                        const gp = d.general_progress;

                        // If an update is ACTIVELY running, force manual mode to show it
                        if (gp.status === 'web_updating' || gp.status === 'restoring') {
                            isManualLoading = true;
                        }

                        // Now verify if we should show visualization
                        if (isManualLoading && (gp.status === 'web_updating' || gp.status === 'restoring' || gp.status === 'web_completed' || gp.status === 'completed')) {

                            // FIX: Stale Status Protection
                            // If we just clicked save (< 4s ago), IGNORE 'completed' or '100%' status from previous runs.
                            // We force it to look like "Iniciando..." until the backend overwrites the file.
                            let ignoreCompletion = (Date.now() < stateLockTimestamp);

                            // FIX: JSON usa 'percent', no 'progress'. Fallback a 0.
                            let p = parseInt(gp.percent || gp.progress) || 0;
                            let m = gp.message || gp.msg || (p >= 100 ? "Completado" : "Procesando...");

                            // Polish: Remove technical text "(CMD 1)..."
                            m = m.replace(/\s*\(CMD \d+\)/, '');

                            // Si el estado dice completado pero el % es 0 o NaN, forzamos 100
                            // EXCEPTO si estamos en tiempo de bloqueo (ignoreCompletion)
                            if ((gp.status === 'web_completed' || gp.status === 'completed' || m.includes('ACTUALIZADA')) && !ignoreCompletion) {
                                p = 100;
                            } else if (ignoreCompletion && p >= 100) {
                                // If locked and reading 100%, FAKE IT as 10%
                                p = 10;
                            }

                            // Force Disable (Visual + Click)
                            setBtnState(true, translateBackendMsg(m), p);
                            const wbtn = document.getElementById('btn-ver-web');
                            if (wbtn) wbtn.style.pointerEvents = 'none'; // CRITICAL: Prevent clicks

                            if (p >= 100 && !ignoreCompletion) {
                                setBtnState(true, translateBackendMsg("Web Actualizada"), 100);
                                setTimeout(() => {
                                    isManualLoading = false;
                                    if (wbtn) wbtn.style.pointerEvents = 'auto'; // Unlock
                                    // Let the main loop update the URL/Status next cycle
                                }, 2000);
                            }
                        }
                    }
                    // =========================================================

                    // --- BADGE UPDATE LOGIC ---
                    const statusSpan = document.getElementById('status-badge-text');
                    if (statusSpan) {
                        // FIX: Treat 'web_updating' as online so badge stays green
                        const isOnline = ['active', 'running', 'online', 'completed', 'web_updating', 'web_completed', 'restoring', 'started', 'restarted'].includes(d.status);
                        const statusKey = isOnline ? 'dashboard.online' : 'dashboard.stopped';
                        const statusText = window.SyloLang?.get(statusKey) || d.status.toUpperCase();

                        statusSpan.setAttribute('data-i18n', statusKey);
                        statusSpan.innerText = statusText;

                        const badge = statusSpan.closest('.badge');
                        if (badge) {
                            badge.className = `badge px-3 py-2 rounded-pill shadow-sm border ${isOnline ? 'bg-success bg-opacity-10 text-success border-success' : 'bg-danger bg-opacity-10 text-danger border-danger'}`;
                        }

                        // Buttons Vis
                        const activeCtrls = document.getElementById('controls-active');
                        const inactiveCtrls = document.getElementById('controls-inactive');
                        if (activeCtrls && inactiveCtrls) {
                            if (isOnline) {
                                activeCtrls.style.display = 'block';
                                inactiveCtrls.style.display = 'none';
                            } else {
                                activeCtrls.style.display = 'none';
                                inactiveCtrls.style.display = 'block';
                            }
                        }
                    }

                }
            } catch (e) { }

            // SSH / Credentials
            const cmd = document.getElementById('disp-ssh-cmd'); if (cmd && d.ssh_cmd && d.ssh_cmd.length > 2) cmd.innerText = d.ssh_cmd;
            const pass = document.getElementById('disp-ssh-pass'); if (pass && d.ssh_pass && d.ssh_pass.length > 2) pass.innerText = d.ssh_pass;

            // Web URL Button logic
            const wUrl = d.web_url;
            try {
                if (!isManualLoading) {
                    const btn = document.getElementById('btn-ver-web');
                    const dispUrl = document.getElementById('disp-web-url');

                    if (wUrl && wUrl.length > 5 && wUrl !== "No Web Service") {
                        if (dispUrl) { dispUrl.href = wUrl; dispUrl.innerText = wUrl; }
                        if (btn) {
                            btn.href = wUrl;
                            btn.target = "_blank";
                            const txt = document.getElementById('web-btn-text');
                            if (btn.classList.contains('disabled')) setBtnState(false);
                        }
                    } else {
                        // Keep disabled if not ready
                    }
                }
            } catch (e) { }

            // Backups Progress & List
            try {
                const pb = d.general_progress; // Mapped from status file
                const bUi = document.getElementById('backup-ui');
                const bBar = document.getElementById('backup-bar');
                const dUi = document.getElementById('delete-ui');
                const dBar = document.getElementById('delete-bar');
                const list = document.getElementById('backups-list-container');

                // BACKUP / RESTORE / DELETE VISUALIZATION (Aggressive Reset Logic)
                let showB = false;
                let showD = false;

                // Detectamos si hay una operación de backup activa
                if (pb && (pb.status === 'backup_processing' || pb.status === 'creating' || pb.status === 'restoring' || (pb.msg && (pb.msg.includes('Backup') || pb.msg.includes('Snapshot') || pb.msg.includes('Restaurando') || pb.msg.includes('Eliminando'))))) {

                    const m = pb.msg || "Procesando...";
                    const isDelete = m.includes('Eliminando');

                    // Mark which UI should be visible
                    if (isDelete) showD = true;
                    else showB = true;

                    const p = parseInt(pb.progress) || 0;
                    const uiToShow = isDelete ? dUi : bUi;
                    const bar = isDelete ? dBar : bBar;
                    const txt = document.getElementById('backup-status-text');

                    // If active, ensure hidden elements are hidden and list is hidden
                    if (list) list.style.display = 'none';


                    // Update Active UI
                    if (uiToShow) uiToShow.style.display = 'block';
                    if (bar) bar.style.width = p + '%';

                    // TRANSLATE MESSAGE
                    const translatedMsg = translateBackendMsg(m);
                    if (txt && !isDelete) txt.innerText = translatedMsg;

                    // Completion Logic
                    if (p >= 100) {
                        if (bar) { bar.className = 'progress-bar bg-success'; bar.style.width = '100%'; }
                        if (txt && !isDelete) txt.innerText = window.SyloLang?.get('backend.completed') || "Completado";

                        // AUTO-DISMISS & CLEANUP (Frontend-Driven)
                        // Wait 4s, then tell backend to delete file AND hide locally
                        if (isBackupLoading) {
                            isBackupLoading = false;
                            setTimeout(() => {
                                // 1. Tell Server to delete file
                                fetch('php/data.php?ajax_action=1', {
                                    method: 'POST',
                                    body: new URLSearchParams({ action: 'dismiss_backup', order_id: orderId })
                                });
                                // 2. Refresh List (which will now be clean)
                                loadData();
                            }, 4000);
                        }
                    } else {
                        // If strictly processing (<100), stop here to prevent list flicker
                        // But since we control list.style.display above, we can just proceed.
                    }
                }

                // FINAL CLEANUP: If not flagged, HIDE THEM forcefully
                if (!showB && bUi) bUi.style.display = 'none';
                if (!showD && dUi) dUi.style.display = 'none';

                // LIST LOGIC: Show list if NOT strictly processing (i.e. idle OR completed 100%)
                // If showB/showD is true AND p < 100, list is hidden above.
                // If showB/showD is true AND p >= 100, list should be visible.
                // If showB/showD is false, list should be visible.

                // Simplified: If (Active AND < 100), List is Hidden. Else List is Visible.
                let isProcessing = (showB || showD) && (pb && parseInt(pb.progress) < 100);
                if (list && !isProcessing) {
                    list.style.display = 'block';
                    if (bBar) bBar.className = 'progress-bar bg-info'; // Reset bar color for next time
                }

                const countEl = document.getElementById('backup-count');
                if (countEl && d.backups_list) countEl.innerText = `${d.backups_list.length}/${backupLimit}`;

                if (d.backups_list) {
                    let html = '';
                    [...d.backups_list].reverse().forEach(b => {
                        let typeClass = b.type == 'FULL' ? 'bg-primary' : (b.type == 'DIFF' ? 'bg-warning text-dark' : 'bg-info text-dark');
                        html += `<div class="backup-item d-flex justify-content-between align-items-center mb-2 p-2 rounded" style="background:rgba(255,255,255,0.05)"><div class="text-white"><span class="fw-bold">${b.name.replace(/'/g, "")}</span> <span class="badge ${typeClass} ms-2">${b.type.toUpperCase()}</span><div class="small text-light-muted">${b.date}</div></div><div class="d-flex gap-2"><button onclick="restoreBackup('${b.file}', '${b.name.replace(/'/g, "")}')" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i></button><button onclick="deleteBackup('${b.file}', '${b.type}', '${b.name.replace(/'/g, "")}')" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></div></div>`;
                    });
                    list.innerHTML = html || `<div class="p-4 text-center border-dashed rounded text-light-muted small"><i class="bi bi-inbox me-2"></i><span data-i18n="dashboard.no_snapshots">${window.SyloLang?.get('dashboard.no_snapshots') || 'Sin copias disponibles.'}</span></div>`;
                }

            } catch (e) { }

            // Installed Software
            try {
                const swContainer = document.getElementById('installed-software-container');
                if (swContainer) {
                    // FIX: Filter out backend's hardcoded "No se han detectado..." string
                    // This ensures we fallback to our frontend translation
                    let cleanSoftware = [];
                    if (Array.isArray(d.installed_tools) || Array.isArray(d.installed_software)) {
                        let rawArr = d.installed_tools || d.installed_software;
                        cleanSoftware = rawArr.filter(s =>
                            s && !s.includes('No se han detectado') && !s.includes('Ninguna')
                        );
                    }

                    if (cleanSoftware.length > 0) {
                        // Show Parent Card
                        const parentCard = swContainer.closest('.card-clean');
                        if (parentCard) parentCard.style.display = 'block';

                        swContainer.innerHTML = cleanSoftware.map(s => `<span class="badge bg-secondary me-1">${s}</span>`).join('');
                    } else {
                        // FIX: User wants this Hardcoded to ENGLISH always. And Box VISIBLE.
                        const parentCard = swContainer.closest('.card-clean');
                        if (parentCard) parentCard.style.display = 'block';

                        // Hardcoded English Message
                        swContainer.innerHTML = `<div class="text-light-muted small"><i class="bi bi-info-circle me-1"></i> No installed software detected.</div>`;
                    }
                }
            } catch (e) { }

            // Chat
            try {
                const chatBubble = document.getElementById('sylo-thinking'); const chatText = document.getElementById('thinking-text');
                if (chatBubble && d.chat_status) chatText.innerText = d.chat_status;
                if (d.chat_reply) {
                    if (chatBubble) chatBubble.remove();
                    const body = document.getElementById('chatBody');
                    if (body) {
                        body.innerHTML += `<div class="chat-msg support">${d.chat_reply}</div>`;
                        body.scrollTop = body.scrollHeight;
                        showToast(window.SyloLang?.get('chat.received') || "Mensaje de soporte recibido", "info");
                    }
                }
            } catch (e) { }

        })
        .catch(err => { console.log("Esperando datos...", err); });
}
pollingInterval = setInterval(loadData, 500);

function setBtnState(disabled, text = null, progress = null) {
    const btn = document.getElementById('btn-ver-web');
    const txt = document.getElementById('web-btn-text');
    if (!btn) return;

    if (disabled) {
        btn.classList.add('disabled');
        btn.setAttribute('aria-disabled', 'true');
        btn.style.pointerEvents = 'none'; // Force block cliks
    } else {
        btn.classList.remove('disabled');
        btn.removeAttribute('aria-disabled');
        btn.style.pointerEvents = 'auto'; // Force enable clicks

        // Restore default text if enabling and no specific text is passed
        // This ensures it doesn't get stuck on "Web Actualizada"
        if (!text && txt) {
            const label = window.SyloLang?.get('dashboard.view_site') || 'Ver Sitio Web';
            txt.innerHTML = `<i class="bi bi-box-arrow-up-right me-2"></i><span data-i18n="dashboard.view_site">${label}</span>`;
        }
    }

    if (text && txt) {
        if (progress !== null && progress !== undefined) {
            // Visualize progress in text or button background could be cool, but simple text for now
            txt.innerHTML = `${text} (${progress}%)`;
        } else {
            txt.innerHTML = text;
        }
    }
}

function submitPlanChange() {
    const form = document.getElementById('planForm');
    if (!form) return;

    const planInput = document.getElementById('selectedPlanInput');
    const planName = planInput ? planInput.value : "Nuevo Plan";

    // Warn about backups deletion
    const warningMsg = `⚠️ ADVERTENCIA: CAMBIO DE PLAN\n\nEstás a punto de cambiar al plan: ${planName}.\n\nIMPORTANTE: Si seleccionas un plan inferior al actual, el límite de copias de seguridad podría reducirse. El sistema borrará AUTOMÁTICAMENTE las copias más antiguas para ajustarse al nuevo espacio.\n\n¿Deseas continuar?`;

    if (!confirm(warningMsg)) {
        return;
    }

    // 1. Hide Plan Modal
    const planModalEl = document.getElementById('changePlanModal');
    if (planModalEl) {
        const modal = bootstrap.Modal.getInstance(planModalEl);
        if (modal) modal.hide();
    }

    // 2. Show Loading Modal (Immediate Feedback)
    const powerModalEl = document.getElementById('powerModal');
    if (powerModalEl) {
        // Initialize if not exists
        if (!powerModal) powerModal = new bootstrap.Modal(powerModalEl);
        powerModal.show();

        // Update Text
        const pBar = document.getElementById('power-progress-bar');
        const pTxt = document.getElementById('power-status-text');

        if (pBar) pBar.style.width = '5%';
        if (pTxt) {
            pTxt.innerText = "Iniciando cambio de plan...";
            // Try to use translation key if available, otherwise keep text
            pTxt.setAttribute('data-i18n', 'plan.updating');
        }
    }

    // 3. Submit via AJAX
    const formData = new FormData(form);
    // data.php expects 'action' which is in the form

    fetch('php/data.php?ajax_action=1', {
        method: 'POST',
        body: formData
    })
        .then(r => r.json()) // Expecting JSON {status: 'ok'}
        .then(data => {
            console.log("Plan Update Request Sent", data);
            // The existing polling loop in loadData() checks for d.general_progress.action === 'plan_update'
            // and will keep the modal open and updating until completion.

            // Add artificial delay to show 10% progress at least before polling takes over
            const pBar = document.getElementById('power-progress-bar');
            if (pBar) pBar.style.width = '10%';

        })
        .catch(err => {
            console.error("Plan Update Error", err);
            alert("Error al comunicarse con el servidor. Por favor recarga la página.");
            if (powerModal) powerModal.hide();
        });
}