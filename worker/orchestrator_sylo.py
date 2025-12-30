#!/usr/bin/env python3
import os
import time
import json
import glob
import subprocess
import sys
import shutil
import signal
import threading
from concurrent.futures import ThreadPoolExecutor

try: import requests
except: pass

# ==========================================
# ORQUESTADOR SYLO V18 (SOPORTE REDHAT/PUERTOS DINÁMICOS)
# ==========================================

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
API_URL = "http://127.0.0.1:8001/api/clientes" # OJO: Si usas Docker para el orquestador, usa host.docker.internal

DB_CONTAINER = "kylo-main-db"
DB_USER = "sylo_app"
DB_PASS = "sylo_app_pass"
DB_NAME = "kylo_main_db"

# Scripts de Despliegue
SCRIPT_BRONCE = os.path.join(BASE_DIR, "tofu-k8s/k8s-simple/deploy_simple.sh")
SCRIPT_PLATA = os.path.join(BASE_DIR, "tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh")
SCRIPT_ORO = os.path.join(BASE_DIR, "tofu-k8s/full-stack/deploy_oro.sh")
SCRIPT_CUSTOM = os.path.join(BASE_DIR, "tofu-k8s/custom-stack/deploy_custom.sh")

MAX_WORKERS = 3 
shutdown_event = threading.Event()

class Colors:
    GREEN = '\033[92m'; BLUE = '\033[94m'; CYAN = '\033[96m'; RED = '\033[91m'; RESET = '\033[0m'

def log(msg, color=Colors.RESET):
    print(f"{color}[ORCHESTRATOR] {msg}{Colors.RESET}")
    sys.stdout.flush()

def signal_handler(signum, frame):
    shutdown_event.set()
    sys.exit(0)

def report_progress(oid, percent, msg):
    if shutdown_event.is_set(): return
    file_path = os.path.join(BUZON, f"status_{oid}.json")
    data = {"percent": percent, "message": msg, "status": "creating"}
    try:
        with open(file_path, 'w') as f: json.dump(data, f)
        os.chmod(file_path, 0o666)
    except: pass

def update_db_state(oid, status):
    if shutdown_event.is_set(): return
    sql = f"UPDATE orders SET status='{status}' WHERE id={oid};"
    cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "--skip-column-names", "-e", sql]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

# --- FUNCIÓN CRÍTICA: GUARDAR IP Y DATOS FINALES ---
def save_cluster_data(oid, profile, web_port_tf=None, ssh_port_tf=None):
    if shutdown_event.is_set(): return
    try:
        # 1. Obtener IP de Minikube (usando el perfil)
        cmd_ip = f"minikube -p {profile} ip"
        ip = subprocess.run(cmd_ip, shell=True, stdout=subprocess.PIPE, text=True).stdout.strip()
        
        if ip:
            log(f"💾 Guardando IP {ip} en base de datos...", Colors.CYAN)
            sql = f"UPDATE orders SET ip_address='{ip}' WHERE id={oid};"
            cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "--skip-column-names", "-e", sql]
            subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            
            return ip
        else:
            log("⚠️ No se pudo obtener IP del cluster", Colors.RED)
            return None
    except Exception as e:
        log(f"❌ Error guardando IP: {e}", Colors.RED)
        return None

