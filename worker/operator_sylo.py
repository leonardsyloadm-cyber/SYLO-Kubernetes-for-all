#!/usr/bin/env python3
import sys, os, subprocess, time, json, datetime, threading, signal, shutil, tarfile, glob, codecs

def install(package):
    try: subprocess.check_call([sys.executable, "-m", "pip", "install", package])
    except: pass
try: import psutil
except: install("psutil"); import psutil
try: import requests
except: install("requests"); import requests

sys.stdout.reconfigure(line_buffering=True)
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
SUBNET_PREFIX = "192.168.200"
API_URL = "http://192.168.1.135:8001/api/clientes"

status_lock = threading.Lock()
cmd_lock = threading.Lock()
shutdown_event = threading.Event()

def log(msg, tipo="INFO"):
    print(f"[{datetime.datetime.now().strftime('%H:%M:%S')}] [OPERATOR] {msg}", flush=True)

def signal_handler(signum, frame): shutdown_event.set(); sys.exit(0)
def run_command(cmd, timeout=300):
    with cmd_lock:
        try:
            if isinstance(cmd, str): res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            else: res = subprocess.run(cmd, check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            return res.stdout.strip()
        except: return None

def get_real_ip(profile): return run_command(f"minikube -p {profile} ip", timeout=5)

# --- API CALLS ---
def report_api_metrics(oid, metrics, ssh, web):
    try: requests.post(f"{API_URL}/reportar/metricas", json={"id_cliente": int(oid), "metrics": metrics, "ssh_cmd": ssh, "web_url": web}, timeout=2)
    except: pass

def report_api_progress(oid, tipo, status_text, percent, msg):
    try: requests.post(f"{API_URL}/reportar/progreso", json={"id_cliente": int(oid), "tipo": tipo, "status_text": status_text, "percent": int(percent), "msg": str(msg)}, timeout=2)
    except: pass

def report_api_backups_list(oid, backups):
    try: requests.post(f"{API_URL}/reportar/lista_backups", json={"id_cliente": int(oid), "backups": backups}, timeout=2)
    except: pass

def report_api_web_content(oid, html):
    try: requests.post(f"{API_URL}/reportar/contenido_web", json={"id_cliente": int(oid), "html_content": html}, timeout=2)
    except: pass

def get_client_specs(oid):
    query = f"SELECT p.name, os.db_enabled, os.web_enabled, os.db_type, os.web_type FROM orders o JOIN plans p ON o.plan_id = p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE o.id = {oid}"
    cmd = ["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-N", "-e", query]
    res = run_command(cmd)
    if not res: return {'plan': 'Bronce', 'has_db': False, 'has_web': False, 'db_type': 'mysql', 'web_type': 'apache'}
    parts = res.split('\t')
    plan = parts[0]
    has_db = (parts[1] == '1') if len(parts) > 1 else False
    has_web = (parts[2] == '1') if len(parts) > 2 else False
    db_type = parts[3].lower() if len(parts) > 3 and parts[3] != 'NULL' else 'mysql'
    web_type = parts[4].lower() if len(parts) > 4 and parts[4] != 'NULL' else 'apache'
    if plan == 'Oro': has_db = True; has_web = True
    elif plan == 'Plata': has_db = True
    return {'plan': plan, 'has_db': has_db, 'has_web': has_web, 'db_type': db_type, 'web_type': web_type}

def get_target_pod(profile, service_type="web"):
    if service_type == "db":
        check_master = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "mysql-master-0", "-o", "name"])
        if check_master: return "mysql-master-0"
        check_mysql = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=mysql", "-o", "name"])
        if check_mysql: return check_mysql.replace("pod/", "").strip().splitlines()[0]
    check_web = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=web-cliente", "-o", "name"])
    if check_web: return check_web.replace("pod/", "").strip().splitlines()[0]
    check_custom = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=custom-web", "-o", "name"])
    if check_custom: return check_custom.replace("pod/", "").strip().splitlines()[0]
    return None

def get_web_root_path(web_type, pod_name=""):
    if "nginx" in pod_name.lower() or "nginx" in web_type: return "/usr/share/nginx/html"
    return "/var/www/html"

