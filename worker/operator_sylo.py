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

# =======================================================
# SYLO OPERATOR V26 - CLEAN METRICS & REFRESH
# =======================================================
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
SUBNET_PREFIX = "192.168.200"

status_lock = threading.Lock()

def log(msg):
    t_name = threading.current_thread().name
    prefix = "" if t_name == "MainThread" else f"[{t_name}] "
    print(f"[SYLO] {prefix}{msg}")
    sys.stdout.flush()

def run_command(cmd):
    try:
        res = subprocess.run(cmd, check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=60)
        return res.stdout.strip()
    except: return None

def get_real_ip(profile):
    ip = run_command(["minikube", "-p", profile, "ip"])
    if ip and "192" in ip: return ip
    # Fallback Docker
    ip = run_command(["docker", "inspect", "-f", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", profile])
    if ip and "192" in ip: return ip
    return None

def update_web_progress(oid, percent, msg):
    st_file = os.path.join(BUZON, f"web_status_{oid}.json")
    try:
        with open(st_file, 'w') as f: json.dump({"progress": percent, "msg": msg}, f)
        os.chmod(st_file, 0o666)
    except: pass

def force_update_status(oid, profile):
    # Detectar estado real
    is_running = False
    
    # 1. Preguntar a Docker (Fuente más rápida y fiable para estado ON/OFF)
    docker_state = run_command(["docker", "inspect", "-f", "{{.State.Running}}", profile])
    
    if docker_state == 'true':
        is_running = True
    elif docker_state is None or docker_state == '':
        # Si docker no devuelve nada, la máquina NO EXISTE (fue borrada por Terminator)
        json_path = os.path.join(BUZON, f"status_{oid}.json")
        if os.path.exists(json_path):
            try: os.remove(json_path) # Auto-limpieza
            except: pass
        return # Salimos, no hay nada que actualizar

    data = {"metrics": {"cpu": 0.0, "ram": 0.0}} # Default 0
    
    if is_running:
        real_ip = get_real_ip(profile)
        if not real_ip: real_ip = f"{SUBNET_PREFIX}.{oid}"
        
        # Puertos
        svc_out = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", "-A", "-o", "json"])
        if svc_out:
            try:
                js = json.loads(svc_out)
                for item in js.get('items', []):
                    for p in item.get('spec', {}).get('ports', []):
                        if p.get('port') == 80 and 'nodePort' in p: data['web_url'] = f"http://{real_ip}:{p['nodePort']}"
                        if p.get('port') == 22 and 'nodePort' in p: 
                            data['ssh_cmd'] = f"ssh cliente@{real_ip} -p {p['nodePort']}"
                            data['ssh_pass'] = 'sylo1234'
            except: pass

        # Métricas (Solo si está running)
        stats = run_command(["docker", "stats", profile, "--no-stream", "--format", "{{.CPUPerc}},{{.MemPerc}}"])
        if stats:
            try:
                parts = stats.split(',')
                if len(parts) >= 2:
                    data['metrics']['cpu'] = float(parts[0].replace('%','').strip()) if parts[0].strip()!='--' else 0
                    data['metrics']['ram'] = float(parts[1].replace('%','').strip()) if parts[1].strip()!='--' else 0
            except: pass
    else:
        # Estado STOPPED
        data['ssh_cmd'] = "Servidor Detenido"
        data['web_url'] = ""
        # Las métricas se quedan en 0 por defecto

    json_path = os.path.join(BUZON, f"status_{oid}.json")
    with status_lock:
        try:
            # Preservar contraseña si existía
            if os.path.exists(json_path):
                old = json.load(open(json_path))
                if 'ssh_pass' in old: data['ssh_pass'] = old['ssh_pass']
            
            with open(json_path, 'w') as f: json.dump(data, f, indent=4)
            os.chmod(json_path, 0o666)
        except: pass

def perform_backup(oid, profile, btype='full', bname='Backup'):
    log(f"Snapshot '{bname}' ({btype})...")
    status_file = os.path.join(BUZON, f"backup_status_{oid}.json")
    list_file = os.path.join(BUZON, f"backups_list_{oid}.json")
    
    try:
        with open(status_file, 'w') as f: json.dump({"status": "creating", "progress": 5, "msg": f"Iniciando {btype}..."}, f)
        os.chmod(status_file, 0o666)
    except: pass
    
    time.sleep(2) 
    try:
        with open(status_file, 'w') as f: json.dump({"status": "creating", "progress": 50, "msg": "Procesando..."}, f)
    except: pass
    
    time.sleep(2)
    timestamp_now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    file_ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    filename = f"backup_v{oid}_{btype}_{file_ts}.tar.gz"
    
    backups = []
    if os.path.exists(list_file):
        try: backups = json.load(open(list_file))
        except: pass
    
    backups.insert(0, { "name": bname, "type": btype, "date": timestamp_now, "file": filename })
    
    with open(list_file, 'w') as f: json.dump(backups, f)
    try: os.chmod(list_file, 0o666)
    except: pass

    try: os.remove(status_file)
    except: pass
    log(f"Backup OK: {filename}")

def delete_backup_file(oid, filename):
    log(f"Borrar Backup: {filename}")
    status_file = os.path.join(BUZON, f"backup_status_{oid}.json")
    list_file = os.path.join(BUZON, f"backups_list_{oid}.json")

    # Simulacion visual para el dashboard
    try:
        with open(status_file, 'w') as f: json.dump({"status": "deleting", "progress": 10, "msg": "Borrando..."}, f)
        os.chmod(status_file, 0o666)
    except: pass
    time.sleep(1)
    
    try:
        with open(status_file, 'w') as f: json.dump({"status": "deleting", "progress": 80, "msg": "Limpiando..."}, f)
    except: pass
    time.sleep(0.5)

    if os.path.exists(list_file):
        try:
            backups = json.load(open(list_file))
            backups = [b for b in backups if b['file'] != filename]
            with open(list_file, 'w') as f: json.dump(backups, f)
        except: pass
    
    try: os.remove(status_file)
    except: pass
    log("Backup Eliminado")

def process_web(oid, profile, json_html_content):
    log("Actualizando Web...")
    update_web_progress(oid, 10, "Iniciando...") 
    source_file = os.path.join(BUZON, f"web_source_{oid}.html")
    final_html = json_html_content 
    if os.path.exists(source_file):
        try:
            with codecs.open(source_file, 'r', 'utf-8', errors='ignore') as f: final_html = f.read()
        except: pass
    
    update_web_progress(oid, 40, "Configurando...")
    
    pods_out = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-A", "-o", "json"])
    target_pod = None; target_ns = "default"; target_cm = "web-content-config"
    
    if pods_out:
        try:
            js = json.loads(pods_out)
            for i in js.get('items', []):
                if 'nginx' in i['metadata']['name'] or 'web' in i['metadata']['name']:
                    target_pod = i['metadata']['name']
                    target_ns = i['metadata']['namespace']
                    if 'custom' in target_pod: target_cm = "custom-web-content"
                    break
        except: pass
        
    if target_pod:
        update_web_progress(oid, 60, "Aplicando HTML...")
        tmp = f"/tmp/idx_{oid}.html"
        try:
            with codecs.open(tmp, 'w', 'utf-8') as f: f.write(final_html)
            run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "cm", target_cm, "-n", target_ns])
            run_command(["minikube", "-p", profile, "kubectl", "--", "create", "cm", target_cm, f"--from-file=index.html={tmp}", "-n", target_ns])
            os.remove(tmp)
        except: pass
        
        update_web_progress(oid, 80, "Reiniciando...")
        run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "pod", target_pod, "-n", target_ns])
        time.sleep(3)
        update_web_progress(oid, 100, "Online")
        time.sleep(1)
        try: os.remove(os.path.join(BUZON, f"web_status_{oid}.json"))
        except: pass
    else:
        update_web_progress(oid, 100, "Error: No pod")

