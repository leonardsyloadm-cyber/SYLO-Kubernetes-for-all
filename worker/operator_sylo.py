#!/usr/bin/env python3
import os
import time
import json
import glob
import subprocess
import sys
import datetime
import codecs
import threading
import signal
import shutil
import tarfile

# =======================================================
# SYLO OPERATOR V58 - CLEAN & POLISHED
# =======================================================
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
SUBNET_PREFIX = "192.168.200"

status_lock = threading.Lock()
cmd_lock = threading.Lock()
shutdown_event = threading.Event()

# --- CONFIGURACIÓN ---
SHOW_DEBUG_LOGS = False  # <--- CAMBIA A True SOLO SI ALGO FALLA

def log(msg):
    t_name = threading.current_thread().name
    prefix = "" if t_name == "MainThread" else f"[{t_name}] "
    # El formato [SYLO] es clave para que Oktopus lo capture
    print(f"[SYLO] {msg}", flush=True)

def log_debug(msg):
    if SHOW_DEBUG_LOGS:
        print(f"   🔍 DEBUG: {msg}", flush=True)

def signal_handler(signum, frame):
    shutdown_event.set()
    log(f"💀 SEÑAL {signum} RECIBIDA. Apagando motores...")
    sys.exit(0)

def run_command(cmd, timeout=300, debug=False):
    with cmd_lock:
        try:
            if shutdown_event.is_set(): return None
            if debug: log_debug(f"CMD: {cmd}")
            
            if isinstance(cmd, str):
                res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            else:
                res = subprocess.run(cmd, check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            
            if res.returncode != 0 and debug:
                log_debug(f"⚠️ CMD ERROR: {res.stderr.strip()}")
                
            return res.stdout.strip()
        except Exception as e: 
            if debug: log_debug(f"💥 EXCEPTION: {e}")
            return None

def update_msg(file, prog, msg, status_type="creating"):
    try:
        with open(file, 'w') as f: 
            json.dump({"status": status_type, "progress": prog, "msg": msg}, f)
    except: pass

def update_web_progress(oid, percent, msg):
    try:
        with open(os.path.join(BUZON, f"web_status_{oid}.json"), 'w') as f: json.dump({"progress": percent, "msg": msg}, f)
    except: pass

# --- DETECTIVE ---
def get_client_specs(oid):
    query = f"""
    SELECT p.name, os.db_enabled, os.web_enabled, os.db_type, os.web_type 
    FROM orders o 
    JOIN plans p ON o.plan_id = p.id 
    LEFT JOIN order_specs os ON o.id = os.order_id 
    WHERE o.id = {oid}
    """
    cmd = ["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-N", "-e", query]
    res = run_command(cmd)
    
    if not res: return {'plan': 'Bronce', 'has_db': False, 'has_web': False, 'db_type': 'mysql', 'web_type': 'apache'}
    
    parts = res.split('\t')
    plan = parts[0]
    
    has_db = False; has_web = False; db_type = 'mysql'; web_type = 'apache'
    
    if plan == 'Oro':
        has_db = True; has_web = True
    elif plan == 'Plata':
        has_db = True; has_web = False
    elif plan == 'Personalizado':
        has_db = (parts[1] == '1')
        has_web = (parts[2] == '1')
        if len(parts) > 3: db_type = parts[3].lower() if parts[3] != 'NULL' else 'mysql'
        if len(parts) > 4: web_type = parts[4].lower() if parts[4] != 'NULL' else 'apache'

    return {'plan': plan, 'has_db': has_db, 'has_web': has_web, 'db_type': db_type, 'web_type': web_type}

# --- BUSCADOR DE PODS ---
def get_target_pod(profile, service_type="web"):
    if service_type == "db":
        check_master = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "mysql-master-0", "-o", "name"])
        if check_master: return "mysql-master-0"
        
        check_mysql = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=mysql", "-o", "name"])
        if check_mysql and "pod/" in check_mysql: return check_mysql.replace("pod/", "").strip().splitlines()[0]

    check_web = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=web-cliente", "-o", "name"])
    if check_web and "pod/" in check_web: return check_web.replace("pod/", "").strip().splitlines()[0]
    
    check_custom = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=custom-web", "-o", "name"])
    if check_custom and "pod/" in check_custom: return check_custom.replace("pod/", "").strip().splitlines()[0]
    
    any_pod = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "--field-selector=status.phase=Running", "-o", "name"])
    if any_pod: return any_pod.replace("pod/", "").strip().splitlines()[0]
    
    return None

