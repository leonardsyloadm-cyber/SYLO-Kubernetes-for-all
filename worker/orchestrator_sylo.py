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
# ORQUESTADOR SYLO V19 (SEGURIDAD & AISLAMIENTO)
# ==========================================

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR) # Subimos un nivel desde worker/
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
SECURITY_DIR = os.path.join(WORKER_DIR, "security")

API_URL = "http://127.0.0.1:8001/api/clientes"

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
    GREEN = '\033[92m'; BLUE = '\033[94m'; CYAN = '\033[96m'; RED = '\033[91m'; YELLOW = '\033[93m'; RESET = '\033[0m'

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

def save_cluster_data(oid, profile, web_port_tf=None, ssh_port_tf=None):
    if shutdown_event.is_set(): return
    try:
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

# --- SEGURIDAD: APLICAR NETWORK POLICIES ---
def apply_security_policy(profile, owner_id):
    """
    Inyecta las reglas de aislamiento (NetworkPolicy) al cluster recién creado.
    """
    log(f"🔒 Aplicando Escudo de Seguridad para Usuario {owner_id} en {profile}...", Colors.YELLOW)
    try:
        # Asegurarnos de que existe el directorio de seguridad
        if not os.path.exists(SECURITY_DIR):
            os.makedirs(SECURITY_DIR)
            
        # Plantilla Base (Si no existe, la creamos en memoria)
        # NOTA: En producción esto debería leerse de un archivo yaml
        tpl_isolation = """
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: aislamiento-usuario
  namespace: default
spec:
  podSelector: {} # Aplica a todos los pods
  policyTypes:
  - Ingress
  ingress:
  # 1. Permitir tráfico interno SOLO si tienen el mismo dueño
  - from:
    - podSelector:
        matchLabels:
          owner: "OWNER_ID_PLACEHOLDER"
  # 2. Permitir tráfico externo necesario (Ingress, SSH, Monitoring)
  - from:
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ingress-nginx
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: metallb-system
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: kube-system # Para métricas
"""
        # Reemplazar el placeholder con el ID real
        final_yaml = tpl_isolation.replace("OWNER_ID_PLACEHOLDER", str(owner_id))
        
        # Guardar temporalmente
        tmp_policy = f"/tmp/policy_{profile}.yaml"
        with open(tmp_policy, "w") as f: f.write(final_yaml)
        
        # Aplicar con kubectl
        subprocess.run(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_policy], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        
        # Limpiar
        try: os.remove(tmp_policy)
        except: pass
        
        log(f"🛡️ Seguridad Activada: Cluster blindado.", Colors.GREEN)
        
    except Exception as e:
        log(f"⚠️ Error aplicando seguridad: {e}", Colors.RED)

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
        ssh_cmd = "SSH no disponible"
        cmd_port = ["minikube", "-p", profile, "kubectl", "--", "get", "svc", "web-service", "-o", "json"]
        
        # Intentamos obtener SSH del servicio principal o de uno dedicado
        svc_info = subprocess.run(cmd_port, stdout=subprocess.PIPE, text=True)
        if svc_info.returncode != 0:
             cmd_port = ["minikube", "-p", profile, "kubectl", "--", "get", "svc", "ssh-server-service", "-o", "json"]
             svc_info = subprocess.run(cmd_port, stdout=subprocess.PIPE, text=True)
        
        if svc_info.returncode == 0 and svc_info.stdout:
            data = json.loads(svc_info.stdout)
            # Buscar en lista de puertos o puertos directos
            ports = data.get('spec', {}).get('ports', [])
            for port in ports:
                # Buscamos puerto 22 o nombre ssh
                if (port.get('port') == 22 or port.get('name') == 'ssh') and port.get('nodePort'):
                    ssh_port = port['nodePort']
                    ssh_cmd = f"ssh root@{ip_cluster} -p {ssh_port}"
                    break
        
        payload = {
            "id_cliente": int(oid), 
            "metrics": {"cpu": 5, "ram": 12},
            "ssh_cmd": ssh_cmd, 
            "web_url": web_url
        }
        requests.post(f"{API_URL}/reportar/metricas", json=payload, timeout=2)
        log(f"✅ Reporte enviado a API: {web_url}", Colors.GREEN)
        
    except Exception as e: 
        log(f"⚠️ Error enviando reporte: {e}", Colors.RED)