def metrics_loop():
    log("📊 Vigilancia de Métricas Activa")
    while True:
        try:
            out = run_command(["docker", "ps", "-a", "--format", "{{.Names}}"]) # -a para ver parados también
            if out:
                for name in out.split('\n'):
                    if "sylo-cliente-" in name:
                        oid = name.replace("sylo-cliente-", "")
                        force_update_status(oid, name)
        except: pass
        time.sleep(3)

def execute_action_thread(data):
    try:
        oid = data['id']; act = data['action']
        prof = f"sylo-cliente-{oid}"
        
        # IMPORTANTE: No tocar órdenes de terminación
        if "terminate" in str(act).lower(): return

        profs = run_command(["minikube", "profile", "list"])
        if profs and prof not in profs:
            for line in profs.split('\n'):
                if f"-{oid}" in line: prof = line.strip(); break

        log(f"⚡ Acción: {act} -> #{oid}")

        if act == "START":
            run_command(["minikube", "start", "-p", prof])
            run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"UPDATE orders SET status='active' WHERE id={oid};"])
        elif act == "STOP":
            run_command(["minikube", "stop", "-p", prof])
            run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"UPDATE orders SET status='suspended' WHERE id={oid};"])
        elif act == "RESTART":
            run_command(["minikube", "stop", "-p", prof])
            run_command(["minikube", "start", "-p", prof])
            run_command(["docker", "exec", "-i", "kylo-main-db", "mysql", "-usylo_app", "-psylo_app_pass", "-D", "kylo_main_db", "-e", f"UPDATE orders SET status='active' WHERE id={oid};"])
        elif act == "BACKUP": 
            perform_backup(oid, prof, data.get('backup_type', 'full'), data.get('backup_name', 'Backup'))
        elif act == "UPDATE_WEB": 
            process_web(oid, prof, data.get('html_content', ''))
        elif act == "DELETE_BACKUP":
            delete_backup_file(oid, data.get('filename_to_delete'))
        elif act == "REFRESH":
            force_update_status(oid, prof)
        
    except Exception as e: log(f"Error: {e}")

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass
    log("=== OPERATOR V26 (MASTER) ===")
    
    threading.Thread(target=metrics_loop, name="Metrics", daemon=True).start()

    while True:
        files = glob.glob(os.path.join(BUZON, "accion_*.json"))
        # Filtrar los terminate para que no los coja el Operator, solo el Terminator
        files = [f for f in files if "_terminate_" not in f]
        
        for f in files:
            try:
                data = json.load(open(f))
                os.remove(f)
                threading.Thread(target=execute_action_thread, args=(data,), name=f"Worker-{data.get('id')}").start()
            except: 
                try: os.remove(f)
                except: pass
        time.sleep(0.1)

if __name__ == "__main__": main()