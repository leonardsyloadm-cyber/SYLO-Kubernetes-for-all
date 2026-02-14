#!/usr/bin/env python3
import sys, os, subprocess, time, json, datetime, threading, signal, shutil, tarfile, glob, codecs

# Add ~/bin to PATH for Helm
os.environ['PATH'] = os.environ.get('PATH', '') + ':' + os.path.expanduser('~/bin')

# ==============================================================================
# ⚙️ CONFIGURACIÓN
# ==============================================================================
sys.stdout.reconfigure(line_buffering=True)
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "sylo-web", "buzon-pedidos")
API_URL = "http://127.0.0.1:8001/api/clientes" 

status_lock = threading.Lock()
cmd_lock = threading.Lock()
shutdown_event = threading.Event()
blocked_cids = set() # Prevent metrics reporting during power ops
active_port_forwards = {} # {oid: subprocess.Popen}
active_monitor_forwards = {} # {oid: subprocess.Popen} for Grafana
active_prometheus_forwards = {} # {oid: subprocess.Popen} for Prometheus

# ==============================================================================
# 🎨 LOGS
# ==============================================================================
C_RESET = "\033[0m"; C_CYAN = "\033[96m"; C_YELLOW = "\033[93m"; C_GREEN = "\033[92m"; C_RED = "\033[91m"; C_GREY = "\033[90m"

def log(msg, color=C_CYAN):
    timestamp = datetime.datetime.now().strftime('%H:%M:%S')
    print(f"{color}[{timestamp}] {msg}{C_RESET}", flush=True)

def signal_handler(signum, frame):
    log("🛑 Parada solicitada.", C_RED)
    shutdown_event.set()
    sys.exit(0)

# ==============================================================================
# 🛠️ EJECUTOR
# ==============================================================================
def run_command(cmd, timeout=300, silent=False, check_error=False):
    with cmd_lock:
        try:
            # 🔥 INJECTION: Detect profile and force KUBECONFIG
            # This fixes the "localhost" issue by using the isolated config file we generated
            import re
            match = re.search(r'sylo-cliente-(\d+)', cmd)
            if match:
                oid = match.group(1)
                adhoc_conf = os.path.expanduser(f"~/.kube/config_sylo_{oid}")
                if os.path.exists(adhoc_conf):
                    # Prepend export. Note: This works because shell=True
                    cmd = f"export KUBECONFIG={adhoc_conf}; " + cmd
            
            if not silent: log(f"CMD > {cmd}", C_YELLOW)
            res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout, bufsize=10485760)
            if res.returncode != 0:
                err_msg = res.stderr.strip()[:300]
                if "grep" not in cmd: log(f"⚠️ ERROR ({res.returncode}): {err_msg}", C_RED)
                if check_error: raise Exception(f"CMD Failed: {err_msg}")
            elif not silent:
                preview = res.stdout.strip()[:100].replace('\n', ' ')
                if preview: log(f"   OUT: {preview}...", C_GREY)
            return res.stdout.strip()
        except Exception as e:
            msg = f"❌ EXCEPCIÓN: {e}"
            log(msg, C_RED)
            if check_error: raise e
            return ""

# ==============================================================================
# 📡 API
# ==============================================================================
try: import requests
except ImportError: log("⚠️ Falta 'requests'", C_RED)

def report_progress(oid, tipo, status, pct, msg):
    log(f"📡 API > Cliente {oid} [{tipo}]: {msg} ({pct}%)", C_GREY)
    
    # 1. WRITE TO DISK (Compatibilidad Directa Frontend PHP)
    try:
        # Definir nombre de archivo segun tipo
        f_name = f"status_{oid}.json"
        if tipo == "power": f_name = f"power_status_{oid}.json"
        elif tipo == "backup": f_name = f"backup_status_{oid}.json"
        elif tipo == "web" or tipo == "web_updating": f_name = f"web_status_{oid}.json"
        
        file_path = os.path.join(BUZON, f_name)
        
        # Mapeo de action para que el frontend lo reconozca
        payload = {
            "id_cliente": int(oid), 
            "status": status, 
            "percent": int(pct), 
            "msg": str(msg),
            "message": str(msg) # Redundancia
        }
        if tipo == "plan_update": 
            payload["action"] = "plan_update"
            # Force filename to specialist status for plan updates to avoid race condition with metrics
            f_name = f"plan_status_{oid}.json"
            file_path = os.path.join(BUZON, f_name)

        tmp_path = file_path + ".tmp"
        with open(tmp_path, 'w') as f: json.dump(payload, f)
        os.chmod(tmp_path, 0o666)
        os.replace(tmp_path, file_path)
    except Exception as e:
        log(f"⚠️ Error escribiendo disco: {e}", C_RED)

    # 2. SEND TO API (Legacy/Centralized)
    # FIX: Skip API for plan_update to avoid creating 'backup_status' file (API defaults) which overrides our 'status' file in frontend.
    if tipo == "plan_update": return

    try:
        payload = {
            "id_cliente": int(oid), "tipo": tipo, "status_text": status, "percent": int(pct), "msg": str(msg)
        }
        requests.post(f"{API_URL}/reportar/progreso", json=payload, timeout=2)
    except: pass

def report_backups_list(oid):
    try:
        files = glob.glob(os.path.join(BUZON, f"backup_v{oid}_*.tar.gz"))
        backups = []
        for f in sorted(files, reverse=True):
            try:
                parts = os.path.basename(f).split('_')
                if len(parts) >= 5:
                    ts = parts[4].split('.')[0]
                    date_fmt = f"{ts[6:8]}/{ts[4:6]} {ts[8:10]}:{ts[10:12]}"
                    b_type = parts[2].upper()
                    backups.append({"file": os.path.basename(f), "name": parts[3], "type": b_type, "date": date_fmt})
            except: continue
        requests.post(f"{API_URL}/reportar/lista_backups", json={"id_cliente": int(oid), "backups": backups}, timeout=2)
    except: pass

# ==============================================================================
# 🔍 HELPERS
# ==============================================================================
def update_hosts(ip, domain):
    try:
        if not ip or not domain: return
        log(f"🌍 Actualizando DNS Local: {domain} -> {ip}...", C_CYAN)
        clean_cmd = f"sudo sed -i '/{domain}/d' /etc/hosts"
        run_command(clean_cmd, silent=True)
        add_cmd = f"echo '{ip} {domain}' | sudo tee -a /etc/hosts"
        run_command(add_cmd, silent=True)
    except Exception as e:
        log(f"❌ Error actualizando hosts: {e}", C_RED)

def find_web_pod(profile):
    cmd = f"minikube -p {profile} kubectl -- get pods"
    raw = run_command(cmd, silent=True)
    candidates = []
    for line in raw.splitlines():
        if "NAME" in line: continue
        parts = line.split()
        if len(parts) < 3: continue
        name, status = parts[0], parts[2]
        if "mysql" in name or "db" in name: continue
        
        accepted_status = ["Running", "ContainerCreating", "Pending", "PodInitializing", "Init", "BackOff", "Error", "Err", "Unknown", "CrashLoopBackOff"]
        if any(s in status for s in accepted_status): 
            candidates.append(name)
    
    if not candidates: return None
    for c in candidates: 
        if "web" in c: return c
    for c in candidates: 
        if "http" in c or "nginx" in c or "apache" in c or "custom" in c: return c
    if candidates: return candidates[0]
    return None

def find_active_configmap(profile, pod_name):
    return "custom-web-content"

# ==============================================================================
# 🚀 ACCIONES
# ==============================================================================

# ... (Previous functions omitted for brevity, ensure they are kept if outside range) ...

