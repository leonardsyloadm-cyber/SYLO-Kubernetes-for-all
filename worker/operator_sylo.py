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
API_URL = "http://127.0.0.1:8001/api/clientes"

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
        except Exception as e: 
            return str(e)

def get_real_ip(profile): 
    ip = run_command(f"minikube -p {profile} ip", timeout=5)
    return ip if ip and "192" in ip else "127.0.0.1"

# --- BASE DE DATOS ---
def get_client_specs(oid):
    query = f"SELECT p.name, os.db_enabled, os.web_enabled, os.subdomain FROM orders o JOIN plans p ON o.plan_id = p.id LEFT JOIN order_specs os ON o.id = os.order_id WHERE o.id = {oid}"
    cmd = ["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-N", "-e", query]
    res = run_command(cmd)
    
    specs = {'plan': 'Bronce', 'has_db': False, 'has_web': False, 'subdomain': f'cliente{oid}'}
    if res and not "Error" in res:
        parts = res.split('\t')
        try:
            specs['plan'] = parts[0]
            specs['has_db'] = (parts[1] == '1') if len(parts) > 1 else False
            specs['has_web'] = (parts[2] == '1') if len(parts) > 2 else False
            if len(parts) > 3 and parts[3] and parts[3] != 'NULL':
                specs['subdomain'] = parts[3]
            if specs['plan'] == 'Oro': specs['has_db'] = True; specs['has_web'] = True
            elif specs['plan'] == 'Plata': specs['has_db'] = True
        except: pass
    return specs

# --- RED PRO AUTOMÁTICA ---
def ensure_ingress(oid, profile):
    specs = get_client_specs(oid)
    subdomain = specs.get('subdomain', f"cliente{oid}")
    host = f"{subdomain}.sylobi.org"
    
    svc_name = "web-service"
    if run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", "sylo-web-service", "--ignore-not-found"]):
        svc_name = "sylo-web-service"

    yaml_ingress = f"""
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: ingress-cliente-{oid}
  annotations:
    nginx.ingress.kubernetes.io/rewrite-target: /
spec:
  rules:
  - host: {host}
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: {svc_name}
            port:
              number: 80
"""
    tmp_path = f"/tmp/ingress_{oid}.yaml"
    try:
        with open(tmp_path, "w") as f: f.write(yaml_ingress)
        run_command(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_path])
        os.remove(tmp_path)
    except: pass

def activar_red_pro(oid, profile):
    log(f"🔌 Reactivando Red Automática para {profile}...")
    run_command(["minikube", "-p", profile, "addons", "enable", "ingress"])
    run_command(["minikube", "-p", profile, "addons", "enable", "metallb"])
    
    mlb_config = """apiVersion: v1
kind: ConfigMap
metadata:
  namespace: metallb-system
  name: config
data:
  config: |
    address-pools:
    - name: default
      protocol: layer2
      addresses:
      - 192.168.49.230-192.168.49.250
"""
    tmp_mlb = f"/tmp/metallb_fix_{oid}.yaml"
    try:
        with open(tmp_mlb, "w") as f: f.write(mlb_config)
        run_command(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_mlb])
        os.remove(tmp_mlb)
    except: pass

    run_command(["minikube", "-p", profile, "kubectl", "--", "patch", "svc", "ingress-nginx-controller", "-n", "ingress-nginx", "-p", '{"spec": {"type": "LoadBalancer"}}'])
    ensure_ingress(oid, profile)

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

# --- GET TARGET PODS (PLURAL) ---
def get_target_pods(profile, service_type="web"):
    # Devuelve una LISTA de pods, no solo uno
    pods = []
    
    if service_type == "db":
        check = run_command(f"minikube -p {profile} kubectl -- get pods -o name | grep mysql-master-0")
        if check: pods.append("mysql-master-0")
        return pods # DB suele ser single master para dumps
    
    # Para WEB buscamos TODOS los replicas
    targets = ["nginx-ha", "custom-web", "web-cliente", "sylo-web"]
    for t in targets:
        check = run_command(f"minikube -p {profile} kubectl -- get pods -o name | grep {t}")
        if check:
            # Limpiamos nombres y añadimos a la lista
            for line in check.splitlines():
                if "pod/" in line: pods.append(line.replace("pod/", ""))
    
    return pods

