// behavior: sylo-web/public/js/main.js
// frontend logic for Landing/Login

function openM(id) { const el = document.getElementById(id); if (el) new bootstrap.Modal(el).show(); }
function hideM(id) { const el = document.getElementById(id); const m = bootstrap.Modal.getInstance(el); if (m) m.hide(); }
function openAuth() { openM('authModal'); }
function openLegal() { new bootstrap.Offcanvas(document.getElementById('legalCanvas')).show(); }
function viewTermsFromReg() { hideM('authModal'); openLegal(); }
function toggleTheme() { document.body.dataset.theme = document.body.dataset.theme === 'dark' ? '' : 'dark'; }
function toggleCard(el) { document.querySelectorAll('.card-stack-container').forEach(c => c !== el && c.classList.remove('active')); el.classList.toggle('active'); }
function toggleReg() { const e = document.getElementById('t_e').checked; document.getElementById('fields-emp').style.display = e ? 'block' : 'none'; document.getElementById('fields-auto').style.display = e ? 'none' : 'block'; }
function checkOther() { document.getElementById('reg_rs').style.display = (document.getElementById('reg_tipo_emp').value === 'Otro') ? 'block' : 'none'; }

// --- CALCULADORA (FIXED: UPDATE ON INPUT) ---
document.addEventListener('DOMContentLoaded', () => {
    // Eliminados elementos 'sel-calc-db' y 'sel-calc-web' del array porque no existen en el HTML
    ['in-cpu', 'in-ram', 'check-calc-db', 'check-calc-web'].forEach(id => document.getElementById(id)?.addEventListener('input', () => {
        document.getElementById('calc-preset').value = 'custom'; // Forzar custom al mover
        updCalc();
    }));
    updCalc(); // Init
});

function applyPreset() {
    const p = document.getElementById('calc-preset').value, c = document.getElementById('in-cpu'), r = document.getElementById('in-ram'), d = document.getElementById('check-calc-db'), w = document.getElementById('check-calc-web');
    if (p === 'bronce') { c.value = 1; r.value = 1; d.checked = false; w.checked = false; }
    else if (p === 'plata') { c.value = 2; r.value = 2; d.checked = true; w.checked = false; }
    else if (p === 'oro') { c.value = 3; r.value = 3; d.checked = true; w.checked = true; }
    updCalc();
}
function updCalc() {
    let c = parseInt(document.getElementById('in-cpu').value), r = parseInt(document.getElementById('in-ram').value);
    document.getElementById('c-cpu').innerText = c; document.getElementById('c-ram').innerText = r;

    let d_c = document.getElementById('check-calc-db').checked ? 5 : 0;
    let w_c = document.getElementById('check-calc-web').checked ? 5 : 0;

    renderP((c * 5) + (r * 5) + d_c + w_c + 5);
}
function renderP(val) {
    document.getElementById('out-sylo').innerText = val + "€";
    let aws = Math.round((val * 3.5) + 40), az = Math.round((val * 3.2) + 30), sv = Math.round(((aws - val) / aws) * 100); if (sv > 99) sv = 99;
    document.getElementById('out-aws').innerText = aws + "€"; document.getElementById('out-azure').innerText = az + "€"; document.getElementById('out-save').innerText = sv + "%"; document.getElementById('pb-sylo').style.width = sv + "%"; document.getElementById('pb-aws').style.width = "100%"; document.getElementById('pb-azure').style.width = (az / aws * 100) + "%";
}

// --- DEPLOY LOGIC ---
let curPlan = '';
function prepararPedido(plan) {
    // isLogged is defined in global scope in index.php (view)
    if (typeof isLogged !== 'undefined' && !isLogged) { openAuth(); return; }
    curPlan = plan;
    document.getElementById('m_plan').innerText = plan;

    const selOS = document.getElementById('cfg-os'), mCpu = document.getElementById('mod-cpu'), mRam = document.getElementById('mod-ram'), dbT = document.getElementById('mod-db-type'), webT = document.getElementById('mod-web-type'), cDb = document.getElementById('mod-check-db'), cWeb = document.getElementById('mod-check-web');
    const grpHard = document.getElementById('grp-hardware');

    selOS.innerHTML = "";
    if (plan === 'Bronce') {
        selOS.innerHTML = "<option value='alpine'>Alpine</option>"; selOS.disabled = true;
        grpHard.style.display = "none"; // OCULTAR SLIDERS EN FIJOS
        mCpu.value = 1; mRam.value = 1;
        cDb.checked = false; cWeb.checked = false; cDb.disabled = true; cWeb.disabled = true;
    }
    else if (plan === 'Plata') {
        selOS.innerHTML = "<option value='ubuntu'>Ubuntu</option><option value='alpine'>Alpine</option>"; selOS.disabled = false;
        grpHard.style.display = "none";
        mCpu.value = 2; mRam.value = 2;
        cDb.checked = true; cWeb.checked = false; cDb.disabled = true; cWeb.disabled = true; dbT.disabled = true;
    }
    else if (plan === 'Oro') {
        selOS.innerHTML = "<option value='ubuntu'>Ubuntu</option><option value='alpine'>Alpine</option><option value='redhat'>RedHat</option>"; selOS.disabled = false;
        grpHard.style.display = "none";
        mCpu.value = 3; mRam.value = 3;
        cDb.checked = true; cWeb.checked = true; cDb.disabled = true; cWeb.disabled = true; dbT.disabled = true; webT.disabled = true;
    }
    else { // Custom
        selOS.innerHTML = "<option value='ubuntu'>Ubuntu</option><option value='alpine'>Alpine</option><option value='redhat'>RedHat</option>"; selOS.disabled = false;
        grpHard.style.display = "block"; // MOSTRAR SLIDERS SOLO EN CUSTOM
        mCpu.value = document.getElementById('in-cpu').value; mRam.value = document.getElementById('in-ram').value;
        cDb.disabled = false; cWeb.disabled = false;
        cDb.checked = document.getElementById('check-calc-db').checked;
        cWeb.checked = document.getElementById('check-calc-web').checked;
        dbT.disabled = false; webT.disabled = false;
    }
    updateModalHard(); toggleModalSoft();
    openM('configModal');
}