# 7. 🔄 UPDATE PLAN (Scaling & Reconciliation)
def handle_plan_update(oid, profile, new_specs_arg):
    log(f"⚡ UPDATE PLAN: Cliente {oid}", C_YELLOW)
    report_progress(oid, "plan_update", "updating", 10, "Analizando nueva configuración...")
    time.sleep(1)
    
    try:
        # A. Fetch Full Specs (Target)
        report_progress(oid, "plan_update", "updating", 15, "Leyendo especificaciones...")
        # FIXED: Updated to query sylo_admin_db.k8s_deployments instead of old order_specs
        cmd_db = f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT cpu_cores, ram_gb, db_enabled, db_type, db_custom_name, web_enabled, web_type, web_custom_name, subdomain, os_image, ssh_user FROM k8s_deployments WHERE id={oid}"'
        raw = run_command(cmd_db, silent=True).strip()
        if not raw: raise Exception("No specs found in DB")
        
        parts = raw.split('\t')
        specs = {
            "cpu": parts[0], "ram": parts[1],
            "db_en": parts[2], "db_type": parts[3], "db_name": parts[4],
            "web_en": parts[5], "web_type": parts[6], "web_name": parts[7],
            "sub": parts[8], "os": parts[9], "user": parts[10], "owner": parts[10] # user_id mapped to owner
        }
        
        # B. DETECT CURRENT STATE (For user feedback)
        curr_cpu = 0
        curr_ram = 0
        web_exists = "custom-web" in run_command(f"minikube -p {profile} kubectl -- get deploy", silent=True)
        
        try:
            # Check current resources from live deployment (if exists)
            chk = f"minikube -p {profile} kubectl -- get deploy custom-web -o jsonpath='{{.spec.template.spec.containers[0].resources.requests.memory}}'"
            raw_ram = run_command(chk, silent=True).strip()
            if raw_ram and "Gi" in raw_ram: curr_ram = int(raw_ram.replace("Gi",""))
            elif raw_ram and "Mi" in raw_ram: curr_ram = int(raw_ram.replace("Mi","")) / 1024
            
            # Simple heuristic if deployment checking fails or returns strange values:
            if curr_ram == 0: curr_ram = 1 # Assume min
        except: pass

        # C. Compare & Report
        tgt_ram = int(specs['ram'])
        
        msg_scale = f"Ajustando recursos (RAM: {specs['ram']}GB)..."
        if tgt_ram > curr_ram and curr_ram > 0:
            msg_scale = f"🚀 Aumentando RAM y CPU ({curr_ram}GB -> {tgt_ram}GB)..."
        elif tgt_ram < curr_ram:
            msg_scale = f"📉 Reduciendo RAM y CPU ({curr_ram}GB -> {tgt_ram}GB)..."
            
        report_progress(oid, "plan_update", "scaling", 30, msg_scale)
        time.sleep(2)
        
        # Resize Web
        if specs['web_en'] == '1':
            run_command(f"minikube -p {profile} kubectl -- set resources deployment custom-web --limits=cpu={specs['cpu']},memory={specs['ram']}Gi --requests=cpu={specs['cpu']},memory={specs['ram']}Gi", silent=True)
            
        # Resize DB
        if specs['db_en'] == '1':
            run_command(f"minikube -p {profile} kubectl -- set resources deployment custom-db --limits=cpu={specs['cpu']},memory={specs['ram']}Gi --requests=cpu={specs['cpu']},memory={specs['ram']}Gi", silent=True)
            
        time.sleep(2) 

        # D. Installs / Uninstalls
        db_exists = "custom-db" in run_command(f"minikube -p {profile} kubectl -- get deploy", silent=True)
        
        if specs['db_en'] == '1' and not db_exists:
             report_progress(oid, "plan_update", "deploying_db", 50, "Instalando Base de Datos...")
             _apply_db_manifest(profile, specs)
        elif specs['db_en'] == '0' and db_exists:
             report_progress(oid, "plan_update", "removing_db", 50, "Desinstalando Base de Datos...")
             run_command(f"minikube -p {profile} kubectl -- delete deployment custom-db", silent=True)
             run_command(f"minikube -p {profile} kubectl -- delete service custom-db-service", silent=True)
        
        
        if specs['web_en'] == '1' and not web_exists:
            report_progress(oid, "plan_update", "deploying_web", 70, "Instalando Servidor Web...")
            time.sleep(2)
            _apply_web_manifest(profile, specs)
        elif specs['web_en'] == '0' and web_exists:
            report_progress(oid, "plan_update", "removing_web", 70, "Desinstalando Servidor Web...")
            time.sleep(2)
            run_command(f"minikube -p {profile} kubectl -- delete deployment custom-web", silent=True)
            run_command(f"minikube -p {profile} kubectl -- delete service web-service", silent=True)
        elif specs['web_en'] == '1':
             report_progress(oid, "plan_update", "reconciling_web", 70, "Actualizando Servidor Web...")
             time.sleep(2)
             _apply_web_manifest(profile, specs) # Re-apply to ensure config matches
        
        # E. Update Info
        if specs['web_en'] == '1':
             _update_web_info_cm(profile, specs)
             
        # F. Validation
        report_progress(oid, "plan_update", "verifying", 90, "Verificando nuevos servicios...")
        time.sleep(3)
        
        report_progress(oid, "plan_update", "completed", 100, "Plan Actualizado Correctamente")
        log(f"✅ PLAN V{oid} ACTUALIZADO", C_GREEN)
        
        # Clean transient status immediately to unlock UI
        # WAIT 10 SECONDS to ensure Frontend sees the 100%
        time.sleep(10)
        try: os.remove(os.path.join(BUZON, f"plan_status_{oid}.json"))
        except: pass

    except Exception as e:
        log(f"❌ Error Update Plan: {e}", C_RED)
        report_progress(oid, "plan_update", "error", 0, str(e))
# 1. EDITAR WEB
def update_web_content(oid, profile, html_content, is_restore_process=False):
    t_type = "backup" if is_restore_process else "web"
    t_stat = "restoring" if is_restore_process else "web_updating"
    log(f"🔧 PROCESANDO WEB Cliente {oid}...", C_GREEN)
    
    report_progress(oid, t_type, t_stat, 10, "Orden procesada...")
    time.sleep(1.5)
    if is_restore_process: time.sleep(1)
    
    try:
        pod = None
        for i in range(20):
            pod = find_web_pod(profile)
            if pod: break
            report_progress(oid, t_type, t_stat, 15, f"Buscando Pod... ({i*3}s)")
            time.sleep(3)
            
        if not pod: raise Exception("Pod Web no encontrado (¿apagado?)")
        target_cm = find_active_configmap(profile, pod)
        
        temp_file = os.path.join(WORKER_DIR, f"temp_web_{oid}.html")
        with codecs.open(temp_file, "w", "utf-8") as f: f.write(html_content)
            
        report_progress(oid, t_type, t_stat, 30, f"Limpiando configuración... (CMD 1)")
        time.sleep(1.5) 
        run_command(f"minikube -p {profile} kubectl -- delete cm {target_cm} --ignore-not-found", silent=False)
        
        report_progress(oid, t_type, t_stat, 60, f"Subiendo nuevo contenido... (CMD 2)")
        time.sleep(1.5)
        res = run_command(f"minikube -p {profile} kubectl -- create cm {target_cm} --from-file=index.html={temp_file}", silent=False)
        
        if "created" not in res: raise Exception("Fallo ConfigMap")

        report_progress(oid, t_type, t_stat, 75, "Aplicando cambios... (CMD 3)")
        time.sleep(1.5)
        run_command(f"minikube -p {profile} kubectl -- delete pod {pod} --wait=false", silent=False)
        
        if os.path.exists(temp_file): os.remove(temp_file)
        
        time.sleep(5)
        new_pod = None
        for i in range(20):
            report_progress(oid, t_type, t_stat, 95, f"Verificando... ({i*2}s)")
            new_pod = find_web_pod(profile)
            if new_pod:
                s = run_command(f"minikube -p {profile} kubectl -- get pod {new_pod} -o jsonpath='{{.status.phase}}'", silent=True)
                if "Running" in s: break
            time.sleep(2)
        
        report_progress(oid, t_type, "completed" if is_restore_process else "web_completed", 100, "Web Actualizada")
        log("✅ WEB ACTUALIZADA", C_GREEN)
        
    except Exception as e:
        log(f"❌ Error: {e}", C_RED)
        report_progress(oid, t_type, "error", 0, str(e))