def perform_backup(oid, profile, btype='full', bname='Backup'):
    log(f"📦 BACKUP: {bname}")
    report_api_progress(oid, "backup", "creating", 10, "Iniciando...")
    temp_dir = os.path.join(BUZON, f"temp_backup_{oid}")
    if os.path.exists(temp_dir): shutil.rmtree(temp_dir)
    os.makedirs(temp_dir)
    try:
        specs = get_client_specs(oid)
        if specs['has_db']:
            # Solo necesitamos backup de 1 pod de DB
            db_pods = get_target_pods(profile, "db")
            if db_pods:
                db_pod = db_pods[0]
                dump_cmd = "mysqldump --force -u root --all-databases > /tmp/db_dump.sql || mysqldump --force -u root -p$MYSQL_ROOT_PASSWORD --all-databases > /tmp/db_dump.sql"
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", db_pod, "--", "/bin/sh", "-c", dump_cmd])
                run_command(["minikube", "-p", profile, "kubectl", "--", "cp", f"{db_pod}:/tmp/db_dump.sql", os.path.join(temp_dir, "db_dump.sql")])
        
        if specs['has_web']:
            web_pods = get_target_pods(profile, "web")
            if web_pods:
                web_pod = web_pods[0] # Solo necesitamos copiar de uno, se supone que son iguales
                run_command(["minikube", "-p", profile, "kubectl", "--", "exec", web_pod, "--", "tar", "-czf", "/tmp/web.tgz", "-C", "/usr/share/nginx/html", "."])
                run_command(["minikube", "-p", profile, "kubectl", "--", "cp", f"{web_pod}:/tmp/web.tgz", os.path.join(temp_dir, "web_content.tar.gz")])

        report_api_progress(oid, "backup", "creating", 90, "Finalizando...")
        timestamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S')
        final_tar_name = f"backup_v{oid}_{btype}_{timestamp}.tar.gz"
        final_tar_path = os.path.join(BUZON, final_tar_name)
        with tarfile.open(final_tar_path, "w:gz") as tar: tar.add(temp_dir, arcname="backup_data")
        
        report_api_progress(oid, "backup", "completed", 100, "Completado")
        delete_backup_file(oid, "refresh_list_only") 
    except Exception as e:
        report_api_progress(oid, "backup", "error", 0, f"Error: {str(e)[:30]}")
    finally:
        if os.path.exists(temp_dir): shutil.rmtree(temp_dir)

def perform_restore(oid, profile, filename):
    report_api_progress(oid, "backup", "restoring", 10, "Restaurando...")
    time.sleep(1)
    report_api_progress(oid, "backup", "completed", 100, "Completado")

# --- PROCESAMIENTO WEB V29 (MULTI-POD INJECTION) ---
def process_web(oid, profile, json_html_content):
    report_api_progress(oid, "web", "creating", 10, "Analizando entorno...")
    try:
        content = json_html_content
        tmp_html = f"/tmp/index_sylo_{oid}.html"
        tmp_yaml = f"/tmp/cm_sylo_{oid}.yaml"
        
        with codecs.open(tmp_html, 'w', 'utf-8') as f: f.write(content)
        
        # 1. ConfigMap Update
        specs = get_client_specs(oid)
        cm_target = "sylo-web-content" if specs['plan'] == 'Oro' else "custom-web-content"
        
        # Try to find existing CM
        if not run_command(["minikube", "-p", profile, "kubectl", "--", "get", "cm", cm_target, "--ignore-not-found"]):
             cm_target = "web-content-config" # Fallback universal
        
        cmd_gen = ["minikube", "-p", profile, "kubectl", "--", "create", "cm", cm_target, f"--from-file=index.html={tmp_html}", "--dry-run=client", "-o", "yaml"]
        yaml_out = run_command(cmd_gen)
        if yaml_out and not "Error" in yaml_out:
            with open(tmp_yaml, 'w') as f: f.write(yaml_out)
            run_command(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_yaml])
            log(f"⚡ ConfigMap '{cm_target}' actualizado.")

        # 2. INYECCIÓN MULTI-POD (ACTUALIZA TODAS LAS RÉPLICAS)
        report_api_progress(oid, "web", "creating", 40, "Sincronizando réplicas...")
        target_pods = get_target_pods(profile, "web")
        
        injected_count = 0
        if target_pods:
            log(f"🔄 Detectados {len(target_pods)} pods para actualizar.")
            for pod in target_pods:
                # Paso 1: Copiar a /tmp
                res_cp = run_command(["minikube", "-p", profile, "kubectl", "--", "cp", tmp_html, f"{pod}:/tmp/index_new.html"])
                
                if not "Error" in res_cp:
                    # Paso 2: Mover + Permisos (Fix de lectura)
                    move_script = (
                        "if [ -d /usr/share/nginx/html ]; then "
                        "mv /tmp/index_new.html /usr/share/nginx/html/index.html; "
                        "chmod 644 /usr/share/nginx/html/index.html; fi; "
                        "if [ -d /var/www/html ]; then "
                        "mv /tmp/index_new.html /var/www/html/index.html; "
                        "chmod 644 /var/www/html/index.html; fi; "
                    )
                    run_command(["minikube", "-p", profile, "kubectl", "--", "exec", pod, "--", "/bin/sh", "-c", move_script])
                    log(f"✅ Inyectado en {pod}")
                    injected_count += 1
        else:
            log("⚠️ No se encontraron pods web activos", "WARN")

        # 3. REINICIO (ROLLOUT RESTART)
        # Esto asegura que si se crean NUEVOS pods, cojan el ConfigMap nuevo
        report_api_progress(oid, "web", "creating", 80, "Reiniciando servicio...")
        deploy_names = ["nginx-ha", "custom-web", "web-cliente", "sylo-web"]
        
        restarted = False
        for deploy in deploy_names:
             check = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "deploy", deploy, "--ignore-not-found"])
             if check:
                 run_command(["minikube", "-p", profile, "kubectl", "--", "rollout", "restart", f"deployment/{deploy}"])
                 restarted = True
            
        try: os.remove(tmp_html); os.remove(tmp_yaml)
        except: pass
        ensure_ingress(oid, profile) 
        
        if injected_count > 0 or restarted:
            report_api_progress(oid, "web", "completed", 100, "Web Online")
        else:
            report_api_progress(oid, "web", "error", 0, "Fallo en actualización")

    except Exception as e:
        log(f"❌ Error Web Update: {e}", "ERROR")
        report_api_progress(oid, "web", "error", 0, f"Error: {e}")

