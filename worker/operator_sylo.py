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
        
        # 1. Eliminar entradas viejas para ese dominio
        # sed -i '/domain/d' /etc/hosts
        clean_cmd = f"sudo sed -i '/{domain}/d' /etc/hosts"
        run_command(clean_cmd, silent=True)
        
        # 2. Añadir nueva
        # echo "IP domain" | sudo tee -a /etc/hosts
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
        
        # Aceptar amplia gama de estados para detección temprana (incluso errores para que se reporten)
        accepted_status = ["Running", "ContainerCreating", "Pending", "PodInitializing", "Init", "BackOff", "Error", "Err", "Unknown", "CrashLoopBackOff"]
        if any(s in status for s in accepted_status): 
            candidates.append(name)
            # LOG DE DEBUG (SOLICITADO POR USUARIO)
            # log(f"   🔎 Candidato encontrado: {name} [{status}]", C_GREY)
    
    if not candidates:
        # log(f"   ⚠️ No se encontraron pods candidatos en el perfil {profile} namespace default", C_GREY)
        return None

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
    
    report_progress(oid, t_type, t_stat, 10, "Orden procesada...")
    time.sleep(1.5) # UX Delay
    if is_restore_process: time.sleep(1)
    
    try:
        # RETRY LOOP: Esperar a que el Pod aparezca (max 60s)
        pod = None
        for i in range(20):
            pod = find_web_pod(profile)
            if pod: break
            # Si tarda mucho, mantener en 15%
            report_progress(oid, t_type, t_stat, 15, f"Buscando Pod... ({i*3}s)")
            time.sleep(3)
            
        if not pod: raise Exception("Pod Web no encontrado (¿apagado?)")
        target_cm = find_active_configmap(profile, pod)
        
        temp_file = os.path.join(WORKER_DIR, f"temp_web_{oid}.html")
        with codecs.open(temp_file, "w", "utf-8") as f: f.write(html_content)
            
        # CMD 1: Delete CM (30%)
        report_progress(oid, t_type, t_stat, 30, f"Limpiando configuración... (CMD 1)")
        time.sleep(1.5) 
        run_command(f"minikube -p {profile} kubectl -- delete cm {target_cm} --ignore-not-found", silent=False)
        
        # CMD 2: Create CM (60%)
        report_progress(oid, t_type, t_stat, 60, f"Subiendo nuevo contenido... (CMD 2)")
        time.sleep(1.5)
        res = run_command(f"minikube -p {profile} kubectl -- create cm {target_cm} --from-file=index.html={temp_file}", silent=False)
        
        if "created" not in res: raise Exception("Fallo ConfigMap")

        # CMD 3: Delete Pod (75%)
        report_progress(oid, t_type, t_stat, 75, "Aplicando cambios... (CMD 3)")
        time.sleep(1.5)
        run_command(f"minikube -p {profile} kubectl -- delete pod {pod} --wait=false", silent=False)
        
        if os.path.exists(temp_file): os.remove(temp_file)
        if os.path.exists(temp_file): os.remove(temp_file)
        
        # Esperar a que el Pod esté realmente Running (hasta 40s)
        time.sleep(5) # Grace period
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
        # Step 1: Find Pod (10%)
        report_progress(oid, "backup", "creating", 10, "Iniciando...")
        time.sleep(1.5)

        pod = find_web_pod(profile)
        if pod:
            # Step 2: Read Data (30%)
            report_progress(oid, "backup", "creating", 30, "Leyendo datos...")
            time.sleep(1.5)
            
            html = run_command(f"minikube -p {profile} kubectl -- exec {pod} -- cat /usr/share/nginx/html/index.html", silent=True)
            if not html or "No such file" in html: html = run_command(f"minikube -p {profile} kubectl -- exec {pod} -- cat /var/www/html/index.html", silent=True)
            
            with open(os.path.join(temp_dir, "index.html"), "w") as f: f.write(html if html and "No such file" not in html else "")
        else:
            with open(os.path.join(temp_dir, "index.html"), "w") as f: f.write("")
        
        # Step 3: Compress (60%)
        report_progress(oid, "backup", "creating", 60, "Comprimiendo...")
        time.sleep(1.5)

        # Step 4: Finalize (80%)
        report_progress(oid, "backup", "creating", 80, "Guardando en disco...")
        time.sleep(1.5)

        ts = datetime.datetime.now().strftime('%Y%m%d%H%M%S')
        safe_name = "".join(x for x in backup_name if x.isalnum())
        fname = f"backup_v{oid}_{backup_type.lower()}_{safe_name}_{ts}.tar.gz"
        full_path = os.path.join(BUZON, fname)
        
        with tarfile.open(full_path, "w:gz") as tar: tar.add(temp_dir, arcname="data")
        
        # FIX PERMISSIONS: Ensure PHP (www-data) can read the file created by 'ivan'
        try: os.chmod(full_path, 0o644)
        except: pass

        # Step 5: Complete (100%)
        report_progress(oid, "backup", "completed", 100, "Backup Completado")
        report_backups_list(oid)
        log(f"💾 Guardado: {fname}", C_GREEN)
        
        # UX: Auto-Hide "Completed" bar after 4 seconds
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
        
        # UX: Auto-Hide Restore bar too
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
        
        # UX: Auto-Hide Delete bar
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