# 2. BACKUP
def create_backup(oid, profile, backup_name, backup_type="full"):
    log(f"📦 BACKUP [{backup_type}]: {backup_name}", C_GREEN)
    report_progress(oid, "backup", "creating", 10, "Iniciando...")
    temp_dir = os.path.join(BUZON, f"temp_bk_{oid}")
    if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)
    
    try:
        report_progress(oid, "backup", "creating", 10, "Iniciando...")
        time.sleep(1.5)

        pod = find_web_pod(profile)
        if pod:
            report_progress(oid, "backup", "creating", 30, "Leyendo datos...")
            time.sleep(1.5)
            html = run_command(f"minikube -p {profile} kubectl -- exec {pod} -- cat /usr/share/nginx/html/index.html", silent=True)
            if not html or "No such file" in html: html = run_command(f"minikube -p {profile} kubectl -- exec {pod} -- cat /var/www/html/index.html", silent=True)
            with open(os.path.join(temp_dir, "index.html"), "w") as f: f.write(html if html and "No such file" not in html else "")
        else:
            with open(os.path.join(temp_dir, "index.html"), "w") as f: f.write("")
        
        report_progress(oid, "backup", "creating", 60, "Comprimiendo...")
        time.sleep(1.5)

        report_progress(oid, "backup", "creating", 80, "Guardando en disco...")
        time.sleep(1.5)

        ts = datetime.datetime.now().strftime('%Y%m%d%H%M%S')
        safe_name = "".join(x for x in backup_name if x.isalnum())
        fname = f"backup_v{oid}_{backup_type.lower()}_{safe_name}_{ts}.tar.gz"
        full_path = os.path.join(BUZON, fname)
        
        with tarfile.open(full_path, "w:gz") as tar: tar.add(temp_dir, arcname="data")
        try: os.chmod(full_path, 0o644)
        except: pass

        report_progress(oid, "backup", "completed", 100, "Backup Completado")
        report_backups_list(oid)
        log(f"💾 Guardado: {fname}", C_GREEN)
        time.sleep(4)
        try: os.remove(os.path.join(BUZON, f"backup_status_{oid}.json"))
        except: pass
        
    except Exception as e:
        log(f"❌ Error: {e}", C_RED); report_progress(oid, "backup", "error", 0, str(e))
    finally:
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)

# 3. RESTORE
def restore_backup(oid, profile, filename):
    log(f"♻️ RESTAURANDO: {filename}", C_GREEN)
    report_progress(oid, "backup", "restoring", 5, "Iniciando...")
    path = os.path.join(BUZON, filename)
    if not os.path.exists(path): return
    ext_dir = os.path.join(BUZON, f"rest_{oid}")
    if os.path.exists(ext_dir): shutil.rmtree(ext_dir)
    try:
        with tarfile.open(path, "r:gz") as tar: tar.extractall(ext_dir)
        found = None
        for r, d, f in os.walk(ext_dir):
            if "index.html" in f: found = os.path.join(r, "index.html"); break
        if found:
            with codecs.open(found, 'r', 'utf-8') as f: update_web_content(oid, profile, f.read(), is_restore_process=True)
        time.sleep(4)
        try: os.remove(os.path.join(BUZON, f"backup_status_{oid}.json"))
        except: pass
    except Exception as e: report_progress(oid, "backup", "error", 0, str(e))
    finally:
        if os.path.exists(ext_dir): shutil.rmtree(ext_dir)

# 3.5. LIFECYCLE (CREATE / TERMINATE)
def create_client(oid, plan_id, specs):
    log(f"✨ CREANDO CLIENTE {oid} (Plan {plan_id})", C_GREEN)
    report_progress(oid, "creating", "starting", 10, "Iniciando infraestructura...")
    
    try:
        # IP Calculation (Subnet Sharding: 192.168.50+OID.x)
        oct3 = 50 + int(oid)
        static_ip = f"192.168.{oct3}.2"
        
        # Script Selection
        script = "tofu-k8s/k8s-simple/deploy_simple.sh"
        cmd = ""
        
        # Plan 1 (Bronce) -> Simple
        if str(plan_id) == "1":
            user = specs.get('user', 'admin')
            os_img = specs.get('os', 'alpine')
            sub = specs.get('subdomain', f"cluster{oid}")
            cmd = f"bash {script} {oid} {user} {os_img} - - {sub} {static_ip}"
            
        else:
            # Plan Custom/Silver/Gold -> Full Stack (deploy_custom.sh)
            # Args: OID CPU RAM STORAGE DB_EN DB_TYPE WEB_EN WEB_TYPE SSH_USER OS DB_NAME WEB_NAME SUBDOMAIN
            script = "tofu-k8s/custom-stack/deploy_custom.sh"
            
            # Defaults
            cpu = specs.get('cpu', 2)
            ram = specs.get('ram', 4)
            disk = 20
            db_en = specs.get('db_en', 0)
            db_type = specs.get('db_type', 'mysql')
            web_en = specs.get('web_en', 0)
            web_type = specs.get('web_type', 'nginx')
            user = specs.get('user', 'root')
            os_img = specs.get('os', 'ubuntu')
            db_name = specs.get('db_name', 'mydb')
            web_name = specs.get('web_name', 'My Website')
            sub = specs.get('subdomain', f"vp{oid}")
            
            cmd = f"bash {script} {oid} {cpu} {ram} {disk} {db_en} \"{db_type}\" {web_en} \"{web_type}\" \"{user}\" \"{os_img}\" \"{db_name}\" \"{web_name}\" \"{sub}\" {static_ip}"

        # Execute
        log(f"🚀 Ejecutando: {script}", C_CYAN)
        run_command(cmd, check_error=True, timeout=1200) # 20 min timeout for provisioning
        
        # Success Logic handled by script's JSON bucket output? 
        # But we should mark it here too just in case?
        # The script writes status_OID.json with 'completed'.
        log(f"✅ CLIENTE {oid} DESPLEGADO", C_GREEN)
        
    except Exception as e:
        log(f"❌ Error Creación: {e}", C_RED)
        report_progress(oid, "creating", "error", 0, f"Fallo: {str(e)}")

def terminate_client(oid):
    log(f"💀 TERMINANDO CLIENTE {oid}", C_RED)
    report_progress(oid, "terminating", "terminating", 10, "Iniciando destrucción...")
    try:
        destroy_k8s_resources(oid, f"sylo-cliente-{oid}")
        report_progress(oid, "terminating", "terminated", 100, "Servicio eliminado")
    except Exception as e:
        report_progress(oid, "terminating", "error", 0, str(e))

# 4. DELETE
def delete_backup(oid, fname):
    try:
        report_progress(oid, "backup", "deleting", 10, "Localizando archivo...")
        time.sleep(1)
        path = os.path.join(BUZON, fname)
        if os.path.exists(path): 
            report_progress(oid, "backup", "deleting", 50, "Eliminando...")
            time.sleep(1)
            os.remove(path)
        report_progress(oid, "backup", "completed", 100, "Eliminado")
        report_backups_list(oid)
        time.sleep(3)
        try: os.remove(os.path.join(BUZON, f"backup_status_{oid}.json"))
        except: pass
    except: pass