def get_web_root_path(web_type, pod_name=""):
    if "nginx" in pod_name.lower(): return "/usr/share/nginx/html"
    if "apache" in pod_name.lower() or "httpd" in pod_name.lower(): return "/var/www/html"
    if 'nginx' in web_type: return "/usr/share/nginx/html"
    return "/var/www/html"

# ==============================================================================
# LÓGICA BACKUP HÍBRIDO
# ==============================================================================
def perform_backup(oid, profile, btype='full', bname='Backup'):
    if shutdown_event.is_set(): return
    
    log(f"📦 INICIANDO BACKUP: {bname} ({btype})")
    status_file = os.path.join(BUZON, f"backup_status_{oid}.json")
    list_file = os.path.join(BUZON, f"backups_list_{oid}.json")
    
    temp_dir = os.path.join(BUZON, f"temp_backup_{oid}")
    if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)

    try:
        specs = get_client_specs(oid)
        update_msg(status_file, 10, "Conectando...", "creating")
        
        # --- 1. BASE DE DATOS ---
        if specs['has_db']:
            update_msg(status_file, 30, "Descargando DB...", "creating")
            db_pod = get_target_pod(profile, "db")
            if db_pod:
                # log(f"   🔹 Exportando DB...") # Omitido para reducir ruido, descomentar si se quiere
                dump_cmd = "mysqldump -u root --all-databases > /tmp/db_dump.sql || mysqldump -u root -p$MYSQL_ROOT_PASSWORD --all-databases > /tmp/db_dump.sql"
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "/bin/sh", "-c", dump_cmd], debug=True)
                run_command(["minikube", "-p", profile, "kubectl", "--", "cp", f"{db_pod}:/tmp/db_dump.sql", os.path.join(temp_dir, "db_dump.sql")], debug=True)
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "rm", "-f", "/tmp/db_dump.sql"])
        
        # --- 2. ARCHIVOS WEB ---
        if specs['has_web']:
            update_msg(status_file, 60, "Descargando Web...", "creating")
            web_pod = get_target_pod(profile, "web")
            if web_pod:
                web_root = get_web_root_path(specs['web_type'], web_pod)
                # log(f"   🔹 Empaquetando Web...") # Omitido para reducir ruido
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "tar", "-czhf", "/tmp/web_content.tar.gz", web_root], debug=True)
                run_command(["minikube", "-p", profile, "kubectl", "--", "cp", f"{web_pod}:/tmp/web_content.tar.gz", os.path.join(temp_dir, "web_content.tar.gz")], debug=True)
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "rm", "-f", "/tmp/web_content.tar.gz"])

        # --- 3. EMPAQUETADO FINAL ---
        update_msg(status_file, 80, "Generando archivo...", "creating")
        timestamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S')
        final_tar_name = f"backup_v{oid}_{btype}_{timestamp}.tar.gz"
        final_tar_path = os.path.join(BUZON, final_tar_name)
        
        with tarfile.open(final_tar_path, "w:gz") as tar:
            tar.add(temp_dir, arcname="backup_data")
        
        backups = []
        if os.path.exists(list_file):
            try: backups = json.load(open(list_file))
            except: pass
        
        backups.insert(0, { 
            "name": bname, "type": btype, "date": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), 
            "file": final_tar_name, "specs": specs 
        })
        
        with open(list_file, 'w') as f: json.dump(backups, f)
        
        size_mb = round(os.path.getsize(final_tar_path) / 1024 / 1024, 2)
        log(f"✅ Backup Completado: {final_tar_name} ({size_mb} MB)")

    except Exception as e:
        log(f"❌ Error Backup: {e}")
    finally:
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
        time.sleep(1)
        if os.path.exists(status_file): os.remove(status_file)

