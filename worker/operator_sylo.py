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

# =======================================================
# SYLO OPERATOR V33 - LASER GUIDED WEB UPDATE
# =======================================================
# Detección de rutas relativas (Portable)
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
SUBNET_PREFIX = "192.168.200"

# LOCKS
status_lock = threading.Lock()
cmd_lock = threading.Lock()
shutdown_event = threading.Event()

def log(msg):
    t_name = threading.current_thread().name
    prefix = "" if t_name == "MainThread" else f"[{t_name}] "
    print(f"[SYLO] {prefix}{msg}")
    sys.stdout.flush()

def signal_handler(signum, frame):
    shutdown_event.set()
    log(f"💀 SEÑAL {signum} RECIBIDA. Apagando motores...")
    sys.exit(0)

def run_command(cmd, timeout=60):
    with cmd_lock:
        try:
            if shutdown_event.is_set(): return None
            # Soporte dual: string (shell=True) o lista (shell=False)
            if isinstance(cmd, str):
                res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            else:
                res = subprocess.run(cmd, check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            return res.stdout.strip()
        except Exception as e: 
            return None

def get_real_ip(profile):
    ip = run_command(["minikube", "-p", profile, "ip"], timeout=5)
    if ip and "192" in ip: return ip
    ip = run_command(["docker", "inspect", "-f", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", profile], timeout=5)
    if ip and "192" in ip: return ip
    return None

def update_web_progress(oid, percent, msg):
    if shutdown_event.is_set(): return
    st_file = os.path.join(BUZON, f"web_status_{oid}.json")
    try:
        with open(st_file, 'w') as f: json.dump({"progress": percent, "msg": msg}, f)
        os.chmod(st_file, 0o666)
    except: pass

def force_update_status(oid, profile):
    if shutdown_event.is_set(): return
    
    is_running = False
    docker_state = run_command(["docker", "inspect", "-f", "{{.State.Running}}", profile], timeout=5)
    
    if docker_state == 'true': is_running = True
    elif docker_state is None or docker_state == '':
        try: os.remove(os.path.join(BUZON, f"status_{oid}.json"))
        except: pass
        return 

    data = {"metrics": {"cpu": 0.0, "ram": 0.0}} 
    
    if is_running:
        real_ip = get_real_ip(profile)
        if not real_ip: real_ip = f"{SUBNET_PREFIX}.{oid}"
        
        svc_out = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", "-A", "-o", "json"], timeout=10)
        
        if svc_out:
            try:
                js = json.loads(svc_out)
                for item in js.get('items', []):
                    for p in item.get('spec', {}).get('ports', []):
                        p_port = p.get('port', 0)
                        p_node = p.get('nodePort', 0)
                        if (p_port in [80, 8080] or p.get('name') == 'http') and p_node:
                            data['web_url'] = f"http://{real_ip}:{p_node}"
                        if p_port == 22 and p_node:
                            data['ssh_cmd'] = f"ssh cliente@{real_ip} -p {p_node}"
            except: pass

        stats = run_command(["docker", "stats", profile, "--no-stream", "--format", "{{.CPUPerc}},{{.MemPerc}}"], timeout=5)
        if stats:
            try:
                parts = stats.split(',')
                if len(parts) >= 2:
                    data['metrics']['cpu'] = float(parts[0].replace('%','').strip()) if parts[0].strip()!='--' else 0
                    data['metrics']['ram'] = float(parts[1].replace('%','').strip()) if parts[1].strip()!='--' else 0
            except: pass
    else:
        data['ssh_cmd'] = "Servidor Detenido"
        data['web_url'] = ""

    json_path = os.path.join(BUZON, f"status_{oid}.json")
    with status_lock:
        try:
            if os.path.exists(json_path):
                try:
                    old = json.load(open(json_path))
                    if 'ssh_pass' in old: data['ssh_pass'] = old['ssh_pass']
                except: pass
            
            with open(json_path, 'w') as f: json.dump(data, f, indent=4)
            os.chmod(json_path, 0o666)
        except: pass

def perform_termination(oid, profile):
    log(f"💥 EJECUTANDO TERMINACIÓN DE CLIENTE #{oid} ({profile})...")
    run_command(["minikube", "delete", "-p", profile], timeout=90)
    run_command(f"docker rm -f {profile}", timeout=30)
    
    log(f"   -> Limpiando base de datos...")
    run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"DELETE FROM order_specs WHERE order_id={oid};"])
    run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"DELETE FROM orders WHERE id={oid};"])
    
    log(f"   -> Borrando archivos residuales...")
    files = glob.glob(os.path.join(BUZON, f"*{oid}*"))
    count = 0
    for f in files:
        try:
            if os.path.isdir(f): shutil.rmtree(f)
            else: os.remove(f)
            count += 1
        except: pass
    log(f"✅ CLIENTE #{oid} ELIMINADO.")

