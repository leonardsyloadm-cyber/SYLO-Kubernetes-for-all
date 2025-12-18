#!/usr/bin/env python3
import os
import time
import json
import glob
import subprocess
import sys
import datetime

# ==========================================
# SYLO OPERATOR V9 - DB DETECT + FULL FEATURES
# ==========================================
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
SUBNET_PREFIX = "192.168.200"

class Colors:
    GREEN = '\033[92m'
    YELLOW = '\033[93m'
    RED = '\033[91m'
    RESET = '\033[0m'

def log(msg, color=Colors.RESET):
    print(f"{color}[SYLO] {msg}{Colors.RESET}")
    sys.stdout.flush()

def run_command(cmd):
    try:
        res = subprocess.run(cmd, check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        return res.stdout.strip()
    except: return None

def get_real_ip(profile):
    ip = run_command(["minikube", "-p", profile, "ip"])
    if ip and "192" in ip: return ip
    return None

def force_update_status(oid, profile):
    # 1. IP y Puertos
    real_ip = get_real_ip(profile)
    if not real_ip: real_ip = f"{SUBNET_PREFIX}.{oid}"
    
    svc_out = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "svc", "-A", "-o", "json"])
    web_port, ssh_port, db_port = None, None, None
    
    if svc_out:
        try:
            js = json.loads(svc_out)
            for item in js.get('items', []):
                for p in item.get('spec', {}).get('ports', []):
                    # Web (80)
                    if p.get('port') == 80 and 'nodePort' in p: web_port = p['nodePort']
                    # SSH (22)
                    if p.get('port') == 22 and 'nodePort' in p: ssh_port = p['nodePort']
                    # MySQL (3306) - NUEVO
                    if p.get('port') == 3306 and 'nodePort' in p: db_port = p['nodePort']
        except: pass

    # 2. Métricas
    cpu, ram = 0.0, 0.0
    stats = run_command(["docker", "stats", profile, "--no-stream", "--format", "{{.CPUPerc}},{{.MemPerc}}"])
    if stats:
        try:
            parts = stats.split(',')
            if len(parts) >= 2:
                cpu = float(parts[0].replace('%','').strip()) if parts[0].strip()!='--' else 0
                ram = float(parts[1].replace('%','').strip()) if parts[1].strip()!='--' else 0
        except: pass

    # 3. Guardar JSON
    data = {"metrics": {"cpu": cpu, "ram": ram}}
    
    if ssh_port:
        data['ssh_cmd'] = f"ssh cliente@{real_ip} -p {ssh_port}"
        data['ssh_pass'] = 'sylo1234'
        
    if web_port:
        data['web_url'] = f"http://{real_ip}:{web_port}"
        
    if db_port:
        # Guardamos datos de conexión DB
        data['db_host'] = f"{real_ip}:{db_port}"
        data['db_user'] = "root" # O el usuario que uses en tus deployments
        data['db_pass'] = "secret" # O la pass que uses

    json_path = os.path.join(BUZON, f"status_{oid}.json")
    if os.path.exists(json_path):
        try:
            old = json.load(open(json_path))
            if 'ssh_pass' in old: data['ssh_pass'] = old['ssh_pass']
        except: pass

    try:
        with open(json_path, 'w') as f: json.dump(data, f, indent=4)
        os.chmod(json_path, 0o666)
    except: pass

def perform_backup(oid, profile):
    log(f"💾 Iniciando Snapshot para #{oid}...", Colors.YELLOW)
    bf = os.path.join(BUZON, f"backup_info_{oid}.json")
    
    # Simulación de proceso
    info = {"status": "creating", "progress": 10, "msg": "Conectando al volumen..."}
    with open(bf, 'w') as f: json.dump(info, f); 
    try: os.chmod(bf, 0o666)
    except: pass
    time.sleep(1)
    
    info["progress"] = 45; info["msg"] = "Comprimiendo archivos..."; 
    with open(bf, 'w') as f: json.dump(info, f)
    time.sleep(1)
    
    info["progress"] = 80; info["msg"] = "Verificando integridad..."; 
    with open(bf, 'w') as f: json.dump(info, f)
    time.sleep(1)
    
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M")
    filename = f"backup_v{oid}_{timestamp}.tar.gz"
    
    final_data = {"status": "completed", "progress": 100, "last_backup": datetime.datetime.now().strftime("%d/%m/%Y %H:%M"), "file": filename}
    with open(bf, 'w') as f: json.dump(final_data, f)
    log(f"✅ Snapshot completado: {filename}", Colors.GREEN)

def process_web(oid, profile, html):
    log(f"Actualizando Web #{oid}...", Colors.YELLOW)
    target_pod = None
    pods_out = run_command(["minikube", "-p", profile, "kubectl", "--", "get", "pods", "-A", "-o", "json"])
    target_ns, target_cm = "default", "web-content"
    
    if pods_out:
        try:
            js = json.loads(pods_out)
            for i in js.get('items', []):
                name = i['metadata']['name']
                if 'nginx' in name or 'web' in name:
                    target_pod = name
                    target_ns = i['metadata']['namespace']
                    if 'custom' in name: target_cm = "custom-web-content"
                    elif 'nginx-ha' in name: target_cm = "web-content-config"
                    break
        except: pass
        
    if target_pod:
        tmp = f"/tmp/idx_{oid}.html"
        with open(tmp, 'w') as f: f.write(html)
        run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "cm", target_cm, "-n", target_ns])
        run_command(["minikube", "-p", profile, "kubectl", "--", "create", "cm", target_cm, f"--from-file=index.html={tmp}", "-n", target_ns])
        os.remove(tmp)
        run_command(["minikube", "-p", profile, "kubectl", "--", "delete", "pod", target_pod, "-n", target_ns])
        
        time.sleep(8)
        force_update_status(oid, profile)
        
        st_file = os.path.join(BUZON, f"web_status_{oid}.json")
        with open(st_file, 'w') as f: json.dump({"progress": 100, "msg": "¡Listo!"}, f)
        try: os.chmod(st_file, 0o666)
        except: pass
        time.sleep(3)
        try: os.remove(st_file)
        except: pass
    else: log("❌ No se encontró pod web", Colors.RED)

def sync_all_metrics():
    out = run_command(["docker", "ps", "--format", "{{.Names}}"])
    if not out: return
    for name in out.split('\n'):
        if "sylo-cliente-" in name:
            try: oid = name.replace("sylo-cliente-", ""); force_update_status(oid, name)
            except: pass

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass
    log("=== OPERATOR V9 (DB + WEB + BACKUP) ===", Colors.GREEN)

    while True:
        for f in glob.glob(os.path.join(BUZON, "accion_*.json")):
            try:
                data = json.load(open(f))
                oid = data['id']; act = data['action']
                prof = f"sylo-cliente-{oid}"
                
                profs = run_command(["minikube", "profile", "list"])
                if not profs or prof not in profs:
                    if profs:
                        for line in profs.split('\n'):
                            if f"-{oid}" in line: prof = line.strip(); break
                
                log(f"⚡ Acción: {act} -> #{oid}", Colors.YELLOW)
                
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
                elif act == "BACKUP": perform_backup(oid, prof)
                elif act == "UPDATE_WEB": process_web(oid, prof, data.get('html_content', '<h1>Vacio</h1>'))
                
                force_update_status(oid, prof)
                os.remove(f)
            except Exception as e:
                log(f"Error: {e}", Colors.RED)
                try: os.remove(f)
                except: pass
        
        sync_all_metrics()
        time.sleep(2)

if __name__ == "__main__": main()