function updateModalHard() { document.getElementById('lbl-cpu').innerText = document.getElementById('mod-cpu').value; document.getElementById('lbl-ram').innerText = document.getElementById('mod-ram').value; }
function toggleModalSoft() { document.getElementById('mod-db-opts').style.display = document.getElementById('mod-check-db').checked ? 'block' : 'none'; document.getElementById('mod-web-opts').style.display = document.getElementById('mod-check-web').checked ? 'block' : 'none'; }

async function lanzar() {
    const alias = document.getElementById('cfg-alias').value; if (!alias) { alert("Alias obligatorio"); return; }
    const specs = {
        cluster_alias: alias,
        ssh_user: document.getElementById('cfg-ssh-user').value,
        os_image: document.getElementById('cfg-os').value,
        cpu: parseInt(document.getElementById('mod-cpu').value),
        ram: parseInt(document.getElementById('mod-ram').value),
        storage: 25,
        db_enabled: document.getElementById('mod-check-db').checked,
        web_enabled: document.getElementById('mod-check-web').checked,
        db_custom_name: document.getElementById('mod-db-name').value,
        web_custom_name: document.getElementById('mod-web-name').value,
        subdomain: document.getElementById('mod-sub').value || 'interno',
        db_type: document.getElementById('mod-db-type').value,
        web_type: document.getElementById('mod-web-type').value
    };
    hideM('configModal'); openM('progressModal');
    try {
        // Fetch to auth.php
        const res = await fetch('php/auth.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'comprar', plan: curPlan, specs: specs }) });
        const j = await res.json();
        if (j.status === 'success') startPolling(j.order_id, specs); else { hideM('progressModal'); alert(j.mensaje); }
    } catch (e) { hideM('progressModal'); alert("Error"); }
}

function startPolling(oid, finalSpecs) {
    let i = setInterval(async () => {
        // Fetch to auth.php
        const r = await fetch(`php/auth.php?check_status=${oid}`);
        const s = await r.json();
        document.getElementById('prog-bar').style.width = s.percent + "%";
        document.getElementById('progress-text').innerText = s.message;
        if (s.status === 'completed') {
            clearInterval(i); hideM('progressModal');
            document.getElementById('s-plan').innerText = curPlan;
            document.getElementById('s-os').innerText = finalSpecs.os_image;
            document.getElementById('s-cpu').innerText = finalSpecs.cpu;
            document.getElementById('s-ram').innerText = finalSpecs.ram;
            document.getElementById('s-cmd').innerText = s.ssh_cmd;
            document.getElementById('s-pass').innerText = s.ssh_pass;
            openM('successModal');
        }
    }, 1500);
}

async function handleLogin() {
    // Fetch to auth.php
    const r = await fetch('php/auth.php', { method: 'POST', body: JSON.stringify({ action: 'login', email_user: document.getElementById('log_email').value, password: document.getElementById('log_pass').value }) });
    const d = await r.json();
    if (d.status === 'success') location.reload(); else alert(d.mensaje);
}
async function handleRegister() {
    if (!document.getElementById('reg_terms').checked) return;
    const t = document.getElementById('t_a').checked ? 'autonomo' : 'empresa';
    const d = { action: 'register', username: document.getElementById('reg_u').value, email: document.getElementById('reg_e').value, password: document.getElementById('reg_p1').value, password_confirm: document.getElementById('reg_p2').value, telefono: document.getElementById('reg_tel').value, calle: document.getElementById('reg_cal').value, tipo_usuario: t };
    if (t === 'autonomo') { d.full_name = document.getElementById('reg_fn').value; d.dni = document.getElementById('reg_dni_a').value; }
    else { d.contact_name = document.getElementById('reg_contact').value; d.cif = document.getElementById('reg_cif').value; d.dni = d.cif; d.tipo_empresa = document.getElementById('reg_tipo_emp').value; if (d.tipo_empresa === 'Otro') d.company_name = document.getElementById('reg_rs').value; }
    // Fetch to auth.php
    await fetch('php/auth.php', { method: 'POST', body: JSON.stringify(d) });
    location.reload();
}
function logout() {
    // Fetch to auth.php
    fetch('php/auth.php', { method: 'POST', body: JSON.stringify({ action: 'logout' }) }).then(() => location.reload());
}
function copyData() { navigator.clipboard.writeText(document.getElementById('ssh-details').innerText); }
function userMovedSlider() { document.getElementById('calc-preset').value = 'custom'; updCalc(); }
