// behavior: sylo-web/panel/js/control.js
// frontend logic for Dashboard

// Global vars defined in dashboard.php (oid, planCpus, backupLimit) need to be accessible.
// Since this script is loaded after PHP var definition in view, it should be fine.

let isManualLoading = false;

function showToast(msg, type = 'info') {
    const icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-triangle' : 'info-circle');
    const color = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
    const html = `<div class="custom-toast" style="border-left-color:${color}"><i class="bi bi-${icon}" style="color:${color};font-size:1.2rem"></i><div><strong>Notificación</strong><br><small>${msg}</small></div></div>`;
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
    if (tbody.innerHTML.includes('Esperando')) tbody.innerHTML = '';
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
    const thinkingHTML = `<div id="sylo-thinking" class="chat-msg support thinking-bubble"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span id="thinking-text">Enviando...</span></div>`;
    body.innerHTML += thinkingHTML;
    body.scrollTop = body.scrollHeight;
    const formData = new FormData();
    formData.append('action', 'send_chat');
    formData.append('order_id', orderId); // Using orderId from global
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
    window.editorModal = editorModal; // Expose for other functions
}

const uploadModalEl = document.getElementById('uploadModal');
if (uploadModalEl) window.uploadModal = new bootstrap.Modal(uploadModalEl);

const backupModalEl = document.getElementById('backupTypeModal');
if (backupModalEl) window.backupModal = new bootstrap.Modal(backupModalEl);

function showBackupModal() { if (window.backupModal) window.backupModal.show(); }
function copyAllCreds() { navigator.clipboard.writeText(document.getElementById('all-creds-box').innerText); showToast("Copiado!", "success"); }

function confirmTerminate() {
    if (prompt("⚠️ ZONA DE PELIGRO ⚠️\n\nEscribe: eliminar") === "eliminar") {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'php/data.php'; // Updated action to point to data.php
        form.innerHTML = `<input type="hidden" name="action" value="terminate"><input type="hidden" name="order_id" value="${orderId}">`;
        document.body.appendChild(form); form.submit();
    } else alert("Cancelado.");
}

// 🔥 NUEVA FUNCIÓN DESTROY K8S
function destroyK8s() {
    if (prompt("☢️ ¿ESTÁS SEGURO?\nEsto borrará todos los Pods y datos de Kubernetes.\n\nEscribe: BORRAR") === "BORRAR") {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'php/data.php'; // Updated action
        form.innerHTML = `<input type="hidden" name="action" value="destroy_k8s"><input type="hidden" name="order_id" value="${orderId}">`;
        document.body.appendChild(form);
        form.submit();
        showToast("Destruyendo recursos...", "error");
    }
}

function doBackup() {
    const nameInput = document.getElementById('backup_name_input');
    const name = nameInput.value || "Backup";

    // RECUPERAR EL TIPO CORRECTO
    const typeInput = document.querySelector('input[name="backup_type"]:checked');
    const type = typeInput ? typeInput.value : 'full';

    if (window.backupModal) window.backupModal.hide();
    nameInput.value = "";

    document.getElementById('backup-ui').style.display = 'block';
    document.getElementById('backups-list-container').style.display = 'none';
    document.getElementById('backup-bar').style.width = '5%';

    // Fetch to php/data.php
    fetch('php/data.php?ajax_action=1', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'backup',
            order_id: orderId,
            backup_type: type,
            backup_name: name
        })
    });

    showToast(`Iniciando Backup (${type.toUpperCase()})...`, "info");
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
        txt.innerHTML = `<i class="bi bi-arrow-repeat spin me-2"></i>${text}`;
    } else {
        btn.classList.remove('disabled');
        fill.style.width = '0%';
        txt.innerHTML = `<i class="bi bi-box-arrow-up-right me-2"></i>Ver Sitio Web`;
    }
}

