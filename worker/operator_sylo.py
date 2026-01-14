#!/usr/bin/env python3
import sys, os, subprocess, time, json, datetime, threading, signal, shutil, tarfile, glob, codecs

# ==============================================================================
# ⚙️ CONFIGURACIÓN
# ==============================================================================
sys.stdout.reconfigure(line_buffering=True)
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
API_URL = "http://127.0.0.1:8001/api/clientes" 

status_lock = threading.Lock()
cmd_lock = threading.Lock()
shutdown_event = threading.Event()

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
def run_command(cmd, timeout=300, silent=False):
    with cmd_lock:
        try:
            if not silent: log(f"CMD > {cmd}", C_YELLOW)
            res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout, bufsize=10485760)
            if res.returncode != 0:
                if "grep" not in cmd: log(f"⚠️ ERROR ({res.returncode}): {res.stderr.strip()[:200]}", C_RED)
            elif not silent:
                preview = res.stdout.strip()[:100].replace('\n', ' ')
                if preview: log(f"   OUT: {preview}...", C_GREY)
            return res.stdout.strip()
        except Exception as e:
            log(f"❌ EXCEPCIÓN: {e}", C_RED)
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
        if "Running" in status: candidates.append(name)
    
    for c in candidates: 
        if "web" in c: return c
    for c in candidates: 
        if "http" in c or "nginx" in c or "apache" in c or "custom" in c: return c
    if candidates: return candidates[0]
    return None

def find_active_configmap(profile, pod_name):
    # En la nueva arquitectura, siempre es custom-web-content
    return "custom-web-content"

# ==============================================================================
# 🚀 ACCIONES
# ==============================================================================

# 1. EDITAR WEB
def update_web_content(oid, profile, html_content, is_restore_process=False):
    t_type = "backup" if is_restore_process else "web"
    t_stat = "restoring" if is_restore_process else "web_updating"
    log(f"🔧 PROCESANDO WEB Cliente {oid}...", C_GREEN)
    
    report_progress(oid, t_type, t_stat, 10, "Buscando Pod...")
    if is_restore_process: time.sleep(1)
    
    try:
        pod = find_web_pod(profile)
        if not pod: raise Exception("Pod Web no encontrado (¿apagado?)")
        target_cm = find_active_configmap(profile, pod)
        
        temp_file = os.path.join(WORKER_DIR, f"temp_web_{oid}.html")
        with codecs.open(temp_file, "w", "utf-8") as f: f.write(html_content)
            
        report_progress(oid, t_type, t_stat, 40, f"Actualizando {target_cm}...")
        run_command(f"minikube -p {profile} kubectl -- delete cm {target_cm} --ignore-not-found", silent=False)
        res = run_command(f"minikube -p {profile} kubectl -- create cm {target_cm} --from-file=index.html={temp_file}", silent=False)
        
        if "created" not in res: raise Exception("Fallo ConfigMap")

        report_progress(oid, t_type, t_stat, 70, "Reiniciando...")
        run_command(f"minikube -p {profile} kubectl -- delete pod {pod} --wait=false", silent=False)
        if os.path.exists(temp_file): os.remove(temp_file)
        if os.path.exists(temp_file): os.remove(temp_file)
        
        # Esperar a que el Pod esté realmente Running (hasta 40s)
        time.sleep(5) # Grace period
        new_pod = None
        for i in range(20):
            report_progress(oid, t_type, t_stat, 80, f"Verificando... ({i*2}s)")
            new_pod = find_web_pod(profile)
            if new_pod:
                s = run_command(f"minikube -p {profile} kubectl -- get pod {new_pod} -o jsonpath='{{.status.phase}}'", silent=True)
                if "Running" in s: break
            time.sleep(2)
        
        report_progress(oid, t_type, "completed" if is_restore_process else "web_completed", 100, "Online")
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
        pod = find_web_pod(profile)
        if pod:
            report_progress(oid, "backup", "creating", 30, "Leyendo...")
            html = run_command(f"minikube -p {profile} kubectl -- exec {pod} -- cat /usr/share/nginx/html/index.html", silent=True)
            if not html or "No such file" in html: html = run_command(f"minikube -p {profile} kubectl -- exec {pod} -- cat /var/www/html/index.html", silent=True)
            
            with open(os.path.join(temp_dir, "index.html"), "w") as f: f.write(html if html and "No such file" not in html else "")
        else:
            with open(os.path.join(temp_dir, "index.html"), "w") as f: f.write("")
        
        report_progress(oid, "backup", "creating", 70, "Guardando...")
        ts = datetime.datetime.now().strftime('%Y%m%d%H%M%S')
        safe_name = "".join(x for x in backup_name if x.isalnum())
        fname = f"backup_v{oid}_{backup_type.lower()}_{safe_name}_{ts}.tar.gz"
        
        with tarfile.open(os.path.join(BUZON, fname), "w:gz") as tar: tar.add(temp_dir, arcname="data")
        report_progress(oid, "backup", "completed", 100, "OK")
        report_backups_list(oid)
        log(f"💾 Guardado: {fname}", C_GREEN)
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
    except Exception as e: report_progress(oid, "backup", "error", 0, str(e))
    finally:
        if os.path.exists(ext_dir): shutil.rmtree(ext_dir)

