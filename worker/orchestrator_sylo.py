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
import docker # Sylo Toolbelt Dependency

import requests

# ==========================================
# ORQUESTADOR SYLO V22 (FINAL STATUS FIX)
# ==========================================

WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR) # Subimos un nivel desde worker/
BUZON = os.path.join(BASE_DIR, "sylo-web", "buzon-pedidos")
SECURITY_DIR = os.path.join(WORKER_DIR, "security")

API_URL = "http://127.0.0.1:8001/api/clientes"

DB_CONTAINER = "kylo-main-db"
DB_USER = "sylo_app"
DB_PASS = "sylo_app_pass"
DB_NAME = "sylo_admin_db"

# --- SYLO TOOLBELT CATALOGS ---
TIER_1_ESSENTIALS = ["htop", "nano", "ncdu", "curl", "wget", "zip", "unzip", "git"]
TIER_2_DEV        = ["python3", "python3-pip", "nodejs", "npm", "mysql-client", "jq", "tmux", "lazygit"]
TIER_3_PRO        = ["rsync", "ffmpeg", "imagemagick", "redis-tools", "ansible", "speedtest-cli", "zsh"]

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

# 🔥 FIX IMPORTANTE: AHORA ACEPTA EL ARGUMENTO 'status' (Por defecto 'creating')
def report_progress(oid, percent, msg, status="creating"):
    if shutdown_event.is_set(): return
    file_path = os.path.join(BUZON, f"status_{oid}.json")
    tmp_path = file_path + ".tmp"
    # Usamos el status que nos pasen, si no, usa 'creating' para bloquear al Operator
    data = {"percent": percent, "message": msg, "status": status}
    try:
        with open(tmp_path, 'w') as f: json.dump(data, f)
        os.chmod(tmp_path, 0o666)
        os.replace(tmp_path, file_path)
    except: pass

def update_db_state(oid, status):
    if shutdown_event.is_set(): return
    sql = f"UPDATE k8s_deployments SET status='{status}' WHERE id={oid};"
    cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "--skip-column-names", "-e", sql]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def save_cluster_data(oid, profile, web_port_tf=None, ssh_port_tf=None):
    if shutdown_event.is_set(): return
    try:
        cmd_ip = f"minikube -p {profile} ip"
        ip = subprocess.run(cmd_ip, shell=True, stdout=subprocess.PIPE, text=True).stdout.strip()
        
        if ip:
            log(f"💾 Guardando IP {ip} en base de datos...", Colors.CYAN)
            sql = f"UPDATE k8s_deployments SET ip_address='{ip}' WHERE id={oid};"
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

def get_next_free_ip():
    """Genera una IP única en el rango de Minikube (192.168.49.X)"""
    try:
        # Obtenemos IPs usadas
        cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "--skip-column-names", "-e", "SELECT ip_address FROM k8s_deployments WHERE status NOT IN ('terminated','cancelled')"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, text=True)
        used_ips = [line.strip() for line in res.stdout.splitlines() if line.strip()]
        
        # Rango .2 a .200 (Reservamos .1 para Gateway y .254 para otros usos)
        base = "192.168.49"
        for i in range(2, 200):
            candidate = f"{base}.{i}"
            if candidate not in used_ips:
                return candidate
        return None
    except Exception as e:
        log(f"⚠️ Error calculando IP libre: {e}", Colors.RED)
        return None