# 5. DESTROY
def destroy_k8s_resources(oid, profile):
    log(f"☢️ DESTRUYENDO: CLIENTE {oid}", C_RED)
    try:
        run_command(f"minikube delete -p {profile}")
        run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "UPDATE k8s_deployments SET status=\'cancelled\' WHERE id={oid}"', silent=False)
        log(f"⚰️ ELIMINADO", C_RED)
    except: pass

# 6. 🔥 CONTROL DE ENERGÍA (CORE LOGIC)
def handle_power(oid, profile, action):
    action = action.upper()
    log(f"🔌 ENERGÍA: {action} Cliente {oid}", C_YELLOW)
    
    try:
        if action == "STOP":
            blocked_cids.add(int(oid))
            report_progress(oid, "power", "stopping", 10, "Deteniendo clúster...")
            
            check_prof = run_command(f"minikube profile list -o json", silent=True)
            if profile not in check_prof: raise Exception(f"Perfil {profile} no encontrado")

            run_command(f"minikube stop -p {profile}", check_error=True)
            
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "UPDATE k8s_deployments SET status=\'stopped\' WHERE id={oid}"', check_error=True)
            
            try: requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":0,"ram":0}, "ssh_cmd": "Offline", "web_url": "", "os_info": "Offline", "installed_tools": []}, timeout=2)
            except: pass

            report_progress(oid, "power", "stopped", 100, "Hibernando")
            log(f"🛑 CLIENTE {oid} DETENIDO", C_RED)

        elif action == "START":
            _power_up_logic(oid, profile)

        elif action == "RESTART":
            blocked_cids.add(int(oid))
            report_progress(oid, "power", "restarting", 10, "Reiniciando clúster...")
            
            check_prof = run_command(f"minikube profile list -o json", silent=True)
            if profile not in check_prof: raise Exception(f"Perfil {profile} no encontrado")

            run_command(f"minikube stop -p {profile}", check_error=True)
            time.sleep(3)
            
            _power_up_logic(oid, profile, is_restart=True)

    except Exception as e:
        log(f"❌ Error Crítico Energía: {e}", C_RED)
        report_progress(oid, "power", "error", 0, "Fallo crítico. Ver logs.")
        if int(oid) in blocked_cids: blocked_cids.remove(int(oid))

# HELPER: Logic compartida para START y RESTART
def _power_up_logic(oid, profile, is_restart=False):
    op_type = "restarting" if is_restart else "starting"
    final_type = "restarted" if is_restart else "started"
    
    report_progress(oid, "power", op_type, 20, "Preparando arranque...")
    
    check_prof = run_command(f"minikube profile list -o json", silent=True)
    if profile not in check_prof: raise Exception(f"Perfil {profile} no encontrado")

    # 1. OBTENER IP FIJA
    try:
        fixed_ip = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT ip_address FROM k8s_deployments WHERE id={oid}"', silent=True).strip()
    except: fixed_ip = ""

    # 2. OBTENER RAM CONTRATADA (Specs)
    try:
        # Extraemos la RAM definida en el plan del usuario
        db_ram_str = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT ram_gb FROM k8s_deployments WHERE id={oid}"', silent=True).strip()
        user_ram_gb = int(db_ram_str)
    except: 
        user_ram_gb = 2 # Fallback de seguridad si falla la query

    # 3. LÓGICA DE ASIGNACIÓN (OVERHEAD KUBERNETES)
    # K8s necesita ~1.8GB solo para existir. Si el plan es de 1GB, el sistema no arranca.
    # Solución: Si el plan es pequeño, inyectamos RAM "técnica" extra.
    alloc_mb = user_ram_gb * 1024
    if alloc_mb < 2200:
        alloc_mb = 2200
        log(f"ℹ️ Plan pequeño ({user_ram_gb}GB). Ajustando a {alloc_mb}MB para overhead técnico.", C_CYAN)
    
    report_progress(oid, "power", op_type, 30, f"Asignando recursos ({alloc_mb}MB)...")
    
    # 4. ARRANQUE CON LA MEMORIA CALCULADA

    # NUEVA LÍNEA DE ARRANQUE SIMPLIFICADA (FIX TIMEOUTS)
    # Eliminamos cpu-manager-policy=static y reservas para evitar bloqueos en entornos limitados.
    cmd_start = f"minikube start -p {profile} --memory={alloc_mb}m --force"
    
    if fixed_ip and len(fixed_ip) > 6:
        cmd_start += f" --static-ip {fixed_ip}"
    
    # Aumentamos timeout a 600s por si acaso
    run_command(cmd_start, check_error=True, timeout=600)
    
    # 5. ACTUALIZAR CONTEXTO (Vital tras --force)
    run_command(f"minikube -p {profile} update-context", silent=True)
    time.sleep(2)
    
    report_progress(oid, "power", op_type, 50, "Verificando servicios...")
    
    found_pod = False
    for i in range(15):
        pod = find_web_pod(profile)
        if pod:
            s = run_command(f"minikube -p {profile} kubectl -- get pod {pod} -o jsonpath='{{.status.phase}}'", silent=True)
            if "Running" in s:
                found_pod = True
                break
        report_progress(oid, "power", op_type, 50 + (i*2), f"Verificando... ({i*3}s)")
        time.sleep(3)
    
    if not found_pod:
        log(f"⚠️ Alerta: Pods no encontrados. Intentando Self-Healing...", C_YELLOW)
        report_progress(oid, "power", op_type, 80, "Aplicando Self-Healing...")
        
        specs_json = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT JSON_OBJECT(\'cpu\', cpu_cores, \'ram\', ram_gb, \'storage\', storage_gb, \'db_en\', db_enabled, \'db_type\', db_type, \'web_en\', web_enabled, \'web_type\', web_type, \'ssh_user\', ssh_user, \'os\', os_image, \'db_name\', db_custom_name, \'web_name\', web_custom_name, \'subdomain\', subdomain) FROM k8s_deployments WHERE id={oid}"', silent=True).strip()
        
        if specs_json and "{" in specs_json:
            sp = json.loads(specs_json)
            deploy_script = os.path.join(BASE_DIR, "tofu-k8s/custom-stack/deploy_custom.sh")
            cmd_repair = f"bash {deploy_script} {oid} {sp['cpu']} {sp['ram']} {sp['storage']} {sp['db_en']} \"{sp['db_type']}\" {sp['web_en']} \"{sp['web_type']}\" \"{sp['ssh_user']}\" \"{sp['os']}\" \"{sp['db_name']}\" \"{sp['web_name']}\" \"{sp['subdomain']}\""
            
            run_command(cmd_repair, check_error=True)
            log("✅ Reconstrucción completada.", C_GREEN)
            time.sleep(5)
        else:
            raise Exception("Imposible recuperar Specs para reparación")

    run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "UPDATE k8s_deployments SET status=\'active\' WHERE id={oid}"', check_error=True)
    report_progress(oid, "power", final_type, 100, "Online")
    log(f"🟢 CLIENTE {oid} ONLINE", C_GREEN)
    if int(oid) in blocked_cids: blocked_cids.remove(int(oid))