function restoreBackup(file, name) {
    if (prompt(`⚠️ RESTAURAR "${name}"?\nEscribe: RESTAURAR`) === "RESTAURAR") {
        isManualLoading = true;
        setBtnState(true, 'Restaurando...', 5);
        fetch('php/data.php?ajax_action=1', { method: 'POST', body: new URLSearchParams({ action: 'restore_backup', order_id: orderId, filename: file }) });
        showToast(`Restaurando...`, "warning");
        setTimeout(() => { isManualLoading = false; }, 4000);
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
        .then(() => { loadData(); showToast("Datos actualizados", "success"); });
}

function saveWeb() {
    const wbtn = document.getElementById('btn-ver-web');
    isManualLoading = true;
    setBtnState(true, 'Iniciando...', 5);
    if (window.editorModal) window.editorModal.hide();
    fetch('php/data.php?ajax_action=1', { method: 'POST', body: new URLSearchParams({ action: 'update_web', order_id: orderId, html_content: aceEditor.getValue() }) });
    showToast("Publicando web...", "info");
    setTimeout(() => { isManualLoading = false; }, 4000);
}

const upForm = document.getElementById('uploadForm');
if (upForm) {
    upForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const file = this.querySelector('input[type="file"]').files[0];
        if (file) { const reader = new FileReader(); reader.onload = function (e) { aceEditor.setValue(e.target.result); }; reader.readAsText(file); }
        if (window.uploadModal) window.uploadModal.hide();
        isManualLoading = true;
        setBtnState(true, 'Subiendo...', 5);
        const formData = new FormData(this); formData.append('order_id', orderId); formData.append('action', 'upload_web');
        fetch('php/data.php?ajax_action=1', { method: 'POST', body: formData });
        showToast("Archivo subido", "success");
        setTimeout(() => { isManualLoading = false; }, 4000);
    });
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
                if (d.metrics) {
                    let rawCpu = parseFloat(d.metrics.cpu); let visualCpu = (rawCpu / planCpus); if (visualCpu > 100) visualCpu = 100;
                    const cVal = document.getElementById('cpu-val'); if (cVal) cVal.innerText = visualCpu.toFixed(1);
                    const cBar = document.getElementById('cpu-bar'); if (cBar) cBar.style.width = visualCpu + '%';
                    const rVal = document.getElementById('ram-val'); if (rVal) rVal.innerText = parseFloat(d.metrics.ram).toFixed(1);
                    const rBar = document.getElementById('ram-bar'); if (rBar) rBar.style.width = parseFloat(d.metrics.ram) + '%';
                }
            } catch (e) { }

            const cmd = document.getElementById('disp-ssh-cmd'); if (cmd) cmd.innerText = d.ssh_cmd || '...';
            const pass = document.getElementById('disp-ssh-pass'); if (pass) pass.innerText = d.ssh_pass || '...';

            const wUrl = d.web_url;
            try {
                if (wUrl) {
                    const dispUrl = document.getElementById('disp-web-url');
                    if (dispUrl) { dispUrl.innerText = wUrl; dispUrl.href = wUrl; }
                } else {
                    const dispUrl = document.getElementById('disp-web-url');
                    if (dispUrl) dispUrl.innerText = "Esperando IP...";
                }
            } catch (e) { }

            const bUi = document.getElementById('backup-ui'); const bBar = document.getElementById('backup-bar');
            const dUi = document.getElementById('delete-ui'); const dBar = document.getElementById('delete-bar');
            const list = document.getElementById('backups-list-container');

            const pw = d.web_progress;
            const pb = d.backup_progress;

            if ((pw && pw.status === 'web_updating') || (pb && pb.status === 'restoring')) {
                isManualLoading = false;
                const p = pw || pb;
                setBtnState(true, p.msg, p.progress);
            }
            else if ((pw && pw.status === 'web_completed') || (pb && pb.status === 'completed' && document.getElementById('web-btn-text').innerText.includes('Restaurando'))) {
                isManualLoading = false;
                setBtnState(false);
                if (wUrl) {
                    const btn = document.getElementById('btn-ver-web');
                    btn.href = wUrl;
                    btn.target = "_blank";
                }
            }
            else {
                if (!isManualLoading) {
                    const btn = document.getElementById('btn-ver-web');
                    if (wUrl && wUrl.length > 5 && btn.classList.contains('disabled')) {
                        setBtnState(false);
                        btn.href = wUrl;
                        btn.target = "_blank";
                    }
                }
            }

            try {
                if (pb && pb.status === 'creating') {
                    if (bUi) bUi.style.display = 'block'; if (dUi) dUi.style.display = 'none'; if (list) list.style.display = 'none';
                    if (bBar) bBar.style.width = pb.progress + '%';
                } else if (pb && pb.status === 'deleting') {
                    if (bUi) bUi.style.display = 'none'; if (dUi) dUi.style.display = 'block'; if (list) list.style.display = 'none';
                    if (dBar) dBar.style.width = pb.progress + '%';
                } else if (!pb || pb.status === 'completed' || pb.status === 'error') {
                    if (bUi) bUi.style.display = 'none'; if (dUi) dUi.style.display = 'none'; if (list) list.style.display = 'block';

                    document.getElementById('backup-count').innerText = `${d.backups_list.length}/${backupLimit}`;
                    let html = '';
                    [...d.backups_list].reverse().forEach(b => {
                        let typeClass = b.type == 'FULL' ? 'bg-primary' : (b.type == 'DIFF' ? 'bg-warning text-dark' : 'bg-info text-dark');
                        html += `<div class="backup-item d-flex justify-content-between align-items-center mb-2 p-2 rounded" style="background:rgba(255,255,255,0.05)"><div class="text-white"><span class="fw-bold">${b.name.replace(/'/g, "")}</span> <span class="badge ${typeClass} ms-2">${b.type.toUpperCase()}</span><div class="small text-light-muted">${b.date}</div></div><div class="d-flex gap-2"><button onclick="restoreBackup('${b.file}', '${b.name.replace(/'/g, "")}')" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i></button><button onclick="deleteBackup('${b.file}', '${b.type}', '${b.name.replace(/'/g, "")}')" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></div></div>`;
                    });
                    list.innerHTML = html || '<small class="text-light-muted d-block text-center py-2">Sin copias disponibles.</small>';
                }
            } catch (e) { }

            try {
                const chatBubble = document.getElementById('sylo-thinking'); const chatText = document.getElementById('thinking-text');
                if (chatBubble && d.chat_status) chatText.innerText = d.chat_status;
                if (d.chat_reply) { if (chatBubble) chatBubble.remove(); const body = document.getElementById('chatBody'); body.innerHTML += `<div class="chat-msg support">${d.chat_reply}</div>`; body.scrollTop = body.scrollHeight; showToast("Mensaje de soporte recibido", "info"); }
            } catch (e) { }

        }).catch(err => { console.log("Esperando datos...", err); });
}
setInterval(loadData, 1500);