# ==============================================================================
# FUNCIÓN WEB FIX v33 - BÚSQUEDA POR ETIQUETAS (LASER GUIDED)
# ==============================================================================
def process_web(oid, profile, json_html_content):
    if shutdown_event.is_set(): return
    log(f"🌐 Iniciando actualización WEB en {profile}...")
    
    try:
        update_web_progress(oid, 10, "Leyendo código...") 
        source_file = os.path.join(BUZON, f"web_source_{oid}.html")
        final_html = json_html_content 
        
        # Prioridad al archivo físico si existe (viene del dashboard)
        if os.path.exists(source_file):
            try:
                with codecs.open(source_file, 'r', 'utf-8', errors='ignore') as f: final_html = f.read()
            except: pass
        
        if not final_html or len(final_html) < 2:
            final_html = "<h1>Sitio Web en Mantenimiento</h1><p>Sylo Cloud</p>"

        update_web_progress(oid, 20, "Buscando objetivo...")
        
        # ESTRATEGIA 1: Buscar pods del Plan Oro (app=web-cliente)
        target_cm = None
        target_label = None
        
        # Intentamos encontrar pods del plan ORO
        check_oro = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=web-cliente", "-o", "name"], timeout=10)
        
        if check_oro and "pod/" in check_oro:
            log(f"   -> Detectado Plan ORO (app=web-cliente)")
            target_cm = "web-content-config"
            target_label = "app=web-cliente"
        else:
            # ESTRATEGIA 2: Buscar pods del Plan Custom (app=custom-web)
            check_custom = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-l", "app=custom-web", "-o", "name"], timeout=10)
            if check_custom and "pod/" in check_custom:
                log(f"   -> Detectado Plan CUSTOM (app=custom-web)")
                target_cm = "custom-web-content"
                target_label = "app=custom-web"
        
        if not target_cm:
            log(f"❌ ERROR: No se encontraron pods web en {profile}.")
            update_web_progress(oid, 100, "Error: No Web Pod")
            return

        # ESTRATEGIA DE ACTUALIZACIÓN
        update_web_progress(oid, 40, "Inyectando código...")
        
        # 1. Crear archivo temporal dentro del contexto del Operator
        tmp_html = f"/tmp/index_sylo_{oid}.html"
        with codecs.open(tmp_html, 'w', 'utf-8') as f: f.write(final_html)
        
        # 2. Reemplazar ConfigMap (Fuerza bruta: borrar y crear)
        run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "cm", target_cm], timeout=10)
        run_command(["minikube", "-p", profile, "kubectl", "--", "create", "cm", target_cm, f"--from-file=index.html={tmp_html}"], timeout=10)
        
        # Limpieza local
        try: os.remove(tmp_html)
        except: pass
        
        update_web_progress(oid, 70, "Reiniciando servidor...")
        
        # 3. Matar pods para forzar recarga (El Deployment creará nuevos con el nuevo CM)
        run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "pods", "-l", target_label], timeout=20)
        
        log(f"✅ Web actualizada correctamente en {profile}")
        
        # Finalización
        time.sleep(3) # Esperar a que K8s reaccione
        update_web_progress(oid, 100, "Sitio Publicado")
        time.sleep(2)
        try: os.remove(os.path.join(BUZON, f"web_status_{oid}.json"))
        except: pass

    except Exception as e:
        log(f"❌ Web Update Error: {e}")
        update_web_progress(oid, 100, "Error Interno")
        try: os.remove(os.path.join(BUZON, f"web_status_{oid}.json"))
        except: pass