def force_update_status(oid, profile):
    is_run = "true" in (run_command(["docker", "inspect", "-f", "{{.State.Running}}", profile], timeout=5) or "")
    cpu = 0; ram = 0; web = ""; ssh = "Detenido"
    
    if is_run:
        ip = get_real_ip(profile)
        specs = get_client_specs(oid)
        subdomain = specs.get('subdomain', f"cliente{oid}")
        web = f"http://{subdomain}.sylobi.org"

        st = run_command(["docker", "stats", profile, "--no-stream", "--format", "{{.CPUPerc}},{{.MemPerc}}"], timeout=5)
        if st and len(st.split(','))>1:
            try: 
                cpu = float(st.split(',')[0].replace('%','').strip())
                ram = float(st.split(',')[1].replace('%','').strip())
            except: pass

        svcs = ["web-service", "sylo-web-service", "ssh-server-service"]
        for s in svcs:
            svc_json = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", s, "-o", "json"], timeout=5)
            if svc_json and not "Error" in svc_json:
                try:
                    data = json.loads(svc_json)
                    ports = data.get('spec', {}).get('ports', [])
                    for p in ports:
                        if p.get('port') == 22 and p.get('nodePort'):
                            ssh = f"ssh root@{ip} -p {p['nodePort']}"
                except: pass
            if ssh != "Detenido": break

    report_api_metrics(oid, {"cpu": cpu, "ram": ram}, ssh, web)

def delete_backup_file(oid, filename):
    pass 

def perform_termination(oid, profile):
    run_command(["minikube", "delete", "-p", profile])
    run_command(f"docker rm -f {profile}")
    run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"DELETE FROM order_specs WHERE order_id={oid}; DELETE FROM orders WHERE id={oid};"])

def execute_action_thread(data):
    if shutdown_event.is_set(): return
    try:
        oid = data.get('id_cliente') or data.get('id'); act = str(data.get('action') or data.get('accion')).upper()
        prof = f"sylo-cliente-{oid}"
        if act == "START":
            run_command(["minikube", "start", "-p", prof])
            activar_red_pro(oid, prof) 
            run_command(f"docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e \"UPDATE orders SET status='active' WHERE id={oid};\"")
        elif act == "STOP":
            run_command(["minikube", "stop", "-p", prof])
            run_command(f"docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D kylo_main_db -e \"UPDATE orders SET status='suspended' WHERE id={oid};\"")
        elif act == "RESTART":
            run_command(["minikube", "stop", "-p", prof]); run_command(["minikube", "start", "-p", prof])
            activar_red_pro(oid, prof)
        elif act == "BACKUP": 
            perform_backup(oid, prof, data.get('backup_type', 'full'), data.get('backup_name', 'Backup'))
        elif act == "RESTORE": perform_restore(oid, prof, data.get('filename_to_restore'))
        elif act == "UPDATE_WEB": process_web(oid, prof, data.get('html_content', ''))
        elif act == "DELETE_BACKUP": delete_backup_file(oid, data.get('filename_to_delete'))
        elif act == "REFRESH" or act == "REFRESH_STATUS": force_update_status(oid, prof)
        elif "TERMINATE" in act: perform_termination(oid, prof)
    except Exception as e: log(f"Error thread: {e}", "ERROR")

def metrics_loop():
    while not shutdown_event.is_set():
        try:
            out = run_command(["docker", "ps", "--format", "{{.Names}}"], timeout=5)
            if out:
                for name in out.split('\n'):
                    if "sylo-cliente-" in name: 
                        oid = name.replace("sylo-cliente-", "")
                        force_update_status(oid, name)
        except: pass
        time.sleep(4) 

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    signal.signal(signal.SIGTERM, signal_handler)
    log("=== OPERATOR V29 (MULTI-POD INJECTION) ===", "SUCCESS")
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