# 4. DELETE
def delete_backup(oid, fname):
    try:
        if os.path.exists(os.path.join(BUZON, fname)): os.remove(os.path.join(BUZON, fname))
        report_backups_list(oid)
    except: pass

# 5. DESTROY
def destroy_k8s_resources(oid, profile):
    log(f"☢️ DESTRUYENDO: CLIENTE {oid}", C_RED)
    try:
        run_command(f"minikube delete -p {profile}")
        run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'cancelled\' WHERE id={oid}"', silent=False)
        log(f"⚰️ ELIMINADO", C_RED)
    except: pass

# 6. 🔥 CONTROL DE ENERGÍA (NUEVO)
def handle_power(oid, profile, action):
    action = action.upper()
    log(f"🔌 ENERGÍA: {action} Cliente {oid}", C_YELLOW)
    
    try:
        if action == "STOP":
            report_progress(oid, "power", "stopping", 20, "Deteniendo servicios...")
            # Escalar a 0 réplicas (apagar)
            run_command(f"minikube -p {profile} kubectl -- scale deployment --all --replicas=0")
            
            # Actualizar DB a 'stopped' para que el Dashboard lo sepa
            log("💤 Marcando como STOPPED en DB...", C_GREY)
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'stopped\' WHERE id={oid}"')
            
            report_progress(oid, "power", "stopped", 100, "Hibernando")
            log(f"🛑 CLIENTE {oid} DETENIDO", C_RED)

        elif action == "START":
            report_progress(oid, "power", "starting", 20, "Iniciando servicios...")
            # Escalar a 1 réplica (encender)
            run_command(f"minikube -p {profile} kubectl -- scale deployment --all --replicas=1")
            
            # Actualizar DB a 'active'
            log("⚡ Marcando como ACTIVE en DB...", C_GREY)
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'active\' WHERE id={oid}"')
            
            time.sleep(5) # Esperar arranque
            report_progress(oid, "power", "started", 100, "Online")
            log(f"🟢 CLIENTE {oid} INICIADO", C_GREEN)

        elif action == "RESTART":
            report_progress(oid, "power", "restarting", 20, "Reiniciando pods...")
            # Rollout restart (Reinicio suave)
            run_command(f"minikube -p {profile} kubectl -- rollout restart deployment")
            
            report_progress(oid, "power", "restarted", 100, "Reiniciado")
            log(f"🔄 CLIENTE {oid} REINICIADO", C_CYAN)

    except Exception as e:
        log(f"❌ Error Energía: {e}", C_RED)
        report_progress(oid, "power", "error", 0, str(e))

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
                
                # 🔥 NUEVOS COMANDOS DE ENERGÍA
                elif act in ["STOP", "START", "RESTART"]:
                    threading.Thread(target=handle_power, args=(oid, prof, act)).start()
                
            except Exception as e: log(f"⚠️ Error: {e}", C_RED)
        time.sleep(0.5)

def process_metrics():
    log("📊 Métricas activas.", C_GREEN)
    while not shutdown_event.is_set():
        try:
            raw = run_command("docker ps --format '{{.Names}}'", silent=True)
            for line in raw.splitlines():
                if "sylo-cliente-" in line:
                    oid = line.replace("sylo-cliente-", "")
                    stats = run_command(f"docker stats {line} --no-stream --format '{{{{.CPUPerc}}}},{{{{.MemPerc}}}}'", silent=True)
                    c,r = 0,0
                    if "," in stats:
                        try: c=float(stats.split(',')[0].replace('%','')); r=float(stats.split(',')[1].replace('%',''))
                        except: pass
                    
                    db_cmd = f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT subdomain FROM order_specs WHERE order_id={oid}"'
                    sub = run_command(db_cmd, silent=True).strip()
                    url = f"http://{sub}.sylobi.org" if sub and sub!="NULL" and len(sub)>0 else "..."
                    
                    requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":c,"ram":r}, "ssh_cmd": "root@sylo", "web_url": url, "os_info": "Linux Container", "installed_tools": []}, timeout=1)
                    report_backups_list(oid)
        except: pass
        time.sleep(2)

if __name__ == "__main__":
    signal.signal(signal.SIGTERM, signal_handler); signal.signal(signal.SIGINT, signal_handler)
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    log("=== OPERATOR V51 (POWER CONTROL) ===", C_GREEN)
    t1=threading.Thread(target=process_task_queue, daemon=True); t2=threading.Thread(target=process_metrics, daemon=True)
    t1.start(); t2.start()
    while True: time.sleep(1)