# ==============================================================================
# LÓGICA RESTAURAR HÍBRIDO
# ==============================================================================
def perform_restore(oid, profile, filename):
    if shutdown_event.is_set(): return
    
    clean_filename = filename.strip().replace('"', '').replace("'", "")
    status_file = os.path.join(BUZON, f"backup_status_{oid}.json")
    update_msg(status_file, 5, "Iniciando...", "restoring")
    
    log(f"⚡ RESTAURANDO: {clean_filename}")

    local_tar_path = os.path.join(BUZON, clean_filename)
    temp_dir = os.path.join(BUZON, f"temp_restore_{oid}")
    if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)

    try:
        if not os.path.exists(local_tar_path):
            log(f"❌ Error: Archivo de backup no encontrado.")
            return

        specs = get_client_specs(oid)
        
        update_msg(status_file, 20, "Abriendo backup...", "restoring")
        with tarfile.open(local_tar_path, "r:gz") as tar:
            tar.extractall(path=temp_dir)
        
        data_dir = os.path.join(temp_dir, "backup_data")
        
        # 1. DB
        if specs['has_db']:
            sql_file = os.path.join(data_dir, "db_dump.sql")
            if os.path.exists(sql_file):
                update_msg(status_file, 50, "Restaurando DB...", "restoring")
                db_pod = get_target_pod(profile, "db")
                if db_pod:
                    log("   🔹 Restaurando Base de Datos...")
                    run_command(["minikube", "-p", profile, "kubectl", "--", "cp", sql_file, f"{db_pod}:/tmp/restore.sql"], debug=True)
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "/bin/sh", "-c", "mysql -u root < /tmp/restore.sql"], debug=True)
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "rm", "/tmp/restore.sql"])
                else: log("   ⚠️ No se encontró Pod DB para restaurar.")
        
        # 2. WEB
        if specs['has_web']:
            web_tar = os.path.join(data_dir, "web_content.tar.gz")
            if os.path.exists(web_tar):
                update_msg(status_file, 70, "Restaurando Web...", "restoring")
                web_pod = get_target_pod(profile, "web")
                if web_pod:
                    log("   🔹 Restaurando Servidor Web...")
                    # Inyección física
                    run_command(["minikube", "-p", profile, "kubectl", "--", "cp", web_tar, f"{web_pod}:/tmp/restore_web.tar.gz"], debug=True)
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "tar", "-xzf", "/tmp/restore_web.tar.gz", "-C", "/"], debug=True)
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "rm", "/tmp/restore_web.tar.gz"])
                    
                    # INYECCIÓN LÓGICA (Editor + ConfigMap)
                    try:
                        with tarfile.open(web_tar, "r:gz") as wt:
                            web_temp_extract = os.path.join(data_dir, "web_extracted")
                            if os.path.exists(web_temp_extract): shutil.rmtree(web_temp_extract)
                            os.makedirs(web_temp_extract)
                            wt.extractall(path=web_temp_extract)
                            
                            found_content = None
                            for root, dirs, files in os.walk(web_temp_extract):
                                if "index.html" in files:
                                    try:
                                        with codecs.open(os.path.join(root, "index.html"), 'r', 'utf-8', errors='ignore') as f:
                                            found_content = f.read()
                                        if found_content: break
                                    except: pass
                            
                            if found_content:
                                # Actualizar Editor
                                source_file = os.path.join(BUZON, f"web_source_{oid}.html")
                                with codecs.open(source_file, 'w', 'utf-8') as f_src: f_src.write(found_content)
                                
                                # Actualizar K8s
                                log("   🔹 Aplicando cambios y verificando integridad...")
                                process_web(oid, profile, found_content)
                            
                            if os.path.exists(web_temp_extract): shutil.rmtree(web_temp_extract)

                    except Exception as e:
                        log(f"   ❌ Error parcial en web: {e}")
                else: log("   ⚠️ No se encontró Pod Web.")

        update_msg(status_file, 100, "Finalizado", "restoring")
        log("✅ RESTAURACIÓN COMPLETADA CON ÉXITO")

    except Exception as e:
        log(f"❌ Error Crítico: {e}")
    finally:
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
        time.sleep(2)
        if os.path.exists(status_file): os.remove(status_file)

