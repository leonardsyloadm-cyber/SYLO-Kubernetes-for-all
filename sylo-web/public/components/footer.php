<footer class="py-5 border-top mt-5" style="background-color: var(--sylo-bg); border-color: rgba(255,255,255,0.05) !important;"><div class="container text-center"><h5 class="fw-bold text-primary mb-3">SYLO CORP S.L.</h5><div class="mb-4"><a href="mailto:arlanzonivan@gmail.com" class="text-muted mx-2 text-decoration-none">arlanzonivan@gmail.com</a><a href="mailto:leob@gmail.com" class="text-muted mx-2 text-decoration-none">leob@gmail.com</a></div><a href="terminos.php" class="btn btn-link text-muted small" data-i18n="footer.terms">TÉRMINOS Y CONDICIONES</a></div></footer>

<!-- MESSAGE MODAL (Replaces Alerts) -->
    <div class="modal fade" id="messageModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header border-0"><h5 class="fw-bold text-primary" id="msgTitle">Mensaje</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center p-4"><div class="mb-3"><i id="msgIcon" class="fas fa-info-circle fa-3x text-primary"></i></div><p id="msgText" class="lead mb-0"></p></div><div class="modal-footer border-0 justify-content-center"><button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">Entendido</button></div></div></div></div>
    <div class="modal fade" id="authModal"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content border-0 shadow-lg p-4">
        <ul class="nav nav-pills nav-fill mb-4 p-1 rounded-pill" style="background-color: var(--sylo-bg); border: 1px solid rgba(255,255,255,0.05);">
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
    function toggleReg(){const e=document.getElementById('t_e').checked;document.getElementById('fields-emp').style.display=e?'block':'none';document.getElementById('fields-auto').style.display=e?'none':'block';}
    function checkOther(){document.getElementById('reg_rs').style.display=(document.getElementById('reg_tipo_emp').value==='Otro')?'block':'none';}

    async function handleLogin() { 
        const r = await fetch('index.php', { method:'POST', headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ action:'login', email_user: document.getElementById('log_email').value, password: document.getElementById('log_pass').value }) }); 
        const d = await r.json(); 
        if (d.status === 'success') {
            if (d.redirect) location.assign(d.redirect);
            else location.assign('panel.php'); 
        } else {
            showMsg("Login Fallido", d.mensaje, 'error'); 
        }
    }

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
            if(res.status==='success') { showMsg("Éxito", "Cuenta creada. Redirigiendo...", 'success'); setTimeout(()=>location.assign('panel.php'), 2000); }
            else showMsg("Error", res.mensaje || "Error al registrar", 'error');
        } catch(e) { showMsg("Error", "Error de conexión: "+e, 'error'); }
    }
    function logout() { fetch('index.php',{method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token': csrfToken}, body:JSON.stringify({action:'logout'})}).then(()=>location.reload()); }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({ duration: 800, once: true, offset: 50 });
</script>