def perform_backup(oid, profile, btype='full', bname='Backup'):
    log(f"📦 BACKUP: {bname}")
    report_api_progress(oid, "backup", "creating", 5, "Iniciando...")
    temp_dir = os.path.join(BUZON, f"temp_backup_{oid}")
    if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)
    try:
        specs = get_client_specs(oid)
        report_api_progress(oid, "backup", "creating", 10, "Conectando...")
        if specs['has_db']:
            report_api_progress(oid, "backup", "creating", 30, "DB Dump...")
            db_pod = get_target_pod(profile, "db")
            if db_pod:
                dump_cmd = "mysqldump --force -u root --all-databases > /tmp/db_dump.sql || mysqldump --force -u root -p$MYSQL_ROOT_PASSWORD --all-databases > /tmp/db_dump.sql"
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "/bin/sh", "-c", dump_cmd])
                run_command(["minikube", "-p", profile, "kubectl", "--", "cp", f"{db_pod}:/tmp/db_dump.sql", os.path.join(temp_dir, "db_dump.sql")])
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "rm", "-f", "/tmp/db_dump.sql"])
        if specs['has_web']:
            report_api_progress(oid, "backup", "creating", 60, "Web Pack...")
            web_pod = get_target_pod(profile, "web")
            if web_pod:
                web_root = get_web_root_path(specs['web_type'], web_pod)
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "tar", "-czhf", "/tmp/web_content.tar.gz", web_root, "||", "true"])
                run_command(["minikube", "-p", profile, "kubectl", "--", "cp", f"{web_pod}:/tmp/web_content.tar.gz", os.path.join(temp_dir, "web_content.tar.gz")])
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "rm", "-f", "/tmp/web_content.tar.gz"])
        
        report_api_progress(oid, "backup", "creating", 80, "Comprimiendo...")
        timestamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S')
        final_tar_name = f"backup_v{oid}_{btype}_{timestamp}.tar.gz"
        final_tar_path = os.path.join(BUZON, final_tar_name)
        with tarfile.open(final_tar_path, "w:gz") as tar: tar.add(temp_dir, arcname="backup_data")
        
        list_file = os.path.join(BUZON, f"backups_list_{oid}.json")
        backups = []
        if os.path.exists(list_file):
            try: backups = json.load(open(list_file))
            except: pass
        backups.insert(0, { "name": bname, "type": btype, "date": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), "file": final_tar_name, "specs": specs })
        
        with open(list_file, 'w') as f: json.dump(backups, f)
        try: os.chmod(list_file, 0o666)
        except: pass
        
        report_api_backups_list(oid, backups)
        log(f"✅ Backup OK: {final_tar_name}", "SUCCESS")
        report_api_progress(oid, "backup", "completed", 100, "Completado")
    except Exception as e:
        log(f"❌ Error Backup: {e}", "ERROR")
        report_api_progress(oid, "backup", "error", 0, f"Error: {str(e)[:30]}")
    finally:
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)