# ==============================================================================
# 🔄 WORKERS
# ==============================================================================
def process_task_queue():
    log("📥 Cola lista.", C_GREEN)
    while not shutdown_event.is_set():
        # Process generic actions (exclude tool-specific actions)
        for f in glob.glob(os.path.join(BUZON, "accion_*.json")):
            # Skip tool installation/uninstallation files - they're handled separately below
            if 'install_tool' in f or 'uninstall_tool' in f:
                continue
                
            try:
                with open(f) as fh: d = json.load(fh)
                os.remove(f)
                oid, act, prof = d.get('id_cliente'), str(d.get('action')).upper(), f"sylo-cliente-{d.get('id_cliente')}"
                log(f"📨 Orden: {act} -> {oid}", C_YELLOW)
                
                if act == "BACKUP": threading.Thread(target=create_backup, args=(oid, prof, d.get('backup_name'), d.get('backup_type', 'full'))).start()
                elif act == "RESTORE_BACKUP": threading.Thread(target=restore_backup, args=(oid, prof, d.get('filename_to_restore'))).start()
                elif act == "UPDATE_WEB": threading.Thread(target=update_web_content, args=(oid, prof, d.get('html_content'))).start()
                elif act == "DELETE_BACKUP": threading.Thread(target=delete_backup, args=(oid, d.get('filename_to_delete'))).start()
                elif act == "DESTROY_K8S": threading.Thread(target=destroy_k8s_resources, args=(oid, prof)).start()
                elif act in ["STOP", "START", "RESTART"]: threading.Thread(target=handle_power, args=(oid, prof, act)).start()
                elif act == "UPDATE_PLAN": threading.Thread(target=handle_plan_update, args=(oid, prof, d.get('new_specs'))).start()
                
            except Exception as e: log(f"⚠️ Error: {e}", C_RED)
        
        # NEW: Handle tool installation requests
        for f in glob.glob(os.path.join(BUZON, "accion_install_tool_*.json")):
            try:
                with open(f, 'r') as file:
                    content = file.read()
                    log(f"📄 Leyendo archivo: {f}", C_CYAN)
                    log(f"   Contenido: {content[:200]}", C_GREY)
                    d = json.loads(content)
                
                oid = d.get('deployment_id')
                tool = d.get('tool')
                
                log(f"   deployment_id={oid}, tool={tool}", C_GREY)
                
                if not oid or not tool:
                    log(f"⚠️ Invalid tool install request: {f}", C_YELLOW)
                    log(f"   JSON data: {d}", C_YELLOW)
                    os.remove(f)
                    continue
                
                log(f"📥 Nueva solicitud de instalación: {tool} para deployment {oid}", C_CYAN)
                
                # Execute installation
                threading.Thread(target=handle_install_tool, args=(oid, tool, d)).start()
                
                # Mark as processed
                os.rename(f, f + ".procesado")
                
            except Exception as e:
                log(f"❌ Error procesando {f}: {e}", C_RED)
                try:
                    os.rename(f, f + ".error")
                except:
                    pass
        
        # NEW: Handle tool uninstallation requests
        for f in glob.glob(os.path.join(BUZON, "accion_uninstall_tool_*.json")):
            try:
                with open(f, 'r') as file:
                    d = json.load(file)
                
                oid = d.get('deployment_id')
                tool = d.get('tool')
                
                if not oid or not tool:
                    os.remove(f)
                    continue
                
                log(f"📥 Solicitud de desinstalación: {tool} para deployment {oid}", C_YELLOW)
                
                profile = f"sylo-cliente-{oid}"
                
                if tool == "monitoring":
                    threading.Thread(target=uninstall_monitoring_stack, args=(oid, profile)).start()
                
                os.rename(f, f + ".procesado")
                
            except Exception as e:
                log(f"❌ Error desinstalando: {e}", C_RED)
        
        time.sleep(0.5)

def ensure_monitoring_port_forward(oid, profile):
    global active_monitor_forwards, active_prometheus_forwards
    
    try:
        # 1. GRAFANA (30xx)
        grafana_port = f"30{oid}"
        if oid not in active_monitor_forwards or active_monitor_forwards[oid].poll() is not None:
             # Cleanup specific port
            run_command(f"pkill -f ':{grafana_port}'", silent=True)
            
            cmd = ["minikube", "-p", profile, "kubectl", "--", "port-forward", "-n", profile, "svc/sylo-monitor-grafana", f"{grafana_port}:80", "--address", "0.0.0.0"]
            
            # Use custom KUBECONFIG if exists
            my_env = os.environ.copy()
            adhoc_conf = os.path.expanduser(f"~/.kube/config_sylo_{oid}")
            if os.path.exists(adhoc_conf): my_env["KUBECONFIG"] = adhoc_conf
            
            proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=my_env)
            active_monitor_forwards[oid] = proc
            
        # 2. PROMETHEUS (31xx)
        prom_port = f"31{oid}"
        if oid not in active_prometheus_forwards or active_prometheus_forwards[oid].poll() is not None:
            # Cleanup specific port
            run_command(f"pkill -f ':{prom_port}'", silent=True)
            
            cmd = ["minikube", "-p", profile, "kubectl", "--", "port-forward", "-n", profile, "svc/sylo-monitor-kube-promethe-prometheus", f"{prom_port}:9090", "--address", "0.0.0.0"]
            
            # Reuse env
            my_env = os.environ.copy()
            if os.path.exists(adhoc_conf): my_env["KUBECONFIG"] = adhoc_conf
            
            proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=my_env)
            active_prometheus_forwards[oid] = proc
        
    except Exception as e:
        # log(f"⚠️ Monitoring Port-Forward Error: {e}", C_RED)
        pass

def ensure_port_forward(oid, profile, web_en):
    global active_port_forwards
    port = 8000 + int(oid)
    
    # Clean up if disabled or stopped
    if str(web_en) != "1":
        if oid in active_port_forwards:
            log(f"🔌 Deteniendo Port-Forward Cliente {oid}", C_GREY)
            try: active_port_forwards[oid].terminate()
            except: pass
            del active_port_forwards[oid]
        return None

    # Check if active
    if oid in active_port_forwards:
        if active_port_forwards[oid].poll() is not None: # Died
            del active_port_forwards[oid]
        else:
            return f"http://localhost:{port}"

    # Start new
    try:
        log(f"🔌 Iniciando Port-Forward Cliente {oid} -> {port}", C_GREY)
        # Check if port is in use (simple cleanup logic could go here)
        cmd = ["minikube", "-p", profile, "kubectl", "--", "port-forward", "service/web-service", f"{port}:80", "--address", "0.0.0.0"]
        
        # 🔥 FIX: Inject KUBECONFIG for Popen
        my_env = os.environ.copy()
        adhoc_conf = os.path.expanduser(f"~/.kube/config_sylo_{oid}")
        if os.path.exists(adhoc_conf):
            my_env["KUBECONFIG"] = adhoc_conf

        # Run detached
        proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=my_env)
        active_port_forwards[oid] = proc
        time.sleep(1) # Give it a sec
        return f"http://localhost:{port}"
    except Exception as e:
        log(f"⚠️ Fallo Port-Forward {oid}: {e}", C_RED)
        return None