# ==============================================================================
# PROCESO WEB (CON LIMPIEZA FINAL DE ESTADO)
# ==============================================================================
def process_web(oid, profile, json_html_content):
    # log(f"🌐 Actualizando Web...") # Omitido para no saturar si viene de restore
    update_web_progress(oid, 10, "Procesando...")
    try:
        tmp_html = f"/tmp/index_sylo_{oid}.html"
        content = json_html_content
        
        if not content and os.path.exists(os.path.join(BUZON, f"web_source_{oid}.html")):
            with codecs.open(os.path.join(BUZON, f"web_source_{oid}.html"), 'r', 'utf-8', errors='ignore') as f: content = f.read()
            
        if not content: return

        with codecs.open(tmp_html, 'w', 'utf-8') as f: f.write(content)
        
        # 1. CM
        for cm in ["web-content-config", "custom-web-content"]:
            run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "cm", cm, "--ignore-not-found=true"], debug=True)
            run_command(["minikube", "-p", profile, "kubectl", "--", "create", "cm", cm, f"--from-file=index.html={tmp_html}"], debug=True)
        
        # 2. Pods
        for lbl in ["app=web-cliente", "app=custom-web"]:
            run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "pods", "-l", lbl], debug=True)
            
        try: os.remove(tmp_html)
        except: pass
        
        # [FIX] FORZAR 100% Y BORRAR ARCHIVO PARA DESBLOQUEAR UI
        update_web_progress(oid, 100, "Completado")
        time.sleep(1.5) # Pequeña espera para que la UI lea el 100%
        if os.path.exists(os.path.join(BUZON, f"web_status_{oid}.json")): 
            os.remove(os.path.join(BUZON, f"web_status_{oid}.json"))
            
    except Exception as e:
        log(f"❌ Error Web Update: {e}")

def delete_backup_file(oid, filename):
    lfile = os.path.join(BUZON, f"backups_list_{oid}.json")
    tfile = os.path.join(BUZON, filename)
    if os.path.exists(tfile): os.remove(tfile)
    if os.path.exists(lfile):
        try:
            bk = json.load(open(lfile))
            bk = [b for b in bk if b['file'] != filename]
            with open(lfile, 'w') as f: json.dump(bk, f)
        except: pass

def perform_termination(oid, profile):
    log(f"💥 TERMINANDO #{oid}...")
    run_command(["minikube", "delete", "-p", profile])
    run_command(f"docker rm -f {profile}")
    run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"DELETE FROM order_specs WHERE order_id={oid}; DELETE FROM orders WHERE id={oid};"])
    for f in glob.glob(os.path.join(BUZON, f"*{oid}*")):
        try: os.remove(f) if os.path.isfile(f) else shutil.rmtree(f)
        except: pass
    log(f"✅ Cliente #{oid} eliminado.")