def perform_restore(oid, profile, filename):
    clean_filename = filename.strip().replace('"', '').replace("'", "")
    report_api_progress(oid, "backup", "restoring", 5, "Iniciando...")
    log(f"⚡ RESTORE: {clean_filename}")
    local_tar_path = os.path.join(BUZON, clean_filename)
    temp_dir = os.path.join(BUZON, f"temp_restore_{oid}")
    if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)
    try:
        if not os.path.exists(local_tar_path): return
        specs = get_client_specs(oid)
        report_api_progress(oid, "backup", "restoring", 20, "Abriendo...")
        with tarfile.open(local_tar_path, "r:gz") as tar: tar.extractall(path=temp_dir)
        data_dir = os.path.join(temp_dir, "backup_data")
        if specs['has_db']:
            sql_file = os.path.join(data_dir, "db_dump.sql")
            if os.path.exists(sql_file):
                report_api_progress(oid, "backup", "restoring", 50, "DB...")
                db_pod = get_target_pod(profile, "db")
                if db_pod:
                    run_command(["minikube", "-p", profile, "kubectl", "--", "cp", sql_file, f"{db_pod}:/tmp/restore.sql"])
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "/bin/sh", "-c", "mysql -u root < /tmp/restore.sql"])
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "rm", "/tmp/restore.sql"])
        if specs['has_web']:
            web_tar = os.path.join(data_dir, "web_content.tar.gz")
            if os.path.exists(web_tar):
                report_api_progress(oid, "backup", "restoring", 70, "Web...")
                web_pod = get_target_pod(profile, "web")
                if web_pod:
                    run_command(["minikube", "-p", profile, "kubectl", "--", "cp", web_tar, f"{web_pod}:/tmp/restore_web.tar.gz"])
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "tar", "-xzf", "/tmp/restore_web.tar.gz", "-C", "/"])
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "rm", "/tmp/restore_web.tar.gz"])
                    try:
                        with tarfile.open(web_tar, "r:gz") as wt:
                            web_tmp = os.path.join(data_dir, "web_tmp")
                            if os.path.exists(web_tmp): shutil.rmtree(web_tmp)
                            os.makedirs(web_tmp)
                            wt.extractall(path=web_tmp)
                            for r, d, f in os.walk(web_tmp):
                                if "index.html" in f:
                                    with codecs.open(os.path.join(r, "index.html"), 'r', 'utf-8', errors='ignore') as f_cont:
                                        html = f_cont.read()
                                        # IMPORTANTE: Al restaurar, informamos a la API del nuevo contenido para el editor
                                        report_api_web_content(oid, html)
                                        process_web(oid, profile, html) 
                                    break
                    except Exception as e: log(f"Error logic web restore: {e}")
        report_api_progress(oid, "backup", "completed", 100, "Finalizado")
        log("✅ Restore Completado.")
    except Exception as e:
        log(f"Error Restore: {e}", "ERROR")
        report_api_progress(oid, "backup", "error", 0, f"Error: {e}")
    finally:
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)

def process_web(oid, profile, json_html_content):
    # Ya no llamamos a report_api_web_content aquí para evitar bucles,
    # la API es la dueña del archivo ahora al recibir el comando.
    report_api_progress(oid, "web", "creating", 10, "Procesando...")
    try:
        content = json_html_content
        if not content:
             report_api_progress(oid, "web", "error", 0, "No content")
             return

        tmp_html = f"/tmp/index_sylo_{oid}.html"
        with codecs.open(tmp_html, 'w', 'utf-8') as f: f.write(content)
        
        # 1. ACTUALIZAR CONFIGMAP
        for cm in ["web-content-config", "custom-web-content"]:
            run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "cm", cm, "--ignore-not-found=true"])
            run_command(["minikube", "-p", profile, "kubectl", "--", "create", "cm", cm, f"--from-file=index.html={tmp_html}"])
        
        # 2. REINICIAR PODS (OBLIGATORIO para leer el nuevo ConfigMap)
        log("🔄 Reiniciando Pods Web...")
        for lbl in ["app=web-cliente", "app=custom-web"]:
            run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "pods", "-l", lbl])
            
        try: os.remove(tmp_html)
        except: pass
        
        report_api_progress(oid, "web", "completed", 100, "Actualizado")
        log("✅ Web Actualizada satisfactoriamente")
        
    except Exception as e:
        log(f"❌ Error Web Update: {e}", "ERROR")
        report_api_progress(oid, "web", "error", 0, f"Error: {e}")

def force_update_status(oid, profile):
    is_run = "true" in (run_command(["docker", "inspect", "-f", "{{.State.Running}}", profile], timeout=5) or "")
    cpu = 0; ram = 0; web = ""; ssh = "Detenido"
    if is_run:
        ip = get_real_ip(profile) or f"{SUBNET_PREFIX}.{oid}"
        svc = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", "-A", "-o", "json"], timeout=10)
        if svc:
            try:
                js = json.loads(svc)
                for i in js.get('items',[]):
                    for p in i.get('spec',{}).get('ports',[]):
                        if p.get('nodePort'):
                            if p['port'] in [80,8080]: web = f"http://{ip}:{p['nodePort']}"
                            if p['port'] == 22: ssh = f"ssh root@{ip} -p {p['nodePort']}"
            except: pass
        st = run_command(["docker", "stats", profile, "--no-stream", "--format", "{{.CPUPerc}},{{.MemPerc}}"], timeout=5)
        if st and len(st.split(','))>1:
            try: cpu = float(st.split(',')[0].replace('%','').strip()); ram = float(st.split(',')[1].replace('%','').strip())
            except: pass
    report_api_metrics(oid, {"cpu": cpu, "ram": ram}, ssh, web)