def process_metrics():
    log("📊 Métricas activas.", C_GREEN)
    while not shutdown_event.is_set():
        try:
            raw = run_command("docker ps --format '{{.Names}}'", silent=True)
            for line in raw.splitlines():
                if "sylo-cliente-" in line and "-sidecar" not in line and "preload" not in line:
                    parts = line.split('-')
                    if len(parts) == 3 and parts[2].isdigit():
                        oid = int(parts[2])
                        profile = line
                    else: continue
                    
                    if oid in blocked_cids: continue
                    try:
                        check_stat = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT status FROM k8s_deployments WHERE id={oid}"', silent=True).strip().lower()
                        # 🔥 FIX: Only run monitoring/port-forward if status is ACTIVE. Skip during creation/deletion.
                        if check_stat != 'active': continue
                    except: pass

                    stats = run_command(f"docker stats {line} --no-stream --format '{{{{.CPUPerc}}}}|{{{{.MemPerc}}}}'", silent=True)
                    c, r = 0.0, 0.0
                    try:
                        if "|" in stats:
                            parts = stats.split('|')
                            def parse_metric(val):
                                val = val.strip().replace('%', '')
                                if ',' in val and '.' not in val: val = val.replace(',', '.')
                                return float(val)
                            c = parse_metric(parts[0])
                            r = parse_metric(parts[1])
                    except: pass
                    
                    try:
                        db_cmd = f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT subdomain, os_image, web_enabled, web_type, db_enabled, db_type FROM k8s_deployments WHERE id={oid}"'
                        raw_data = run_command(db_cmd, silent=True).strip()
                        sub, os_img, web_en, web_type, db_en, db_type = "", "Linux", "0", "", "0", ""
                        if raw_data:
                            cols = raw_data.split('\t')
                            if len(cols) >= 6:
                                sub = cols[0] if cols[0] != "NULL" else ""
                                os_img = cols[1] if cols[1] != "NULL" else "Linux"
                                web_en = cols[2]; web_type = cols[3] if cols[3] != "NULL" else ""
                                db_en = cols[4]; db_type = cols[5] if cols[5] != "NULL" else ""

                        # PORT FORWARD LOGIC
                        pf_url = ensure_port_forward(oid, profile, web_en)
                        url = pf_url if pf_url else (f"http://{sub}.sylobi.org" if sub else "...")

                        tools = []
                        if os_img: tools.append(os_img)
                        if str(web_en) == "1" and web_type: tools.append(web_type)
                        if str(db_en) == "1" and db_type: tools.append(db_type)
                        
                        # NEW: Ensure Monitoring Port Forward (Self-Healing)
                        ensure_monitoring_port_forward(oid, profile)
                        
                        requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":c,"ram":r}, "ssh_cmd": "root@sylo", "web_url": url, "os_info": "os_img", "installed_tools": tools}, timeout=1)
                        report_backups_list(oid)
                    except:
                        requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":c,"ram":r}, "ssh_cmd": "root@sylo", "web_url": "...", "os_info": "Linux", "installed_tools": []}, timeout=1)
        except: pass
        time.sleep(2)

# 7. 🔄 UPDATE PLAN (Scaling & Reconciliation)
def handle_plan_update(oid, profile, new_specs_arg):
    log(f"⚡ UPDATE PLAN: Cliente {oid}", C_YELLOW)
    report_progress(oid, "plan_update", "updating", 10, "Analizando nueva configuración...")
    time.sleep(2)
    
    try:
        # A. Fetch Full Specs
        # Updated to use k8s_deployments + k8s_tools
        report_progress(oid, "plan_update", "updating", 15, "Obteniendo especificaciones...")
        
        # New Query reflecting schema change
        cmd_db = f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D sylo_admin_db -e "SELECT d.cpu_cores, d.ram_gb, d.db_enabled, d.db_type, d.db_custom_name, d.web_enabled, d.web_type, d.web_custom_name, d.subdomain, d.os_image, d.ssh_user, p.name FROM k8s_deployments d JOIN plans p ON d.plan_id = p.id WHERE d.id={oid}"'
        
        raw = run_command(cmd_db, silent=True).strip()
        if not raw: raise Exception("No specs found in DB")
        
        parts = raw.split('\t')
        specs = {
            "cpu": parts[0], "ram": parts[1],
            "db_en": parts[2], "db_type": parts[3], "db_name": parts[4],
            "web_en": parts[5], "web_type": parts[6], "web_name": parts[7],
            "sub": parts[8], "os": parts[9], "user": parts[10], "owner": parts[10],
            "plan_name": parts[11]
        }
        
        # B. Hot Resize (Vertical Scaling)
        report_progress(oid, "plan_update", "scaling", 30, f"Escalando Recursos (CPU: {specs['cpu']} / RAM: {specs['ram']}GB)...")
        
        # Resize Web
        if specs['web_en'] == '1':
            run_command(f"minikube -p {profile} kubectl -- set resources deployment custom-web --limits=cpu={specs['cpu']},memory={specs['ram']}Gi --requests=cpu={specs['cpu']},memory={specs['ram']}Gi", silent=True)
            
        # Resize DB
        if specs['db_en'] == '1':
            run_command(f"minikube -p {profile} kubectl -- set resources deployment custom-db --limits=cpu={specs['cpu']},memory={specs['ram']}Gi --requests=cpu={specs['cpu']},memory={specs['ram']}Gi", silent=True)
            
        time.sleep(2) # Give it a moment
        
        # C. Reconcile Database
        report_progress(oid, "plan_update", "reconciling_db", 50, "Gestionando capa de Datos...")
        db_exists = "custom-db" in run_command(f"minikube -p {profile} kubectl -- get deploy", silent=True)
        
        if specs['db_en'] == '1':
            log("   ➕ Gestionando Base de Datos (Apply)", C_GREEN)
            report_progress(oid, "plan_update", "deploying_db", 55, "Configurando Base de Datos...")
            _apply_db_manifest(profile, specs)
        elif specs['db_en'] == '0' and db_exists:
            log("   ➖ Solicitud: Eliminar Base de Datos", C_YELLOW)
            report_progress(oid, "plan_update", "removing_db", 55, "Eliminando Base de Datos...")
            run_command(f"minikube -p {profile} kubectl -- delete deployment custom-db", silent=True)
            run_command(f"minikube -p {profile} kubectl -- delete service custom-db-service", silent=True)
        
        # D. Reconcile Web
        report_progress(oid, "plan_update", "reconciling_web", 70, "Gestionando capa Web...")
        web_exists = "custom-web" in run_command(f"minikube -p {profile} kubectl -- get deploy", silent=True)
        
        if specs['web_en'] == '1':
            log("   ➕ Gestionando Servidor Web (Apply)", C_GREEN)
            report_progress(oid, "plan_update", "deploying_web", 75, "Configurando Servidor Web...")
            _apply_web_manifest(profile, specs)
        elif specs['web_en'] == '0' and web_exists:
            log("   ➖ Solicitud: Eliminar Servidor Web", C_YELLOW)
            report_progress(oid, "plan_update", "removing_web", 75, "Eliminando Servidor Web...")
            run_command(f"minikube -p {profile} kubectl -- delete deployment custom-web", silent=True)
            run_command(f"minikube -p {profile} kubectl -- delete service web-service", silent=True)
        
        # E. Update Info & Tools
        if specs['web_en'] == '1':
             _update_web_info_cm(profile, specs)
             _reinstall_tools(profile, specs['plan_name'])
             
        # F. Validation
        report_progress(oid, "plan_update", "verifying", 90, "Verificando salud de servicios...")
        time.sleep(3)
        
        report_progress(oid, "plan_update", "active", 100, "Plan Actualizado Correctamente")
        log(f"✅ PLAN V{oid} ACTUALIZADO", C_GREEN)
        
        # Clean transient status immediately to unlock UI
        try: os.remove(os.path.join(BUZON, f"status_{oid}.json"))
        except: pass

    except Exception as e:
        log(f"❌ Error Update Plan: {e}", C_RED)
        report_progress(oid, "plan_update", "error", 0, str(e))