# 6. 🔥 CONTROL DE ENERGÍA (NUEVO)
def handle_power(oid, profile, action):
    action = action.upper()
    log(f"🔌 ENERGÍA: {action} Cliente {oid}", C_YELLOW)
    
    try:
        if action == "STOP":
            report_progress(oid, "power", "stopping", 10, "Deteniendo clúster (esto puede tardar)...")
            # VERIFICAR SI PERFIL EXISTE PRIMERO
            check_prof = run_command(f"minikube profile list -o json", silent=True)
            if profile not in check_prof: raise Exception(f"Perfil Kubernetes {profile} no encontrado")

            # APAGADO REAL
            run_command(f"minikube stop -p {profile}", check_error=True)
            
            # Actualizar DB a 'stopped' para que el Dashboard lo sepa
            log("💤 Marcando como STOPPED en DB...", C_GREY)
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'stopped\' WHERE id={oid}"', check_error=True)
            
            # 🔥 LIMPIAR MÉTRICAS (Enviar 0%)
            try:
                requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":0,"ram":0}, "ssh_cmd": "Offline", "web_url": "", "os_info": "Offline", "installed_tools": []}, timeout=2)
            except: pass

            report_progress(oid, "power", "stopped", 100, "Hibernando")
            log(f"🛑 CLIENTE {oid} DETENIDO", C_RED)

        elif action == "START":
            report_progress(oid, "power", "starting", 10, "Iniciando clúster (esto puede tardar)...")
            
            # VERIFICAR SI PERFIL EXISTE
            check_prof = run_command(f"minikube profile list -o json", silent=True)
            if profile not in check_prof: raise Exception(f"Perfil Kubernetes {profile} no encontrado")

            # OBTENER IP FIJA (Sylo DNS)
            try:
                fixed_ip = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT ip_address FROM orders WHERE id={oid}"', silent=True).strip()
            except: fixed_ip = ""

            report_progress(oid, "power", "starting", 20, f"Asignando IP Fija: {fixed_ip}..." if fixed_ip else "Iniciando...")
            
            # ENCENDIDO REAL CON IP FIJA
            cmd_start = f"minikube start -p {profile}"
            if fixed_ip and len(fixed_ip) > 6:
                cmd_start += f" --static-ip {fixed_ip}"
                
            run_command(cmd_start, check_error=True)
            
            # FIX: Actualizar contexto para asegurar conexión
            run_command(f"minikube -p {profile} update-context", silent=True)
            
            report_progress(oid, "power", "starting", 60, "Esperando servicios...")
            
            # ESPERAR A QUE LOS PODS ESTÉN RUNNING
            found_pod = False
            for i in range(15): # Wait 45s first
                pod = find_web_pod(profile)
                if pod:
                    s = run_command(f"minikube -p {profile} kubectl -- get pod {pod} -o jsonpath='{{.status.phase}}'", silent=True)
                    log(f"   🔎 Pod detectado: {pod} | Estado: {s}", C_CYAN)
                    if "Running" in s:
                        found_pod = True
                        break
                else:
                    log(f"   ⚠️ Escaneo {i+1}/15: Ningún pod encontrado aún...", C_GREY)

                report_progress(oid, "power", "starting", 60 + i, f"Arrancando Pods... ({i*3}s)")
                time.sleep(3)
            
            # --- AUTO-REPAIR (SELF-HEALING) ---
            if not found_pod:
                log(f"⚠️ Alerta: Pods no encontrados. Iniciando Auto-Reparación...", C_YELLOW)
                report_progress(oid, "power", "starting", 80, "Reparando servicios (Self-Healing)...")
                try:
                    # 1. Fetch Specs
                    specs_json = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT JSON_OBJECT(\'cpu\', cpu_cores, \'ram\', ram_gb, \'storage\', storage_gb, \'db_en\', db_enabled, \'db_type\', db_type, \'web_en\', web_enabled, \'web_type\', web_type, \'ssh_user\', ssh_user, \'os\', os_image, \'db_name\', db_custom_name, \'web_name\', web_custom_name, \'subdomain\', subdomain) FROM order_specs WHERE order_id={oid}"', silent=True).strip()
                    
                    if specs_json and "{" in specs_json:
                        sp = json.loads(specs_json)
                        # 2. Run Deploy Script
                        # Args: ID, CPU, RAM, STORAGE, DB_EN, DB_TYPE, WEB_EN, WEB_TYPE, SSH_USER, OS, DB_NAME, WEB_NAME, SUBDOM
                        deploy_script = os.path.join(BASE_DIR, "tofu-k8s/custom-stack/deploy_custom.sh")
                        
                        cmd_repair = f"bash {deploy_script} {oid} {sp['cpu']} {sp['ram']} {sp['storage']} {sp['db_en']} \"{sp['db_type']}\" {sp['web_en']} \"{sp['web_type']}\" \"{sp['ssh_user']}\" \"{sp['os']}\" \"{sp['db_name']}\" \"{sp['web_name']}\" \"{sp['subdomain']}\""
                        run_command(cmd_repair, check_error=True)
                        log("✅ Auto-Reparación completada.", C_GREEN)
                        found_pod = True # Assume fixed
                    else:
                        log("❌ No se pudieron obtener specs para reparar.", C_RED)
                except Exception as ex:
                    log(f"❌ Falló Auto-Reparación: {ex}", C_RED)

            # DB Update (Just in case)
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'active\' WHERE id={oid}"', check_error=True)

            report_progress(oid, "power", "started", 100, "Online")
            log(f"🟢 CLIENTE {oid} INICIADO", C_GREEN)

        elif action == "RESTART":
            report_progress(oid, "power", "restarting", 10, "Deteniendo máquina...")
            
            check_prof = run_command(f"minikube profile list -o json", silent=True)
            if profile not in check_prof: raise Exception(f"Perfil Kubernetes {profile} no encontrado")

            # 1. STOP
            run_command(f"minikube stop -p {profile}", check_error=True)
            log("💤 Marcando como STOPPED (temporal)...", C_GREY)
            # No actualizamos DB a stopped para no confundir al usuario visualmente, 
            # o sí? El usuario dijo "apague y encienda".
            # Mejor mantenemos el estado visual en "Restarting" pero internamente apagamos.
            
            time.sleep(5)
            report_progress(oid, "power", "restarting", 30, "Iniciando máquina...")

            # 2. START (con IP Fija)
            # OBTENER IP FIJA
            try:
                fixed_ip = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT ip_address FROM orders WHERE id={oid}"', silent=True).strip()
            except: fixed_ip = ""
            
            report_progress(oid, "power", "restarting", 40, f"Asignando IP: {fixed_ip}..." if fixed_ip else "Arrancando...")

            cmd_start = f"minikube start -p {profile}"
            if fixed_ip and len(fixed_ip) > 6:
                cmd_start += f" --static-ip {fixed_ip}"
            
            # --- AUTO-FIX: TRY / CATCH / RETRY ---
            try:
                # Intento 1
                res = run_command(cmd_start, check_error=False)
                if "Error" in res or "fail" in res.lower() or "conflict" in res.lower():
                    raise Exception("Fallo en arranque inicial")
            except:
                log(f"⚠️ Conflicto de IP/Certificados. Recreando contenedor...", C_YELLOW)
                report_progress(oid, "power", "starting", 45, "Solucionando conflicto de IP...")
                
                # Nuke it
                run_command(f"minikube delete -p {profile}", silent=True)
                time.sleep(2)
                
                # Intento 2 (Fresh Start)
                log(f"🔄 Reintentando arranque en {fixed_ip}...", C_CYAN)
                run_command(cmd_start, check_error=True)

            # FIX: Actualizar contexto siempre
            run_command(f"minikube -p {profile} update-context", silent=True)
            
            report_progress(oid, "power", "restarting", 60, "Esperando servicios...")

            # 3. VERIFICAR PODS & SELF-HEALING
            found_pod = False
            for i in range(15):
                pod = find_web_pod(profile)
                if pod:
                    s = run_command(f"minikube -p {profile} kubectl -- get pod {pod} -o jsonpath='{{.status.phase}}'", silent=True)
                    log(f"   🔎 Pod detectado: {pod} | Estado: {s}", C_CYAN)
                    if "Running" in s:
                        found_pod = True
                        break
                else:
                    log(f"   ⚠️ Escaneo {i+1}/15: Ningún pod encontrado aún...", C_GREY)
                    
                report_progress(oid, "power", "restarting", 60 + i, f"Arrancando Pods... ({i*3}s)")
                time.sleep(3)
            
            if not found_pod:
                log(f"⚠️ Alerta: Pods no encontrados. Iniciando Auto-Reparación...", C_YELLOW)
                report_progress(oid, "power", "restarting", 80, "Reparando servicios (Self-Healing)...")
                try:
                    specs_json = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT JSON_OBJECT(\'cpu\', cpu_cores, \'ram\', ram_gb, \'storage\', storage_gb, \'db_en\', db_enabled, \'db_type\', db_type, \'web_en\', web_enabled, \'web_type\', web_type, \'ssh_user\', ssh_user, \'os\', os_image, \'db_name\', db_custom_name, \'web_name\', web_custom_name, \'subdomain\', subdomain) FROM order_specs WHERE order_id={oid}"', silent=True).strip()
                    if specs_json and "{" in specs_json:
                        sp = json.loads(specs_json)
                        deploy_script = os.path.join(BASE_DIR, "tofu-k8s/custom-stack/deploy_custom.sh")
                        cmd_repair = f"bash {deploy_script} {oid} {sp['cpu']} {sp['ram']} {sp['storage']} {sp['db_en']} \"{sp['db_type']}\" {sp['web_en']} \"{sp['web_type']}\" \"{sp['ssh_user']}\" \"{sp['os']}\" \"{sp['db_name']}\" \"{sp['web_name']}\" \"{sp['subdomain']}\""
                        run_command(cmd_repair, check_error=True)
                        found_pod = True
                except Exception as ex: log(f"❌ Falló Auto-Reparación: {ex}", C_RED)

            # DB Update (Just in case)
            run_command(f'docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e "UPDATE orders SET status=\'active\' WHERE id={oid}"', check_error=True)
            
            report_progress(oid, "power", "restarted", 100, "Reiniciado")
            log(f"🔄 CLIENTE {oid} REINICIADO (HARD)", C_CYAN)

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
                # FIX: Strict filtering to avoid "sidecar" containers or non-main containers
                # Only process containers explicitly named "sylo-cliente-<numeric_id>"
                if "sylo-cliente-" in line and "-sidecar" not in line and "preload" not in line:
                    parts = line.split('-')
                    # Expected format: sylo, cliente, <id> (3 parts)
                    if len(parts) == 3 and parts[2].isdigit():
                        oid = parts[2]
                    else:
                        continue
                    
                    # 🛡️ PROTECCIÓN: No enviar métricas si está apagado/deteniéndose en DB
                    try:
                        check_stat = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT status FROM orders WHERE id={oid}"', silent=True).strip().lower()
                        if check_stat in ['stopped', 'stopping', 'cancelled', 'terminated']:
                            continue
                    except: pass

                    stats = run_command(f"docker stats {line} --no-stream --format '{{{{.CPUPerc}}}},{{{{.MemPerc}}}}'", silent=True)
                    c,r = 0,0
                    if "," in stats:
                        try: c=float(stats.split(',')[0].replace('%','')); r=float(stats.split(',')[1].replace('%',''))
                        except: pass
                    
                    # FIX: Fetch installed tools info from DB
                    try:
                        db_cmd = f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT subdomain, os_image, web_enabled, web_type, db_enabled, db_type FROM order_specs WHERE order_id={oid}"'
                        raw_data = run_command(db_cmd, silent=True).strip()
                        
                        sub, os_img, web_en, web_type, db_en, db_type = "", "Linux", "0", "", "0", ""
                        
                        if raw_data:
                            # MySQL output is tab separated by default with -N
                            cols = raw_data.split('\t')
                            if len(cols) >= 6:
                                sub = cols[0] if cols[0] != "NULL" else ""
                                os_img = cols[1] if cols[1] != "NULL" else "Linux"
                                web_en = cols[2]
                                web_type = cols[3] if cols[3] != "NULL" else ""
                                db_en = cols[4]
                                db_type = cols[5] if cols[5] != "NULL" else ""

                        url = f"http://{sub}.sylobi.org" if sub and len(sub)>0 else "..."
                        
                        # Construct Installed Tools List
                        tools = []
                        if os_img: tools.append(os_img)
                        if str(web_en) == "1" and web_type: tools.append(web_type)
                        if str(db_en) == "1" and db_type: tools.append(db_type)
                        
                        requests.post(f"{API_URL}/reportar/metricas", json={
                            "id_cliente":int(oid), 
                            "metrics":{"cpu":c,"ram":r}, 
                            "ssh_cmd": "root@sylo", 
                            "web_url": url, 
                            "os_info": os_img, 
                            "installed_tools": tools
                        }, timeout=1)
                        report_backups_list(oid)
                    except Exception as e:
                        # Fallback if DB query fails
                        requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente":int(oid), "metrics":{"cpu":c,"ram":r}, "ssh_cmd": "root@sylo", "web_url": "...", "os_info": "Linux", "installed_tools": []}, timeout=1)
        except: pass
        time.sleep(2)

if __name__ == "__main__":
    signal.signal(signal.SIGTERM, signal_handler); signal.signal(signal.SIGINT, signal_handler)
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    log("=== OPERATOR V51 (POWER CONTROL) ===", C_GREEN)
    t1=threading.Thread(target=process_task_queue, daemon=True); t2=threading.Thread(target=process_metrics, daemon=True)
    t1.start(); t2.start()
    while True: time.sleep(1)