def delete_backup_file(oid, filename):
    clean_name = filename.strip().replace('"', '').replace("'", "")
    report_api_progress(oid, "backup", "deleting", 10, "Eliminando...")
    tfile = os.path.join(BUZON, clean_name)
    if os.path.exists(tfile): 
        try: os.remove(tfile); log(f"🗑️ Archivo borrado: {clean_name}")
        except Exception as e: log(f"Error borrando: {e}", "ERROR")
    real_backups = []
    found_files = glob.glob(os.path.join(BUZON, f"backup_v{oid}_*.tar.gz"))
    found_files.sort(key=os.path.getmtime, reverse=True)
    for fpath in found_files:
        fname = os.path.basename(fpath)
        b_type = "full"
        parts = fname.split('_')
        if len(parts) > 2: b_type = parts[2]
        b_date = datetime.datetime.fromtimestamp(os.path.getmtime(fpath)).strftime("%Y-%m-%d %H:%M:%S")
        real_backups.append({"name": f"Backup {b_date}", "type": b_type, "date": b_date, "file": fname, "specs": {}})
    lfile = os.path.join(BUZON, f"backups_list_{oid}.json")
    with open(lfile, 'w') as f: json.dump(real_backups, f)
    try: os.chmod(lfile, 0o666)
    except: pass
    report_api_backups_list(oid, real_backups)
    report_api_progress(oid, "backup", "completed", 100, "Eliminado")

def perform_termination(oid, profile):
    run_command(["minikube", "delete", "-p", profile])
    run_command(f"docker rm -f {profile}")
    run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"DELETE FROM order_specs WHERE order_id={oid}; DELETE FROM orders WHERE id={oid};"])
    for f in glob.glob(os.path.join(BUZON, f"*{oid}*")):
        try: os.remove(f) if os.path.isfile(f) else shutil.rmtree(f)
        except: pass

def execute_action_thread(data):
    if shutdown_event.is_set(): return
    try:
        oid = data.get('id_cliente') or data.get('id')
        if not oid: return
        act = str(data.get('action') or data.get('accion')).upper()
        prof = f"sylo-cliente-{oid}"
        if act not in ["REFRESH", "REFRESH_STATUS"]: log(f"ACCIÓN: {act} -> #{oid}")
        if act == "START":
            run_command(["minikube", "start", "-p", prof])
            run_command(f"docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e \"UPDATE orders SET status='active' WHERE id={oid};\"")
        elif act == "STOP":
            run_command(["minikube", "stop", "-p", prof])
            run_command(f"docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e \"UPDATE orders SET status='suspended' WHERE id={oid};\"")
        elif act == "RESTART":
            run_command(["minikube", "stop", "-p", prof]); run_command(["minikube", "start", "-p", prof])
        elif act == "BACKUP": perform_backup(oid, prof, data.get('backup_type', 'full'), data.get('backup_name', 'Backup'))
        elif act == "RESTORE" or act == "RESTORE_BACKUP": perform_restore(oid, prof, data.get('filename_to_restore'))
        elif act == "UPDATE_WEB": process_web(oid, prof, data.get('html_content', ''))
        elif act == "DELETE_BACKUP": delete_backup_file(oid, data.get('filename_to_delete'))
        elif act == "REFRESH" or act == "REFRESH_STATUS": force_update_status(oid, prof)
        elif "TERMINATE" in act: perform_termination(oid, prof)
    except Exception as e: log(f"Error thread: {e}", "ERROR")

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
    log("=== OPERATOR V74 (SYNC & PERSISTENCE) ===", "SUCCESS")
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