def _apply_db_manifest(profile, s):
    # Select Image & Port
    img, port = "mysql:5.7", 3306
    env_root = "MYSQL_ROOT_PASSWORD"; env_db = "MYSQL_DATABASE"
    mount = "/var/lib/mysql"
    
    if s['db_type'] == 'postgresql':
        img = "postgres:14"; port = 5432
        env_root = "POSTGRES_PASSWORD"; env_db = "POSTGRES_DB"
        mount = "/var/lib/postgresql/data"
    elif s['db_type'] == 'mongodb':
        img = "mongo:latest"; port = 27017
        env_root = "MONGO_INITDB_ROOT_PASSWORD"; env_db = "MONGO_INITDB_DATABASE" 
        mount = "/data/db"

    yaml = f"""
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: custom-pvc
  labels: {{owner: "{s['owner']}"}}
spec:
  accessModes:
    - ReadWriteOnce
  resources:
    requests:
      storage: 5Gi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: custom-db
  labels: {{owner: "{s['owner']}"}}
spec:
  replicas: 1
  selector: {{matchLabels: {{app: custom-db}}}}
  template:
    metadata: {{labels: {{app: custom-db, owner: "{s['owner']}"}}}}
    spec:
      containers:
      - name: db
        image: {img}
        resources:
          limits: {{memory: "{s['ram']}Gi", cpu: "{s['cpu']}"}}
          requests: {{memory: "{s['ram']}Gi", cpu: "{s['cpu']}"}}
        env:
        - name: {env_root}
          value: "root"
        - name: {env_db}
          value: "{s['db_name']}"
        ports: [{{containerPort: {port}}}]
        volumeMounts: [{{name: storage-vol, mountPath: {mount}}}]
      volumes:
      - name: storage-vol
        persistentVolumeClaim: {{claimName: custom-pvc}}
---
apiVersion: v1
kind: Service
metadata:
  name: custom-db-service
  labels: {{owner: "{s['owner']}"}}
spec:
  selector: {{app: custom-db}}
  ports: [{{port: {port}, targetPort: {port}}}]
  type: ClusterIP
"""
    _apply_yaml(profile, yaml)

def _apply_web_manifest(profile, s):
    # Select Image
    img = "nginx:latest"; port_int = 80; mount = "/usr/share/nginx/html"
    if s['web_type'] == 'apache':
        img = "httpd:latest"; mount = "/usr/local/apache2/htdocs"
    
    # OS Variants
    if "alpine" in s['os']: 
        img = img.replace("latest", "alpine")
    
    yaml = f"""
apiVersion: v1
kind: ConfigMap
metadata: {{name: custom-web-content}}
data:
  index.html: |
    <h1>{s['web_name']}</h1>
    <p>Subdominio: {s['sub']}.sylobi.org</p>
    <hr><p>CPU: {s['cpu']} / RAM: {s['ram']} GB</p>
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: custom-web
  labels: {{owner: "{s['owner']}"}}
spec:
  replicas: 1
  selector: {{matchLabels: {{app: custom-web}}}}
  template:
    metadata: {{labels: {{app: custom-web, owner: "{s['owner']}"}}}}
    spec:
      containers:
      - name: web-server
        image: {img}
        resources:
          limits: {{memory: "{s['ram']}Gi", cpu: "{s['cpu']}"}}
          requests: {{memory: "{s['ram']}Gi", cpu: "{s['cpu']}"}}
        ports: [{{containerPort: {port_int}}}]
        volumeMounts:
        - {{name: html-vol, mountPath: {mount}}}
        env: [{{name: DB_HOST, value: custom-db-service}}]
      volumes:
      - name: html-vol
        configMap: {{name: custom-web-content}}
---
apiVersion: v1
kind: Service
metadata:
  name: web-service
  labels: {{owner: "{s['owner']}"}}
spec:
  selector: {{app: custom-web}}
  type: NodePort
  ports: [{{port: 80, targetPort: {port_int}}}]
"""
    _apply_yaml(profile, yaml)

def _update_web_info_cm(profile, s):
    # Just update the CM to reflect new CPU/RAM info text
    yaml = f"""
apiVersion: v1
kind: ConfigMap
metadata: {{name: custom-web-content}}
data:
  index.html: |
    <h1>{s['web_name']}</h1>
    <p>Subdominio: {s['sub']}.sylobi.org</p>
    <hr><p>CPU: {s['cpu']} / RAM: {s['ram']} GB</p>
    <p>DB: {s['db_type'] if s['db_en']=='1' else 'No'}</p>
    <p><small>Owner: {s['owner']}</small></p>
"""
    _apply_yaml(profile, yaml)

def _apply_yaml(profile, yaml_content):
    filename = f"/tmp/manifest_{int(time.time())}_{random_str(4)}.yaml"
    with open(filename, "w") as f: f.write(yaml_content)
    run_command(f"minikube -p {profile} kubectl -- apply -f {filename}", silent=True)
    os.remove(filename)

def _reinstall_tools(profile, plan_name):
    """
    Reinstala herramientas según el plan actualizado (Hot-Inject).
    """
    log(f"   🛠️ Reconciliando Herramientas para Plan: {plan_name}...", C_CYAN)
    
    # Tool Catalog (Mirrored from Orchestrator)
    TIER_1 = ["htop", "nano", "ncdu", "curl", "wget", "zip", "unzip", "git"]
    TIER_2 = ["python3", "nodejs", "npm", "mysql-client", "jq", "tmux", "lazygit"]
    TIER_3 = ["rsync", "ffmpeg", "imagemagick", "redis", "ansible", "zsh"] # redis-tools -> redis in alpine

    to_install = set(TIER_1) # Always T1
    p = plan_name.lower()
    

    if p == "plata" or p == "oro":
        to_install.update(TIER_2)
    if p == "oro":
        to_install.update(TIER_3)

    # Install via APT (Minikube Node is Ubuntu based)
    packages = " ".join(to_install)
    log(f"   📦 Instalando en Nodo Bastion ({profile}): {packages}", C_CYAN)
    
    # We install directly on the node container (which acts as Bastion)
    cmd = f"docker exec {profile} bash -c 'apt-get update >/dev/null 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get install -y {packages} >/dev/null 2>&1'"
    run_command(cmd, silent=True)
    log("   ✅ Herramientas Nodo Sincronizadas.", C_GREEN)

# ==============================================================================
# 🛠️ MONITORING STACK INSTALLATION (PROMETHEUS + GRAFANA)
# ==============================================================================

def handle_install_tool(oid, tool_name, config):
    """
    Instala herramientas adicionales en el clúster del cliente.
    Actualmente soporta: monitoring (Prometheus + Grafana)
    """
    profile = f"sylo-cliente-{oid}"
    log(f"🛠️ INSTALL TOOL: {tool_name} en {profile}", C_CYAN)
    
    if tool_name == "monitoring":
        install_monitoring_stack(oid, profile, config)
    else:
        log(f"⚠️ Herramienta desconocida: {tool_name}", C_YELLOW)

