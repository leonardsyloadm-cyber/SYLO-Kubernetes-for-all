#!/usr/bin/env python3
import sys, os, subprocess, time, json, datetime, threading, signal, shutil, tarfile, glob, codecs

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
    try:
        requests.post(f"{API_URL}/reportar/progreso", json={
            "id_cliente": int(oid), "tipo": tipo, "status_text": status, "percent": int(pct), "msg": str(msg)
        }, timeout=2)
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
        run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'cancelled\' WHERE id={oid}"', silent=False)
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
            
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'stopped\' WHERE id={oid}"', check_error=True)
            
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
        fixed_ip = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT ip_address FROM orders WHERE id={oid}"', silent=True).strip()
    except: fixed_ip = ""

    # 2. OBTENER RAM CONTRATADA (Specs)
    try:
        # Extraemos la RAM definida en el plan del usuario
        db_ram_str = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT ram_gb FROM order_specs WHERE order_id={oid}"', silent=True).strip()
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
    cmd_start = f"minikube start -p {profile} --memory={alloc_mb}m --force"
    if fixed_ip and len(fixed_ip) > 6:
        cmd_start += f" --static-ip {fixed_ip}"
    
    run_command(cmd_start, check_error=True)
    
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
        
        specs_json = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT JSON_OBJECT(\'cpu\', cpu_cores, \'ram\', ram_gb, \'storage\', storage_gb, \'db_en\', db_enabled, \'db_type\', db_type, \'web_en\', web_enabled, \'web_type\', web_type, \'ssh_user\', ssh_user, \'os\', os_image, \'db_name\', db_custom_name, \'web_name\', web_custom_name, \'subdomain\', subdomain) FROM order_specs WHERE order_id={oid}"', silent=True).strip()
        
        if specs_json and "{" in specs_json:
            sp = json.loads(specs_json)
            deploy_script = os.path.join(BASE_DIR, "tofu-k8s/custom-stack/deploy_custom.sh")
            cmd_repair = f"bash {deploy_script} {oid} {sp['cpu']} {sp['ram']} {sp['storage']} {sp['db_en']} \"{sp['db_type']}\" {sp['web_en']} \"{sp['web_type']}\" \"{sp['ssh_user']}\" \"{sp['os']}\" \"{sp['db_name']}\" \"{sp['web_name']}\" \"{sp['subdomain']}\""
            
            run_command(cmd_repair, check_error=True)
            log("✅ Reconstrucción completada.", C_GREEN)
            time.sleep(5)
        else:
            raise Exception("Imposible recuperar Specs para reparación")

    run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'active\' WHERE id={oid}"', check_error=True)
    report_progress(oid, "power", final_type, 100, "Online")
    log(f"🟢 CLIENTE {oid} ONLINE", C_GREEN)
    if int(oid) in blocked_cids: blocked_cids.remove(int(oid))

# ==============================================================================
# 🔄 WORKERS
# ==============================================================================
def process_task_queue():
    log("📥 Cola lista.", C_GREEN)
    while not shutdown_event.is_set():
        for f in glob.glob(os.path.join(BUZON, "accion_*.json")):
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
                
            except Exception as e: log(f"⚠️ Error: {e}", C_RED)
        time.sleep(0.5)

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
                    else: continue
                    
                    if oid in blocked_cids: continue
                    try:
                        check_stat = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT status FROM orders WHERE id={oid}"', silent=True).strip().lower()
                        if check_stat in ['stopped', 'stopping', 'cancelled', 'terminated']: continue
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
                        db_cmd = f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT subdomain, os_image, web_enabled, web_type, db_enabled, db_type FROM order_specs WHERE order_id={oid}"'
                        raw_data = run_command(db_cmd, silent=True).strip()
                        sub, os_img, web_en, web_type, db_en, db_type = "", "Linux", "0", "", "0", ""
                        if raw_data:
                            cols = raw_data.split('\t')
                            if len(cols) >= 6:
                                sub = cols[0] if cols[0] != "NULL" else ""
                                os_img = cols[1] if cols[1] != "NULL" else "Linux"
                                web_en = cols[2]; web_type = cols[3] if cols[3] != "NULL" else ""
                                db_en = cols[4]; db_type = cols[5] if cols[5] != "NULL" else ""

                        url = f"http://{sub}.sylobi.org" if sub and len(sub)>0 else "..."
                        tools = []
                        if os_img: tools.append(os_img)
                        if str(web_en) == "1" and web_type: tools.append(web_type)
                        if str(db_en) == "1" and db_type: tools.append(db_type)
                        
                        requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":c,"ram":r}, "ssh_cmd": "root@sylo", "web_url": url, "os_info": "os_img", "installed_tools": tools}, timeout=1)
                        report_backups_list(oid)
                    except:
                        requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":c,"ram":r}, "ssh_cmd": "root@sylo", "web_url": "...", "os_info": "Linux", "installed_tools": []}, timeout=1)
        except: pass
        time.sleep(2)

if __name__ == "__main__":
    signal.signal(signal.SIGTERM, signal_handler); signal.signal(signal.SIGINT, signal_handler)
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    log("=== OPERATOR V55 (SELF-HEAL FIXED) ===", C_GREEN)
    t1=threading.Thread(target=process_task_queue, daemon=True); t2=threading.Thread(target=process_metrics, daemon=True)
    t1.start(); t2.start()
    while True: time.sleep(1)