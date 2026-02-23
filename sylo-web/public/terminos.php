<?php
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
    
    <style>
        :root { --sylo-bg: #f8fafc; --sylo-text: #334155; --sylo-card: #ffffff; --sylo-accent: #2563eb; --input-bg: #f1f5f9; }
        [data-theme="dark"] { --sylo-bg: #0f172a; --sylo-text: #f1f5f9; --sylo-card: #1e293b; --sylo-accent: #3b82f6; --input-bg: #334155; }
        
        body { font-family: 'Inter', sans-serif; background-color: var(--sylo-bg); color: var(--sylo-text); transition: 0.3s; }
        .navbar { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(0,0,0,0.05); }
        [data-theme="dark"] .navbar { background: rgba(15, 23, 42, 0.95); border-bottom: 1px solid rgba(255,255,255,0.1); }
        [data-theme="dark"] .navbar-brand, [data-theme="dark"] .nav-link { color: white !important; }
        
        .info-card, .bg-white { background-color: var(--sylo-card) !important; color: var(--sylo-text); }
        [data-theme="dark"] .text-muted { color: #94a3b8 !important; }
        [data-theme="dark"] .bg-light { background-color: #1e293b !important; border-color: #334155; }
        [data-theme="dark"] .form-control, [data-theme="dark"] .form-select { background-color: var(--input-bg); border-color: #475569; color: white; }
        [data-theme="dark"] .modal-content { background-color: var(--sylo-card); color: white; }
        
        .hero { padding: 140px 0 100px; background: linear-gradient(180deg, var(--sylo-bg) 0%, var(--sylo-card) 100%); }
        
        .calc-box { background: #1e293b; border-radius: 24px; padding: 40px; color: white; border: 1px solid #334155; }
        .price-display { font-size: 3.5rem; font-weight: 800; color: var(--sylo-accent); }
        
        input[type=range] { -webkit-appearance: none; width: 100%; background: transparent; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; height: 20px; width: 20px; border-radius: 50%; background: #3b82f6; cursor: pointer; margin-top: -8px; box-shadow: 0 0 10px #3b82f6; }
        input[type=range]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #475569; border-radius: 2px; }
        input[type=range]:focus::-webkit-slider-runnable-track { background: #3b82f6; }

        .card-stack-container { perspective: 1500px; height: 600px; cursor: pointer; position: relative; margin-bottom: 30px; }
        .card-face { width: 100%; height: 100%; position: relative; transform-style: preserve-3d; transition: transform 0.8s; border-radius: 24px; }
        .card-stack-container.active .card-face { transform: rotateY(180deg); }
        .face-front, .face-back { position: absolute; width: 100%; height: 100%; top:0; left:0; backface-visibility: hidden; border-radius: 24px; padding: 30px; display: flex; flex-direction: column; justify-content: space-between; background: var(--sylo-card); border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .face-front { z-index: 2; transform: rotateY(0deg); pointer-events: auto; }
        .face-back { z-index: 1; transform: rotateY(180deg); background: var(--sylo-bg); border-color: var(--sylo-accent); pointer-events: none; }
        .card-stack-container.active .face-front { pointer-events: none; }
        .card-stack-container.active .face-back { pointer-events: auto; }
        
        .bench-bar { height: 35px; border-radius: 8px; display: flex; align-items: center; padding: 0 15px; color: white; margin-bottom: 8px; transition: width 1s; }
        .b-sylo { background: linear-gradient(90deg, #2563eb, #3b82f6); }
        .b-aws { background: #64748b; }
        .success-box { background: black; color: white; border-radius: 8px; padding: 20px; font-family: 'JetBrains Mono', monospace; text-align: left; }
        .status-dot { width: 10px; height: 10px; background-color: #22c55e; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0% {box-shadow:0 0 0 0 rgba(34,197,94,0.7);} 70% {box-shadow:0 0 0 6px rgba(34,197,94,0);} 100% {box-shadow:0 0 0 0 rgba(34,197,94,0);} }
        
        .modal-content { border:none; border-radius: 16px; }
        .avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 15px; }
        .k8s-specs li { display: flex; justify-content: space-between; border-bottom: 1px dashed #cbd5e1; padding: 8px 0; font-size: 0.9rem; }
        
        /* SYLO TOOL CHIPS */
        .tool-opt { cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid #e2e8f0; background: #fff; padding: 6px 12px; border-radius: 8px; display: inline-flex; align-items: center; font-size: 0.85rem; font-weight: 600; color: #64748b; user-select: none; }
        .tool-opt:hover { transform: translateY(-1px); border-color: var(--sylo-accent); color: var(--sylo-accent); }
        .tool-opt input { display: none; } /* Hide checkbox */
        
        /* Active State with Animation */
        .tool-opt.active { background: var(--sylo-accent); color: white; border-color: var(--sylo-accent); box-shadow: 0 4px 6px rgba(37, 99, 235, 0.2); animation: syloBounce 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        
        .tool-opt.disabled { opacity: 0.4; pointer-events: none; background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; }
        .tool-opt i { margin-right: 6px; }

        @keyframes syloBounce {
            0% { transform: scale(1); }
            40% { transform: scale(0.92); }
            70% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pass-wrapper { position: relative; }
        .eye-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #94a3b8; z-index: 10; }
        .eye-icon:hover { color: var(--sylo-accent); }
        /* Ensure Message Modal is always on top of Auth Modal */
        #messageModal { z-index: 1090 !important; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="index.php"><i class="fas fa-cube me-2"></i>SYLO</a>
            <div class="collapse navbar-collapse justify-content-center" id="mainNav">
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link nav-link p-0" onclick="toggleTheme()"><i class="fas fa-moon fa-lg"></i></button>
                <script src="js/lang.js"></script>
                <div class="lang-selector">
                    <button class="lang-btn lang-toggle-btn">
                        <span class="current-lang-flag">🇪🇸</span> <span class="current-lang-label fw-bold">ES</span> <i class="fas fa-chevron-down small" style="font-size:0.7em"></i>
                    </button>
                    <div class="lang-dropdown">
                        <button class="lang-opt" onclick="SyloLang.setLanguage('es')"><span class="me-2">🇪🇸</span> Español</button>
                        <button class="lang-opt" onclick="SyloLang.setLanguage('en')"><span class="me-2">🇬🇧</span> English</button>
                        <button class="lang-opt" onclick="SyloLang.setLanguage('fr')"><span class="me-2">🇫🇷</span> Français</button>
                        <button class="lang-opt" onclick="SyloLang.setLanguage('de')"><span class="me-2">🇩🇪</span> Deutsch</button>
                    </div>
                </div>

                <div class="d-none d-md-flex align-items-center cursor-pointer" onclick="new bootstrap.Modal('#statusModal').show()">
                    <div class="status-dot me-2"></div><span class="small fw-bold text-success" data-i18n="dashboard.online">Status</span>
                </div>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="../panel/dashboard.php" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" data-i18n="nav.console">CONSOLA</a>
                    <button class="btn btn-link text-danger btn-sm" onclick="logout()"><i class="fas fa-sign-out-alt"></i></button>
                <?php else: ?>
                    <button class="btn btn-outline-primary btn-sm rounded-pill px-4 fw-bold" onclick="openM('authModal')" data-i18n="nav.login">CLIENTE</button>
                <?php endif; ?>
                
            </div>
        </div>
    </nav>

<div class="container py-5 mt-5">
    <div class="row pt-5">
        <div class="col-lg-8 mx-auto bg-white p-5 rounded-4 shadow-sm border">
            <h1 class="fw-bold text-primary mb-4" data-i18n="legal.title">Contrato de Servicios y Términos</h1>

        <h5 data-i18n="legal.t1">1. Identidad y Objeto</h5><p data-i18n="legal.d1">SYLO CORP S.L. es una Sociedad Limitada registrada en Alicante (CIF B-12345678). El presente contrato regula el uso de la plataforma Oktopus™ V21. Al contratar, acepta estas condiciones sin reservas.</p>
        <h5 data-i18n="legal.t2">2. Hardware Ryzen Garantizado</h5><p data-i18n="legal.d2">Garantizamos el uso exclusivo de procesadores AMD Ryzen™ Threadripper™ de última generación. Los recursos contratados (vCPU) corresponden a hilos de ejecución físicos y no virtuales. No realizamos "overselling" agresivo.</p>
        <h5 data-i18n="legal.t3">3. Política de Uso Aceptable (AUP) - Kubernetes</h5><p data-i18n="legal.d3">Queda terminantemente prohibido el uso para: Minería de criptomonedas, ataques DDoS, intrusiones en redes ajenas, y alojamiento de contenido ilegal. La detección resultará en la terminación inmediata.</p>
        <h5 data-i18n="legal.t4">4. Privacidad y Protección de Datos</h5><p data-i18n="legal.d4">En cumplimiento con el RGPD (UE 2016/679): Sus datos se almacenan cifrados en reposo (AES-256) en Alicante. No realizamos inspección profunda de paquetes (DPI).</p>
        <h5 data-i18n="legal.t5">5. Acuerdo de Nivel de Servicio (SLA)</h5><p data-i18n="legal.d5">Garantizamos un 99.9% de disponibilidad mensual. En caso de incumplimiento, se compensará con créditos de servicio.</p>
        <h5 data-i18n="legal.t6">6. Pagos y Suspensión</h5><p data-i18n="legal.d6">El servicio es prepago. El impago resultará en la suspensión del servicio a las 48 horas y el borrado de datos a los 15 días.</p>
        <h5 data-i18n="legal.t7">7. Responsabilidad</h5><p data-i18n="legal.d7">Sylo no será responsable de pérdidas de datos derivadas de una mala configuración por parte del usuario o vulnerabilidades en su software.</p>
        <h5 data-i18n="legal.t8">8. Jurisdicción</h5><p data-i18n="legal.d8">Las partes se someten a los juzgados de Alicante, España.</p>
    </div>
            <div class="mt-5 text-center">
                <a href="index.php" class="btn btn-outline-primary rounded-pill px-4" data-i18n="corp.btn_back_home">Volver al Inicio</a>
            </div>
        </div>
    </div>
</div>
<footer class="py-5 bg-light border-top mt-5"><div class="container text-center"><h5 class="fw-bold text-primary mb-3">SYLO CORP S.L.</h5><div class="mb-4"><a href="mailto:arlanzonivan@gmail.com" class="text-muted mx-2 text-decoration-none">arlanzonivan@gmail.com</a><a href="mailto:leob@gmail.com" class="text-muted mx-2 text-decoration-none">leob@gmail.com</a></div><button class="btn btn-link text-muted small" onclick="openLegal()" data-i18n="footer.terms">TÉRMINOS Y CONDICIONES</button></div></footer>
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
    function openLegal(){new bootstrap.Offcanvas(document.getElementById('legalCanvas')).show();}
    function viewTermsFromReg(){openLegal();} // No ocultamos authModal para no romper naveg
    function toggleTheme(){document.body.dataset.theme=document.body.dataset.theme==='dark'?'':'dark';}
    function toggleReg(){const e=document.getElementById('t_e').checked;document.getElementById('fields-emp').style.display=e?'block':'none';document.getElementById('fields-auto').style.display=e?'none':'block';}
    function checkOther(){document.getElementById('reg_rs').style.display=(document.getElementById('reg_tipo_emp').value==='Otro')?'block':'none';}

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
        if (!validateEmail(email)) { showMsg("Error", "Email no válido. Use un correo real (ej: gmail.com, hotmail.com)", 'error'); return; }
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
            d.dni=d.cif;
            if (/^[XYZ0-9]/.test(d.cif) && d.cif.length === 9) {
                 if (!validateDNI(d.cif) && !/^[ABCDEFGHJKLMNPQRSUVW]/.test(d.cif)) {}
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
</script>
</body></html>