def perform_backup(oid, profile, btype='full', bname='Backup'):
    if shutdown_event.is_set(): return
    log(f"Snapshot '{bname}' ({btype})...")
    status_file = os.path.join(BUZON, f"backup_status_{oid}.json")
    list_file = os.path.join(BUZON, f"backups_list_{oid}.json")
    try:
        with open(status_file, 'w') as f: json.dump({"status": "creating", "progress": 10, "msg": "Iniciando..."}, f)
        os.chmod(status_file, 0o666)
    except: pass
    time.sleep(1)
    
    timestamp_now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    filename = f"backup_v{oid}_{btype}_{datetime.datetime.now().strftime('%Y%m%d_%H%M%S')}.tar.gz"
    
    backups = []
    if os.path.exists(list_file):
        try: backups = json.load(open(list_file))
        except: pass
    backups.insert(0, { "name": bname, "type": btype, "date": timestamp_now, "file": filename })
    
    try:
        with open(list_file, 'w') as f: json.dump(backups, f)
        os.chmod(list_file, 0o666)
    except: pass
    
    try: os.remove(status_file)
    except: pass

def delete_backup_file(oid, filename):
    list_file = os.path.join(BUZON, f"backups_list_{oid}.json")
    if os.path.exists(list_file):
        try:
            backups = json.load(open(list_file))
            backups = [b for b in backups if b['file'] != filename]
            with open(list_file, 'w') as f: json.dump(backups, f)
        except: pass

def metrics_loop():
    log("📊 Vigilancia de Métricas Activa")
    while not shutdown_event.is_set():
        try:
            out = run_command(["docker", "ps", "-a", "--format", "{{.Names}}"], timeout=5)
            if out:
                for name in out.split('\n'):
                    if "sylo-cliente-" in name:
                        oid = name.replace("sylo-cliente-", "")
                        force_update_status(oid, name)
        except: pass
        for _ in range(30): 
            if shutdown_event.is_set(): break
            time.sleep(0.1)

def execute_action_thread(data):
    if shutdown_event.is_set(): return
    try:
        oid = data['id']; act = str(data['action']).upper()
        prof = f"sylo-cliente-{oid}"
        
        log(f"⚡ Acción: {act} -> #{oid}")

        if act == "START":
            run_command(["minikube", "start", "-p", prof], timeout=120)
            run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"UPDATE orders SET status='active' WHERE id={oid};"])
        elif act == "STOP":
            run_command(["minikube", "stop", "-p", prof], timeout=60)
            run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"UPDATE orders SET status='suspended' WHERE id={oid};"])
        elif act == "RESTART":
            run_command(["minikube", "stop", "-p", prof], timeout=60)
            run_command(["minikube", "start", "-p", prof], timeout=120)
        elif act == "BACKUP": 
            perform_backup(oid, prof, data.get('backup_type', 'full'), data.get('backup_name', 'Backup'))
        elif act == "UPDATE_WEB": 
            process_web(oid, prof, data.get('html_content', ''))
        elif act == "DELETE_BACKUP":
            delete_backup_file(oid, data.get('filename_to_delete'))
        elif act == "REFRESH":
            force_update_status(oid, prof)
        elif "TERMINATE" in act:
            perform_termination(oid, prof)
        
    except Exception as e: log(f"Error: {e}")

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass
    
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    log("=== OPERATOR V33 (LASER GUIDED) ===")
    
    threading.Thread(target=metrics_loop, name="Metrics", daemon=True).start()

    try:
        while not shutdown_event.is_set():
            files = glob.glob(os.path.join(BUZON, "accion_*.json"))
            for f in files:
                if shutdown_event.is_set(): break
                try:
                    data = json.load(open(f))
                    os.remove(f)
                    t = threading.Thread(target=execute_action_thread, args=(data,), name=f"Worker-{data.get('id')}", daemon=True)
                    t.start()
                except: 
                    try: os.remove(f)
                    except: pass
            time.sleep(0.5)
    except: pass
    finally:
        log("Operator Apagado.")

if __name__ == "__main__": main()