def enable_monitoring(profile):
    subprocess.run(["minikube", "addons", "enable", "metrics-server", "-p", profile], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def activating_red_pro(profile, oid, subdomain):
    if shutdown_event.is_set(): return
    log(f"🌐 Configurando Red Segura (.49.x) para {profile}...", Colors.CYAN)
    try:
        subprocess.run(["minikube", "addons", "enable", "metallb", "-p", profile], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(["minikube", "addons", "enable", "ingress", "-p", profile], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        
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
        tmp_mlb = f"/tmp/metallb_{oid}.yaml"
        with open(tmp_mlb, "w") as f: f.write(mlb_config)
        time.sleep(5)
        subprocess.run(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_mlb], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        
        subprocess.run(["minikube", "-p", profile, "kubectl", "--", "patch", "svc", "ingress-nginx-controller", "-n", "ingress-nginx", "-p", '{"spec": {"type": "LoadBalancer"}}'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        full_domain = f"{subdomain}.sylobi.org"
        
        # INGRESS QUE APUNTA AL SERVICIO UNIFICADO (web-service)
        ingress_yaml = f"""apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: ingress-cliente-{oid}
  annotations:
    nginx.ingress.kubernetes.io/rewrite-target: /
spec:
  rules:
  - host: {full_domain}
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: web-service
            port:
              number: 80
"""
        tmp_ing = f"/tmp/ingress_{oid}.yaml"
        with open(tmp_ing, "w") as f: f.write(ingress_yaml)
        subprocess.run(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_ing], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        
        try: os.remove(tmp_mlb); os.remove(tmp_ing)
        except: pass
        
    except Exception as e:
        log(f"⚠️ Error red: {e}", Colors.RED)

def push_final_credentials(oid, profile, subdomain, ip_cluster):
    try:
        log(f"📝 Generando reporte final para API...", Colors.CYAN)
        web_url = f"http://{subdomain}.sylobi.org"
        
        # Intentamos obtener los puertos reales desde Terraform si es posible
        # (Esto requiere parsear el log o leer el state, pero usaremos un fallback inteligente)
        
        # Buscar puerto SSH (NodePort)
        ssh_cmd = "SSH no disponible"
        cmd_port = ["minikube", "-p", profile, "kubectl", "--", "get", "svc", "web-service", "-o", "json"]
        svc_info = subprocess.run(cmd_port, stdout=subprocess.PIPE, text=True)
        
        if svc_info.returncode == 0 and svc_info.stdout:
            data = json.loads(svc_info.stdout)
            for port in data.get('spec', {}).get('ports', []):
                if port.get('name') == 'ssh' and port.get('nodePort'):
                    ssh_port = port['nodePort']
                    ssh_cmd = f"ssh root@{ip_cluster} -p {ssh_port}"
                    break
        
        # Enviamos métricas dummy iniciales para que el panel no salga en 0 absoluto
        payload = {
            "id_cliente": int(oid), 
            "metrics": {"cpu": 5, "ram": 12}, # Valores iniciales dummy
            "ssh_cmd": ssh_cmd, 
            "web_url": web_url
        }
        requests.post(f"{API_URL}/reportar/metricas", json=payload, timeout=2)
        log(f"✅ Reporte enviado a API: {web_url}", Colors.GREEN)
        
    except Exception as e: 
        log(f"⚠️ Error enviando reporte: {e}", Colors.RED)

def run_bash_script(script_path, args, cwd=None):
    if shutdown_event.is_set(): return False
    cmd = ["bash", script_path] + [str(a) for a in args]
    try:
        process = subprocess.Popen(cmd, cwd=cwd, stdout=sys.stdout, stderr=subprocess.STDOUT)
        while process.poll() is None:
            if shutdown_event.is_set(): process.terminate(); return False
            time.sleep(0.5)
        return process.returncode == 0
    except: return False

def process_order(json_file):
    if shutdown_event.is_set(): return
    processing_file = json_file + ".procesando"
    try: shutil.move(json_file, processing_file)
    except: return 
    
    try:
        with open(processing_file, 'r') as f: data = json.load(f)
        oid = data.get("id"); plan_raw = data.get("plan")
        specs = data.get("specs", {})
        
        subdomain = specs.get("subdomain", f"cliente{oid}")
        ssh_user = specs.get("ssh_user", "usuario")
        os_image = specs.get("os_image", "ubuntu") 
        
        log(f"👉 PROCESANDO ID: {oid} | Plan: {plan_raw} | Dom: {subdomain} | OS: {os_image}", Colors.GREEN)
        update_db_state(oid, "creating")
        report_progress(oid, 10, "Preparando entorno personalizado...")
        cluster_profile = f"sylo-cliente-{oid}"
        success = False

        if plan_raw == "Bronce":
            args = [oid, ssh_user, os_image, subdomain]
            success = run_bash_script(SCRIPT_BRONCE, args)
            
        elif plan_raw == "Plata":
            db_name = specs.get("db_custom_name", "sylo_db")
            args = [oid, ssh_user, os_image, db_name, subdomain]
            success = run_bash_script(f"./{os.path.basename(SCRIPT_PLATA)}", args, cwd=os.path.dirname(SCRIPT_PLATA))
            
        elif plan_raw == "Oro":
            db_name = specs.get("db_custom_name", "sylo_db")
            web_name = specs.get("web_custom_name", "sylo_web")
            args = [oid, ssh_user, os_image, db_name, web_name, subdomain]
            success = run_bash_script(SCRIPT_ORO, args)
            
        elif plan_raw == "Personalizado":
            cpu = specs.get("cpu", "2"); ram = specs.get("ram", "4"); storage = specs.get("storage", "10")
            db_en = str(specs.get("db_enabled", "")).lower(); db_type = specs.get("db_type", "mysql")
            web_en = str(specs.get("web_enabled", "")).lower(); web_type = specs.get("web_type", "nginx")
            db_name = specs.get("db_custom_name", "custom_db")
            web_name = specs.get("web_custom_name", "custom_web")
            
            args = [oid, cpu, ram, storage, db_en, db_type, web_en, web_type, ssh_user, os_image, db_name, web_name, subdomain]
            success = run_bash_script(SCRIPT_CUSTOM, args)

        if success:
            log(f"✅ ID {oid} Desplegado. Configurando Red...", Colors.GREEN)
            
            # 1. GUARDAR IP EN DB (Vital para el Dashboard)
            ip_cluster = save_cluster_data(oid, cluster_profile)

            report_progress(oid, 90, "Asignando DNS y SSL...")
            enable_monitoring(cluster_profile)
            
            # 2. ACTIVAR RED EXTERNA (Ingress)
            activating_red_pro(cluster_profile, oid, subdomain) 
            
            # 3. ENVIAR DATOS FINALES A API (Para que el dashboard deje de estar en 0)
            if ip_cluster:
                push_final_credentials(oid, cluster_profile, subdomain, ip_cluster)
            
            update_db_state(oid, "active")
            report_progress(oid, 100, "Despliegue finalizado.")
            log(f"✨ ID {oid} LISTO.", Colors.GREEN)
        else:
            update_db_state(oid, "error")
            log(f"❌ Fallo ID {oid}", Colors.RED)

    except Exception as e:
        log(f"Error fatal: {e}", Colors.RED)
    finally:
        try: shutil.move(processing_file, json_file.replace(".json", ".json.procesado"))
        except: pass

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)
    
    log(f"=== ORQUESTADOR SYLO V18 (FULL) ===", Colors.BLUE)
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        while not shutdown_event.is_set():
            files = glob.glob(os.path.join(BUZON, "orden_*.json"))
            for json_file in files:
                if shutdown_event.is_set(): break
                executor.submit(process_order, json_file)
            time.sleep(2)
        executor.shutdown(wait=False, cancel_futures=True)

if __name__ == "__main__": main()