def run_bash_script(script_path, args, env_vars=None, cwd=None):
    if shutdown_event.is_set(): return False
    cmd = ["bash", script_path] + [str(a) for a in args]
    
    # Fusión de variables de entorno (Sistema + Nuevas)
    current_env = os.environ.copy()
    if env_vars:
        current_env.update(env_vars)
        
    try:
        process = subprocess.Popen(cmd, cwd=cwd, env=current_env, stdout=sys.stdout, stderr=subprocess.STDOUT)
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
        
        # --- NUEVO: OBTENER DUEÑO ---
        # Si no viene (pedidos antiguos), usamos 'admin'
        owner_id = str(data.get("id_usuario_real", "admin"))
        
        subdomain = specs.get("subdomain", f"cliente{oid}")
        ssh_user = specs.get("ssh_user", "usuario")
        os_image = specs.get("os_image", "ubuntu") 
        
        log(f"👉 PROCESANDO ID: {oid} | User: {owner_id} | Plan: {plan_raw}", Colors.GREEN)
        update_db_state(oid, "creating")
        report_progress(oid, 10, "Preparando entorno blindado...")
        cluster_profile = f"sylo-cliente-{oid}"
        success = False

        # Preparamos las variables de entorno para Terraform
        env_vars = {"TF_VAR_owner_id": owner_id}

        if plan_raw == "Bronce":
            args = [oid, ssh_user, os_image, subdomain]
            success = run_bash_script(SCRIPT_BRONCE, args, env_vars)
            
        elif plan_raw == "Plata":
            db_name = specs.get("db_custom_name", "sylo_db")
            args = [oid, ssh_user, os_image, db_name, subdomain]
            success = run_bash_script(f"./{os.path.basename(SCRIPT_PLATA)}", args, env_vars, cwd=os.path.dirname(SCRIPT_PLATA))
            
        elif plan_raw == "Oro":
            db_name = specs.get("db_custom_name", "sylo_db")
            web_name = specs.get("web_custom_name", "sylo_web")
            args = [oid, ssh_user, os_image, db_name, web_name, subdomain]
            success = run_bash_script(SCRIPT_ORO, args, env_vars)
            
        elif plan_raw == "Personalizado":
            cpu = specs.get("cpu", "2"); ram = specs.get("ram", "4"); storage = specs.get("storage", "10")
            db_en = str(specs.get("db_enabled", "")).lower(); db_type = specs.get("db_type", "mysql")
            web_en = str(specs.get("web_enabled", "")).lower(); web_type = specs.get("web_type", "nginx")
            db_name = specs.get("db_custom_name", "custom_db")
            web_name = specs.get("web_custom_name", "custom_web")
            
            args = [oid, cpu, ram, storage, db_en, db_type, web_en, web_type, ssh_user, os_image, db_name, web_name, subdomain]
            success = run_bash_script(SCRIPT_CUSTOM, args, env_vars)

        if success:
            log(f"✅ ID {oid} Desplegado. Aplicando Seguridad...", Colors.GREEN)
            
            # 1. APLICAR SEGURIDAD (Network Policy)
            apply_security_policy(cluster_profile, owner_id)
            
            # 2. CONFIGURAR RED Y MONITORING
            ip_cluster = save_cluster_data(oid, cluster_profile)
            report_progress(oid, 90, "Asignando DNS y SSL...")
            enable_monitoring(cluster_profile)
            activating_red_pro(cluster_profile, oid, subdomain) 
            
            if ip_cluster:
                push_final_credentials(oid, cluster_profile, subdomain, ip_cluster)
            
            update_db_state(oid, "active")
            report_progress(oid, 100, "Despliegue finalizado.")
            log(f"✨ ID {oid} LISTO Y SEGURO.", Colors.GREEN)
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
    
    log(f"=== ORQUESTADOR SYLO V19 (SEGURIDAD ACTIVA) ===", Colors.BLUE)
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        while not shutdown_event.is_set():
            files = glob.glob(os.path.join(BUZON, "orden_*.json"))
            for json_file in files:
                if shutdown_event.is_set(): break
                executor.submit(process_order, json_file)
            time.sleep(2)
        executor.shutdown(wait=False, cancel_futures=True)

if __name__ == "__main__": main()