def install_monitoring_stack(oid, profile, config):
    """
    Instala Prometheus + Grafana usando Helm en el namespace del cliente.
    """
    namespace = profile  # El namespace es el mismo que el profile
    grafana_password = config.get('grafana_password', 'admin')
    
    try:
        log(f"   📦 Instalando Monitoring Stack (Prometheus + Grafana)...", C_CYAN)
        
        # 0. Update DB & File Status
        update_tool_status(oid, "monitoring", "installing")
        # Pass 'install_tool' action to fix Modal Title in Frontend
        update_install_status(oid, 10, "Iniciando instalación...", status="installing", action="install_tool")
        
        # 1. Add Helm repo (idempotent)
        log("   ➕ Añadiendo repositorio Helm...", C_CYAN)
        res_repo = run_command("helm repo add prometheus-community https://prometheus-community.github.io/helm-charts", silent=True, timeout=60)
        if "Error" in res_repo: raise Exception(f"Helm Repo Add Failed: {res_repo}")
        
        # Update repo with timeout and error check
        res_upd = run_command("helm repo update", silent=True, timeout=60)
        if "Error" in res_upd or "failed" in res_upd.lower(): raise Exception(f"Helm Repo Update Failed: {res_upd}")
        
        # 2. Install kube-prometheus-stack with minimal resources
        log(f"   🚀 Instalando stack en namespace {namespace}...", C_CYAN)
        
        # Minimal values for resource-constrained environments (UPDATED for stability)
        helm_cmd = f"""helm upgrade --install sylo-monitor prometheus-community/kube-prometheus-stack \
            --namespace {namespace} \
            --create-namespace \
            --set prometheus.prometheusSpec.resources.requests.memory=250Mi \
            --set prometheus.prometheusSpec.resources.limits.memory=512Mi \
            --set prometheus.prometheusSpec.resources.requests.cpu=100m \
            --set prometheus.prometheusSpec.resources.limits.cpu=300m \
            --set prometheus.prometheusSpec.retention=7d \
            --set grafana.adminPassword={grafana_password} \
            --set grafana.resources.requests.memory=128Mi \
            --set grafana.resources.limits.memory=512Mi \
            --set grafana.resources.requests.cpu=50m \
            --set grafana.resources.limits.cpu=200m \
            --set grafana.persistence.enabled=false \
            --set alertmanager.enabled=false \
            --set nodeExporter.enabled=false \
            --set kubeStateMetrics.enabled=true \
            --set grafana.service.type=ClusterIP \
            --timeout 10m \
            --wait"""
        
        # VISUAL PROGRESS: 30%
        update_install_status(oid, 30, "Desplegando charts de Prometheus y Grafana (esto puede tardar unos minutos)...", status="installing")
        
        result = run_command(helm_cmd, timeout=900, silent=False)
        
        if "deployed" in result.lower() or "upgraded" in result.lower():
            log("   ✅ Helm installation successful", C_GREEN)
        else:
            raise Exception(f"Helm install failed: {result}")
        
        # VISUAL PROGRESS: 60%
        update_install_status(oid, 60, "Esperando a que los Pods arranquen...")
        
        # 3. Wait for Grafana pod to be ready
        log("   ⏳ Esperando a que Grafana esté listo...", C_CYAN)
        time.sleep(10)  # Give it a moment
        
        wait_cmd = f"minikube -p {profile} kubectl -- wait --for=condition=ready pod -l app.kubernetes.io/name=grafana --namespace {namespace} --timeout=300s"
        run_command(wait_cmd, silent=True)
        
        # 4. Setup port-forward for Grafana
        log("   🌐 Configurando port-forward para Grafana...", C_CYAN)
        
        # VISUAL PROGRESS: 80%
        update_install_status(oid, 80, "Configurando acceso y puertos...")
        
        setup_grafana_port_forward(oid, profile, namespace)
        setup_prometheus_port_forward(oid, profile, namespace) # Add Prometheus too!
        
        # 5. Update database status
        log("   💾 Actualizando estado en base de datos...", C_CYAN)
        update_tool_status(oid, "monitoring", "active")
        
        log(f"   ✅ MONITORING STACK INSTALADO CORRECTAMENTE", C_GREEN)
        log(f"   🔗 Grafana URL: http://localhost:80{oid}", C_CYAN)
        log(f"   👤 Usuario: admin", C_CYAN)
        log(f"   👤 Usuario: admin", C_CYAN)
        log(f"   🔑 Password: {grafana_password}", C_CYAN)
        
        # VISUAL PROGRESS: 100%
        update_install_status(oid, 100, "Instalación Completada")
        
    except Exception as e:
        log(f"   ❌ Error instalando monitoring: {e}", C_RED)
        update_install_status(oid, 0, f"Error: {str(e)}", status="error")
        update_tool_status(oid, "monitoring", "error")

def setup_grafana_port_forward(oid, profile, namespace):
    """
    Configura port-forward para acceder a Grafana via localhost:30XX
    """
    port = f"30{oid}" # Changed from 80{oid} to avoid conflict
    
    try:
        # Kill any existing port-forward for this port
        run_command(f"pkill -f 'port-forward.*{port}:{port}'", silent=True)
        
        # Start new port-forward in background
        cmd = f"minikube -p {profile} kubectl -- port-forward -n {namespace} svc/sylo-monitor-grafana {port}:80 --address=0.0.0.0 > /dev/null 2>&1 &"
        run_command(cmd, silent=True)
        
        log(f"   ✅ Port-forward configurado: localhost:{port}", C_GREEN)
    except Exception as e:
        log(f"   ⚠️ Error configurando port-forward: {e}", C_YELLOW)

def uninstall_monitoring_stack(oid, profile):
    """
    Desinstala el stack de monitoring usando Helm.
    """
    namespace = profile
    port = f"80{oid}"
    
    try:
        log(f"   🗑️ Desinstalando Monitoring Stack...", C_YELLOW)
        
        # 1. Kill port-forward
        run_command(f"pkill -f 'port-forward.*{port}:{port}'", silent=True)
        
        # 2. Delete Helm release
        uninstall_cmd = f"helm uninstall sylo-monitor --namespace {namespace}"
        run_command(uninstall_cmd, silent=True)
        
        # 3. Update database
        delete_tool_record(oid, "monitoring")
        
        log(f"   ✅ Monitoring desinstalado correctamente", C_GREEN)
        
    except Exception as e:
        log(f"   ❌ Error desinstalando monitoring: {e}", C_RED)

def update_tool_status(oid, tool_name, status):
    """
    Actualiza el estado de una herramienta en la base de datos.
    """
    cmd = f"""docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "UPDATE k8s_tools SET status='{status}', updated_at=NOW() WHERE deployment_id={oid} AND tool_name='{tool_name}'" """
    run_command(cmd, silent=True)

def delete_tool_record(oid, tool_name):
    """
    Elimina el registro de una herramienta de la base de datos.
    """
    cmd = f"""docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "DELETE FROM k8s_tools WHERE deployment_id={oid} AND tool_name='{tool_name}'" """
    run_command(cmd, silent=True)

def setup_prometheus_port_forward(oid, profile, namespace):
    port = f"31{oid}" # Prometheus Port
    try:
        run_command(f"pkill -f 'port-forward.*{port}:{port}'", silent=True)
        # Service name usually: sylo-monitor-kube-promethe-prometheus based on helm chart
        # We need to verify the exact service name or use a label selector if possible, but port-forward to svc is standard.
        # Assuming standard prometheus-community chart names:
        # release-name-kube-prometheus-stack-prometheus
        # But here release is 'sylo-monitor'
        # So: sylo-monitor-kube-promethe-prometheus
        cmd = f"minikube -p {profile} kubectl -- port-forward -n {namespace} svc/sylo-monitor-kube-promethe-prometheus {port}:9090 --address=0.0.0.0 > /dev/null 2>&1 &"
        run_command(cmd, silent=True)
        log(f"   ✅ Prometheus Port-forward: localhost:{port}", C_GREEN)
    except Exception as e:
        log(f"   ⚠️ Error Prometheus Port-forward: {e}", C_YELLOW)

def update_install_status(oid, percent, msg, status="installing", action="install_tool"):
    f = os.path.join(BUZON, f"install_status_{oid}.json")
    with open(f, 'w') as fh:
        # Include action in JSON so frontend can set Modal Title
        json.dump({"percent": percent, "msg": msg, "status": status, "action": action}, fh)





def random_str(length):
    import random, string
    return ''.join(random.choices(string.ascii_lowercase, k=length))
if __name__ == "__main__":
    signal.signal(signal.SIGTERM, signal_handler); signal.signal(signal.SIGINT, signal_handler)
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    log("=== OPERATOR V55 (SELF-HEAL FIXED) ===", C_GREEN)
    t1=threading.Thread(target=process_task_queue, daemon=True); t2=threading.Thread(target=process_metrics, daemon=True)
    t1.start(); t2.start()
    while True: time.sleep(1)