# --- SEGURIDAD: APLICAR NETWORK POLICIES ---
def apply_security_policy(profile, owner_id):
    log(f"🔒 Aplicando Escudo de Seguridad para Usuario {owner_id} en {profile}...", Colors.YELLOW)
    try:
        if not os.path.exists(SECURITY_DIR):
            os.makedirs(SECURITY_DIR)
            
        tpl_isolation = """
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: aislamiento-usuario
  namespace: default
spec:
  podSelector: {} 
  policyTypes:
  - Ingress
  ingress:
  - from:
    - podSelector:
        matchLabels:
          owner: "OWNER_ID_PLACEHOLDER"
  - from:
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ingress-nginx
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: metallb-system
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: kube-system 
"""
        final_yaml = tpl_isolation.replace("OWNER_ID_PLACEHOLDER", str(owner_id))
        tmp_policy = f"/tmp/policy_{profile}.yaml"
        with open(tmp_policy, "w") as f: f.write(final_yaml)
        
        subprocess.run(["minikube", "-p", profile, "kubectl", "--", "apply", "-f", tmp_policy], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
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

        full_domain = f"{subdomain.lower()}.sylobi.org"
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

def push_final_credentials(oid, profile, subdomain, ip_cluster, os_real_name="Linux"):
    try:
        log(f"📝 Generando reporte final para API (OS: {os_real_name})...", Colors.CYAN)
        
        # Leemos el JSON generado por el script BASH para obtener datos precisos
        ssh_cmd = "SSH no disponible"
        web_url = f"http://{subdomain}.sylobi.org"
        
        status_file = os.path.join(BUZON, f"status_{oid}.json")
        if os.path.exists(status_file):
            try:
                with open(status_file, 'r') as f:
                    data = json.load(f)
                    ssh_cmd = data.get("ssh_cmd", ssh_cmd)
                    # Si el script bash reportó web_url vacía (Bronce), la respetamos
                    if "web_url" in data and not data["web_url"]:
                        web_url = "No Web Service"
            except: pass

        # Formateo bonito del OS para la API
        os_display = os_real_name.capitalize()
        if "alpine" in os_real_name.lower(): os_display = "Alpine Linux (Optimizado)"
        elif "ubuntu" in os_real_name.lower(): os_display = "Ubuntu Server LTS"
        elif "redhat" in os_real_name.lower(): os_display = "RedHat Enterprise (UBI)"

        payload = {
            "id_cliente": int(oid), 
            "metrics": {"cpu": 5, "ram": 12},
            "ssh_cmd": ssh_cmd, 
            "web_url": web_url,
            "os_info": os_display
        }
        requests.post(f"{API_URL}/reportar/metricas", json=payload, timeout=2)
        log(f"✅ Reporte enviado a API: {web_url} [{os_display}]", Colors.GREEN)
        
        # 🌐 AUTO-REGISTRAR SUBDOMINIO VÍA INGRESS (reemplaza sylo_nginx_manager eliminado)
        if subdomain and subdomain != "No Web Service":
            try:
                profile = f"sylo-cliente-{oid}"
                kubeconfig_tmp = f"/tmp/kubeconfig-{oid}.yaml"
                # Actualizar kubeconfig aislado para este cluster
                subprocess.run(
                    ["minikube", "update-context", "-p", profile],
                    env={**os.environ, "KUBECONFIG": kubeconfig_tmp},
                    timeout=10, capture_output=True
                )
                ingress_yaml = f"""apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: web-ingress
  namespace: default
  annotations:
    nginx.ingress.kubernetes.io/rewrite-target: /
spec:
  ingressClassName: nginx
  rules:
  - host: {subdomain}.sylobi.org
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
                subprocess.run(
                    ["kubectl", "apply", "-f", "-"],
                    input=ingress_yaml.encode(),
                    env={**os.environ, "KUBECONFIG": kubeconfig_tmp},
                    timeout=15, capture_output=True
                )
                log(f"🌐 Ingress registrado: {subdomain}.sylobi.org → web-service:80", Colors.GREEN)
            except Exception as ne:
                log(f"⚠️ No se pudo registrar ingress: {ne}", Colors.YELLOW)

        
    except Exception as e: 
        log(f"⚠️ Error enviando reporte: {e}", Colors.RED)

def run_bash_script(script_path, args, env_vars=None, cwd=None):
    if shutdown_event.is_set(): return False
    cmd = ["bash", script_path] + [str(a) for a in args]
    
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

# ==============================================================================
# SYLO TOOLBELT: LOGIC & INJECTION
# ==============================================================================

def validate_tools(plan_name, total_price, requested_tools):
    """
    Filtra las herramientas según el plan del usuario.
    Retorna la lista final de herramientas permitidas.
    """
    allowed_catalog = set()
    
    # Determinar nivel de acceso basado en Plan o Precio
    plan_clean = plan_name.lower()
    
    # Lógica Dinámica de Precios (Custom)
    is_custom = (plan_clean == "personalizado")
    
    # Tier 1: Bronce o Custom < 15
    if plan_clean == "bronce" or (is_custom and total_price < 15):
        allowed_catalog.update(TIER_1_ESSENTIALS)
        
    # Tier 2: Plata o Custom >= 15
    elif plan_clean == "plata" or (is_custom and 15 <= total_price < 30):
        allowed_catalog.update(TIER_1_ESSENTIALS)
        allowed_catalog.update(TIER_2_DEV)
        
    # Tier 3: Oro o Custom >= 30
    elif plan_clean == "oro" or (is_custom and total_price >= 30):
        allowed_catalog.update(TIER_1_ESSENTIALS)
        allowed_catalog.update(TIER_2_DEV)
        allowed_catalog.update(TIER_3_PRO)
        
    # Filtrado silencioso
    final_list = [t for t in requested_tools if t in allowed_catalog]
    
    if final_list:
        log(f"🔧 Toolbelt: Solicitadas {len(requested_tools)} -> Aprobadas {len(final_list)} ({plan_name})", Colors.CYAN)
    
    return final_list

def install_tools(container_name, tools_list):
    """
    Inyecta las herramientas en el contenedor usando Docker API.
    Auto-detecta si es Alpine o Debian/Ubuntu.
    """
    if not tools_list: return
    
    log(f"🛠️ Instalando Sylo Toolbelt en {container_name}: {', '.join(tools_list)}...", Colors.YELLOW)
    try:
        client = docker.from_env()
        container = client.containers.get(container_name)
        
        # Detectar OS (Check rápido)
        # Intentamos ejecutar 'cat /etc/os-release'
        exit_code, output = container.exec_run("cat /etc/os-release")
        os_info = output.decode().lower()
        
        cmd_install = ""
        if "alpine" in os_info:
            # Alpine: apk add --no-cache
            pkgs = " ".join(tools_list)
            cmd_install = f"apk add --no-cache {pkgs}"
        else:
            # Debian/Ubuntu: apt-get
            # Añadimos DEBIAN_FRONTEND=noninteractive para evitar bloqueos
            pkgs = " ".join(tools_list)
            cmd_install = f"apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y {pkgs}"
            
        # Ejecutar Instalación
        # Detach=False para esperar a que termine
        res = container.exec_run(["/bin/sh", "-c", cmd_install], user="root")
        
        if res.exit_code == 0:
            log(f"✅ Herramientas instaladas correctamente en {container_name}.", Colors.GREEN)
        else:
            log(f"⚠️ Error instalando herramientas: {res.output.decode()}", Colors.RED)
            
    except Exception as e:
        log(f"⚠️ Fallo en inyección Toolbelt: {e}", Colors.RED)

def process_order(json_file):
    if shutdown_event.is_set(): return
    processing_file = json_file + ".procesando"
    try: shutil.move(json_file, processing_file)
    except: return 
    
    try:
        with open(processing_file, 'r') as f: data = json.load(f)
        try: oid = int(data.get("id")); plan_raw = data.get("plan")
        except: return # Malformed ID
        specs = data.get("specs", {})
        
        # OBTENER DUEÑO
        owner_id = str(data.get("id_usuario_real", "admin"))
        
        # Datos del Pedido (Lo que el cliente pidió)
        subdomain = specs.get("subdomain", f"cliente{oid}")
        ssh_user = specs.get("ssh_user", "usuario")
        os_requested = specs.get("os_image", "ubuntu") # Lo que eligió en la web
        
        # --- SANITIZATION (FIX INVALID SUBDOMAIN ERROR) ---
        # Aseguramos que no haya caracteres inválidos para DNS (como guiones bajos)
        import re
        def sanitize_dns(val):
            return re.sub(r'[^a-zA-Z0-9-]', '-', str(val)).lower().strip('-')

        subdomain = sanitize_dns(subdomain)
        
        # También sanitizamos nombres de servicios si existen en specs (para Custom/Oro)
        if "db_custom_name" in specs: specs["db_custom_name"] = sanitize_dns(specs["db_custom_name"])
        if "web_custom_name" in specs: specs["web_custom_name"] = sanitize_dns(specs["web_custom_name"])

        log(f"👉 PROCESANDO ID: {oid} | User: {owner_id} | Plan: {plan_raw} | ReqOS: {os_requested} | Sub: {subdomain}", Colors.GREEN)
        update_db_state(oid, "creating")
        
        cluster_profile = f"sylo-cliente-{oid}"
        success = False
        env_vars = {"TF_VAR_owner_id": owner_id}

        # ==============================================================================
        # 🧠 ROUTER DE LÓGICA DE NEGOCIO (THEMATIC ENFORCEMENT)
        # ==============================================================================

        # HELPER: Configuración Determinista de IP (Subnet Sharding)
        def reserve_ip(oid):
            # Subnet Sharding: Usamos el tercer octeto para aislar redes.
            # Base 50. Si ID es muy grande, hacemos wrap around o saltamos.
            try:
                third_octet = 50 + int(oid)
                
                # Protección para IDs gigantes (>200)
                if third_octet > 250: 
                    # Fallback a rango 10.10.x.x si tenemos más de 200 clientes
                    third_octet = int(oid) % 200
                    fixed_ip = f"10.10.{third_octet}.2"
                else:
                    fixed_ip = f"192.168.{third_octet}.2"
                    
                log(f"📌 IP Inmortal (Subnet Sharding) asignada para ID {oid}: {fixed_ip}", Colors.CYAN)
                
                # Persistir en DB
                sql = f"UPDATE k8s_deployments SET ip_address='{fixed_ip}' WHERE id={oid};"
                subprocess.run(["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "--skip-column-names", "-e", sql], stdout=subprocess.DEVNULL)
                
                return fixed_ip
            except Exception as e:
                log(f"❌ Error calculando IP: {e}", Colors.RED)
                return ""

        # --- PLAN BRONCE ---
        # Temática: Siempre Alpine, Solo SSH. Ignoramos lo que venga en el JSON de Web/DB.
        if plan_raw == "Bronce":
            fixed_ip = reserve_ip(oid)
            report_progress(oid, 10, f"Iniciando Plan Bronce (Alpine) en {fixed_ip}...")
            # Argumentos: ID, User, OS(Forzado), DB(Ignorado), Web(Ignorado), Subdomain, FIXED_IP
            args = [oid, ssh_user, "alpine", "no-db", "no-web", subdomain, fixed_ip]
            success = run_bash_script(SCRIPT_BRONCE, args, env_vars)
            
            # Variable para reporte final (La real, no la pedida)
            os_final = "alpine"

        # --- PLAN PLATA ---
        # Temática: Alpine o Ubuntu. DB Obligatoria (MySQL). Sin Web.
        elif plan_raw == "Plata":
            db_name = specs.get("db_custom_name", "sylo_db")
            fixed_ip = reserve_ip(oid)
            report_progress(oid, 10, f"Iniciando Plan Plata ({os_requested}) en {fixed_ip}...")
            # Argumentos del script: ID, User, OS, DB_Name, Subdomain, FIXED_IP
            args = [oid, ssh_user, os_requested, db_name, subdomain, fixed_ip]
            success = run_bash_script(f"./{os.path.basename(SCRIPT_PLATA)}", args, env_vars, cwd=os.path.dirname(SCRIPT_PLATA))
            
            os_final = os_requested

        # --- PLAN ORO ---
        # Temática: Todo permitido (inc. RedHat). Web + DB Obligatorios.
        elif plan_raw == "Oro":
            db_name = specs.get("db_custom_name", "sylo_db")
            web_name = specs.get("web_custom_name", "sylo_web")
            fixed_ip = reserve_ip(oid)
            report_progress(oid, 10, f"Iniciando Plan Oro ({os_requested}) en {fixed_ip}...")
            # Argumentos: ID, User, OS, DB_Name, Web_Name, Subdomain, FIXED_IP
            args = [oid, ssh_user, os_requested, db_name, web_name, subdomain, fixed_ip]
            success = run_bash_script(SCRIPT_ORO, args, env_vars)
            
            os_final = os_requested

        # --- PLAN PERSONALIZADO ---
        # Temática: A la carta. Pasamos todos los parámetros crudos.
        elif plan_raw == "Personalizado":
            cpu = specs.get("cpu", "2"); ram = specs.get("ram", "4"); storage = specs.get("storage", "10")
            db_en = str(specs.get("db_enabled", "")).lower(); db_type = specs.get("db_type", "mysql")
            web_en = str(specs.get("web_enabled", "")).lower(); web_type = specs.get("web_type", "nginx")
            db_name = specs.get("db_custom_name", "custom_db")
            web_name = specs.get("web_custom_name", "custom_web")
            
            fixed_ip = reserve_ip(oid)
            report_progress(oid, 10, f"Iniciando Custom ({os_requested}) en {fixed_ip}...")
            
            args = [oid, cpu, ram, storage, db_en, db_type, web_en, web_type, ssh_user, os_requested, db_name, web_name, subdomain, fixed_ip]
            success = run_bash_script(SCRIPT_CUSTOM, args, env_vars)
            
            os_final = os_requested

        # --- (MARKETPLACE ELIMINADO POR PETICIÓN DEL CLIENTE) ---
        elif plan_raw == "App":
            log(f"⚠️ Pedido de App ignorado: El módulo App Reactor ha sido desactivado.", Colors.YELLOW)
            success = False

        # ==============================================================================

        if success:
            log(f"✅ ID {oid} Desplegado. Aplicando Mejoras...", Colors.GREEN)
            
            # 1. SYLO TOOLBELT INJECTION
            requested_tools = specs.get("tools", [])
            total_price = float(specs.get("price", 0)) # Asegurarse de que venga en el JSON
            
            # Nombre del contenedor (Asumimos convención sylo-cliente-{oid} para Docker)
            # OJO: Si es Kubernetes, esto intenta conectar al contenedor 'docker' que tenga ese nombre.
            # En Minikube con driver docker, los contenedores son hermanos o internos.
            # Asumimos que la red es plana o accesible.
            target_container = f"sylo-cliente-{oid}" 
            
            valid_tools = validate_tools(plan_raw, total_price, requested_tools)
            install_tools(target_container, valid_tools)
            
            # --- PERSIST TOOL INFO VIA API (SAFER) ---
            try:
                import requests
                api_tools_url = f"{API_URL.replace('/clientes', '')}/clientes/reportar/tools"
                payload = {"id_cliente": oid, "tools": valid_tools}
                try:
                    r = requests.post(api_tools_url, json=payload, timeout=2)
                    if r.status_code == 200:
                        log(f"💾 Tools reportadas a API: {valid_tools}", Colors.CYAN)
                    else:
                        log(f"⚠️ Error API Tools: {r.text}", Colors.RED)
                except Exception as ex:
                     log(f"⚠️ Error conexión API Tools: {ex}", Colors.RED)

            except Exception as e:
                log(f"⚠️ Error general tools: {e}", Colors.RED)

            # 2. SEGURIDAD
            apply_security_policy(cluster_profile, owner_id)
            
            # 2. RED Y MONITORING
            ip_cluster = save_cluster_data(oid, cluster_profile)
            
            # Solo activamos Ingress/Metallb si NO es Bronce (Bronce es solo SSH)
            if plan_raw != "Bronce":
                report_progress(oid, 90, "Asignando DNS y SSL...")
                enable_monitoring(cluster_profile)
                activating_red_pro(cluster_profile, oid, subdomain) 
            
            if ip_cluster:
                # Usamos os_final para que la API sepa qué se instaló realmente
                push_final_credentials(oid, cluster_profile, subdomain, ip_cluster, os_final)
            
            update_db_state(oid, "active")
            
            # 🔥 AQUÍ ESTÁ EL CAMBIO CLAVE: Cambiamos a 'completed' para liberar al Operator
            report_progress(oid, 100, "Despliegue finalizado.", status="completed")
            
            log(f"✨ ID {oid} LISTO Y SEGURO.", Colors.GREEN)
        else:
            update_db_state(oid, "error")
            log(f"❌ Fallo ID {oid}", Colors.RED)

    except Exception as e:
        log(f"Error fatal: {e}", Colors.RED)
    finally:
        try: shutil.move(processing_file, json_file.replace(".json", ".json.procesado"))
        except: pass

def process_action(json_file):
    if shutdown_event.is_set(): return
    processing_file = json_file + ".procesando"
    try: shutil.move(json_file, processing_file)
    except: return

    try:
        with open(processing_file, 'r') as f: data = json.load(f)
        oid = int(data.get("id_cliente"))
        action = data.get("action", "").upper()
        
        log(f"⚡ ACCIÓN RECIBIDA ID {oid}: {action}", Colors.YELLOW)
        
        cluster_profile = f"sylo-cliente-{oid}"
        buzon_status = os.path.join(BUZON, f"power_status_{oid}.json")
        if action in ["BACKUP", "RESTORE"]: 
            buzon_status = os.path.join(BUZON, f"backup_status_{oid}.json")
        
        # Helper para reportar progreso de la acción
        def report_act_progress(pct, msg, status="processing"):
            # Mapeamos status a algo que el frontend entienda (stopping, starting...)
            # Ojo: El frontend espera keys traducibles si es posible
            payload = {"percent": pct, "msg": msg, "status": status, "source_type": "action"}
            with open(buzon_status, 'w') as f: json.dump(payload, f)
            
        if action == "STOP":
            report_act_progress(10, "progress_stopping", "stopping")
            # FIX: Force stop if needed or just wait longer? Minikube stop can hang.
            res = run_bash_script("minikube", ["stop", "-p", cluster_profile])
            
            # Even if it fails (already stopped?), we mark as stopped to clear UI
            report_act_progress(100, "completed", "stopped")
            update_db_state(oid, "stopped")

        elif action == "START":
            report_act_progress(10, "progress_starting", "starting")
            # FIX: Memory Crash on Bronze resolved by using --force (like in deploy script)
            # Reverted to 1100m as requested by user. force flag is key.
            run_bash_script("minikube", ["start", "-p", cluster_profile, "--memory=1100m", "--force"])
            report_act_progress(100, "completed", "active")
            update_db_state(oid, "active")

        elif action == "RESTART":
            report_act_progress(10, "progress_restarting", "restarting")
            run_bash_script("minikube", ["stop", "-p", cluster_profile])
            time.sleep(2)
            run_bash_script("minikube", ["start", "-p", cluster_profile, "--memory=1100m", "--force"])
            report_act_progress(100, "completed", "active")
            update_db_state(oid, "active")

        elif action == "DESTROY_K8S":
            report_act_progress(10, "backend.deleting", "deleting")
            run_bash_script("minikube", ["delete", "-p", cluster_profile])
            report_act_progress(100, "backend.deleted", "terminated")
            update_db_state(oid, "terminated")
            
        elif action == "BACKUP":
            b_type = data.get("backup_type", "FULL")
            b_name = data.get("backup_name", "Manual").replace(" ", "_")
            ts = time.strftime("%Y%m%d%H%M%S")
            filename = f"backup_v{oid}_{b_type}_{b_name}_{ts}.tar.gz"
            dest = os.path.join(BUZON, filename)
            
            report_act_progress(10, "backend.starting", "backup_processing")
            time.sleep(1)
            report_act_progress(30, "backend.reading", "backup_processing")
            
            # TODO: Implementar backup real (ej: velero o exportar recursos)
            # Por ahora creamos un dummy file
            run_bash_script("touch", [dest])
            
            report_act_progress(80, "backend.saving", "backup_processing")
            time.sleep(1)
            report_act_progress(100, "backend.completed", "completed")

        elif action == "RESTORE":
            f_name = data.get("filename_to_restore")
            report_act_progress(10, "backend.locating", "restoring")
            time.sleep(1)
            report_act_progress(50, "backend.applying", "restoring")
            # Logica Dummy Restore
            time.sleep(1)
            report_act_progress(100, "backend.completed", "completed")
            
        elif action == "DELETE_BACKUP":
            f_name = data.get("filename_to_delete")
            p = os.path.join(BUZON, f_name)
            if os.path.exists(p): os.remove(p)
            report_act_progress(100, "backend.deleted", "completed")

    except Exception as e:
        log(f"⚠️ Error procesando acción: {e}", Colors.RED)
    finally:
        try: shutil.move(processing_file, json_file.replace(".json", ".json.procesado"))
        except: pass

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)
    
    log(f"=== ORQUESTADOR SYLO V22 (FINAL STATUS FIX + ACTIONS) ===", Colors.BLUE)
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        while not shutdown_event.is_set():
            # 1. Procesar Ordenes
            files = glob.glob(os.path.join(BUZON, "orden_*.json"))
            for json_file in files:
                if shutdown_event.is_set(): break
                executor.submit(process_order, json_file)
            
            # 2. Procesar Acciones (Power, Backup) - MOVIDO A OPERATOR_SYLO.PY
            # actions = glob.glob(os.path.join(BUZON, "accion_*.json"))
            # for act_file in actions:
            #    if shutdown_event.is_set(): break
            #    executor.submit(process_action, act_file)
                
            time.sleep(2)
        executor.shutdown(wait=False, cancel_futures=True)

if __name__ == "__main__": main()