def force_update_status(oid, profile):
    if shutdown_event.is_set(): return
    is_run = "true" in (run_command(["docker", "inspect", "-f", "{{.State.Running}}", profile], timeout=5) or "")
    data = {"metrics": {"cpu":0,"ram":0}, "ssh_cmd": "Detenido", "web_url": ""}
    if is_run:
        ip = get_real_ip(profile) or f"{SUBNET_PREFIX}.{oid}"
        svc = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", "-A", "-o", "json"], timeout=10)
        if svc:
            try:
                js = json.loads(svc)
                for i in js.get('items',[]):
                    for p in i.get('spec',{}).get('ports',[]):
                        if p.get('nodePort'):
                            if p['port'] in [80,8080]: data['web_url'] = f"http://{ip}:{p['nodePort']}"
                            if p['port'] == 22: data['ssh_cmd'] = f"ssh root@{ip} -p {p['nodePort']}"
            except: pass
        st = run_command(["docker", "stats", profile, "--no-stream", "--format", "{{.CPUPerc}},{{.MemPerc}}"], timeout=5)
        if st and len(st.split(','))>1:
            data['metrics']['cpu'] = float(st.split(',')[0].replace('%','').strip() or 0)
            data['metrics']['ram'] = float(st.split(',')[1].replace('%','').strip() or 0)
            
    with status_lock:
        with open(os.path.join(BUZON, f"status_{oid}.json"), 'w') as f: json.dump(data, f)

def execute_action_thread(data):
    if shutdown_event.is_set(): return
    try:
        oid = data['id']; act = str(data['action']).upper()
        prof = f"sylo-cliente-{oid}"
        
        # LOGS DE ACCIÓN (SOLO LO IMPORTANTE)
        if act not in ["REFRESH", "REFRESH_STATUS"]:
            log(f"⚡ ACCIÓN: {act} -> #{oid}")

        if act == "START":
            run_command(["minikube", "start", "-p", prof])
            run_command(f"docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e \"UPDATE orders SET status='active' WHERE id={oid};\"")
            log(f"✅ Cliente #{oid} Iniciado")
        elif act == "STOP":
            run_command(["minikube", "stop", "-p", prof])
            run_command(f"docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e \"UPDATE orders SET status='suspended' WHERE id={oid};\"")
            log(f"✅ Cliente #{oid} Detenido")
        elif act == "RESTART":
            run_command(["minikube", "stop", "-p", prof]); run_command(["minikube", "start", "-p", prof])
            log(f"✅ Cliente #{oid} Reiniciado")
        elif act == "BACKUP": 
            perform_backup(oid, prof, data.get('backup_type', 'full'), data.get('backup_name', 'Backup'))
        elif act == "RESTORE" or act == "RESTORE_BACKUP":
            perform_restore(oid, prof, data.get('filename_to_restore'))
        elif act == "UPDATE_WEB": 
            process_web(oid, prof, data.get('html_content', ''))
            log("✅ Web Actualizada")
        elif act == "DELETE_BACKUP":
            delete_backup_file(oid, data.get('filename_to_delete'))
            log(f"🗑️ Backup eliminado")
        elif act == "REFRESH" or act == "REFRESH_STATUS":
            force_update_status(oid, prof)
        elif "TERMINATE" in act:
            perform_termination(oid, prof)
    except Exception as e: log(f"❌ Error thread: {e}")

def metrics_loop():
    while not shutdown_event.is_set():
        try:
            out = run_command(["docker", "ps", "-a", "--format", "{{.Names}}"], timeout=5)
            if out:
                for name in out.split('\n'):
                    if "sylo-cliente-" in name: force_update_status(name.replace("sylo-cliente-", ""), name)
        except: pass
        time.sleep(3)

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)
    log("=== OPERATOR V58 (POLISHED) ===")
    threading.Thread(target=metrics_loop, name="Metrics", daemon=True).start()
    while not shutdown_event.is_set():
        for f in glob.glob(os.path.join(BUZON, "accion_*.json")):
            try:
                data = json.load(open(f)); os.remove(f)
                threading.Thread(target=execute_action_thread, args=(data,), daemon=True).start()
            except: 
                try: os.remove(f)
                except: pass
        time.sleep(0.5)

if __name__ == "__main__": main()