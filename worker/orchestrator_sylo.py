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

# ==========================================
# ORQUESTADOR SYLO V7 (KAMIKAZE MODE)
# ==========================================

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")

# Configuración DB
DB_CONTAINER = "kylo-main-db"
DB_USER = "sylo_app"
DB_PASS = "sylo_app_pass"
DB_NAME = "kylo_main_db"

# Rutas de Scripts
SCRIPT_BRONCE = os.path.join(BASE_DIR, "tofu-k8s/k8s-simple/deploy_simple.sh")
SCRIPT_PLATA = os.path.join(BASE_DIR, "tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh")
SCRIPT_ORO = os.path.join(BASE_DIR, "tofu-k8s/full-stack/deploy_oro.sh")
SCRIPT_CUSTOM = os.path.join(BASE_DIR, "tofu-k8s/custom-stack/deploy_custom.sh")

MAX_WORKERS = 5 
shutdown_event = threading.Event() # Bandera de muerte

class Colors:
    GREEN = '\033[92m'
    BLUE = '\033[94m'
    CYAN = '\033[96m'
    RED = '\033[91m'
    RESET = '\033[0m'

def log(msg, color=Colors.RESET):
    print(f"{color}[ORCHESTRATOR] {msg}{Colors.RESET}")
    sys.stdout.flush()

# --- GESTOR DE MUERTE ---
def signal_handler(signum, frame):
    log(f"💀 SEÑAL {signum} RECIBIDA. Abortando despliegues...", Colors.RED)
    shutdown_event.set()
    # Forzar salida inmediata (los hilos daemon morirán con nosotros)
    os._exit(0) 

def report_progress(oid, percent, msg):
    if shutdown_event.is_set(): return
    file_path = os.path.join(BUZON, f"status_{oid}.json")
    data = {"percent": percent, "message": msg, "status": "creating"}
    try:
        with open(file_path, 'w') as f: json.dump(data, f)
        try: os.chmod(file_path, 0o666)
        except: pass
    except: pass

def update_db_state(oid, status):
    if shutdown_event.is_set(): return
    sql = f"UPDATE orders SET status='{status}' WHERE id={oid};"
    cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "--skip-column-names", "-e", sql]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def enable_monitoring(profile):
    if shutdown_event.is_set(): return
    log(f"🔌 Inyectando sonda de monitorización en {profile}...", Colors.CYAN)
    subprocess.run(["minikube", "addons", "enable", "metrics-server", "-p", profile], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(5)

def run_bash_script(script_path, args, cwd=None):
    if shutdown_event.is_set(): return False
    cmd = ["bash", script_path] + [str(a) for a in args]
    try:
        # Popen permite matar el subproceso si morimos nosotros
        process = subprocess.Popen(cmd, cwd=cwd, stdout=sys.stdout, stderr=subprocess.STDOUT)
        
        while process.poll() is None:
            if shutdown_event.is_set():
                process.terminate() # Matar script bash si nos apagan
                return False
            time.sleep(0.5)
            
        return process.returncode == 0
    except Exception as e:
        log(f"Error ejecutando script: {e}", Colors.RED)
        return False

def process_order(json_file):
    if shutdown_event.is_set(): return
    processing_file = json_file + ".procesando"
    try: shutil.move(json_file, processing_file)
    except FileNotFoundError: return 

    try:
        with open(processing_file, 'r') as f: data = json.load(f)
        
        oid = data.get("id")
        plan_raw = data.get("plan")
        cliente = data.get("cliente", "cliente_generico")
        cliente = "".join(c for c in cliente if c.isalnum() or c in ('-', '_'))

        log(f"👉 Procesando ID: {oid} | Plan: {plan_raw}", Colors.GREEN)
        
        update_db_state(oid, "creating")
        report_progress(oid, 5, "Inicializando recursos...")

        cluster_profile = f"sylo-cliente-{oid}"
        success = False

        if plan_raw == "Bronce":
            report_progress(oid, 10, "Desplegando Plan Bronce...")
            success = run_bash_script(SCRIPT_BRONCE, [oid, cliente])

        elif plan_raw == "Plata":
            report_progress(oid, 10, "Desplegando Plan Plata...")
            script_dir = os.path.dirname(SCRIPT_PLATA)
            script_name = os.path.basename(SCRIPT_PLATA)
            success = run_bash_script(f"./{script_name}", [oid, cliente], cwd=script_dir)

        elif plan_raw == "Oro":
            report_progress(oid, 10, "Desplegando Plan Oro...")
            success = run_bash_script(SCRIPT_ORO, [oid, cliente])

        elif plan_raw == "Personalizado":
            specs = data.get("specs", {})
            args = [
                oid, 
                specs.get("cpu", "2"), 
                specs.get("ram", "4"), 
                specs.get("storage", "10"), 
                str(specs.get("db_enabled", "")).lower(), 
                specs.get("db_type", "mysql"), 
                str(specs.get("web_enabled", "")).lower(), 
                specs.get("web_type", "nginx"), 
                cliente
            ]
            report_progress(oid, 10, "Configurando Stack Personalizado...")
            success = run_bash_script(SCRIPT_CUSTOM, args)

        if success:
            log(f"✅ ID {oid} instalado. Activando extras...", Colors.GREEN)
            enable_monitoring(cluster_profile)
            update_db_state(oid, "active")
            log(f"✨ ID {oid} LISTO PARA EL CLIENTE.", Colors.GREEN)
        else:
            if not shutdown_event.is_set(): # Solo reportar error si no fue por apagado manual
                report_progress(oid, 0, "Fallo crítico en instalación.")
                update_db_state(oid, "error")
                log(f"❌ ID {oid} falló.", Colors.RED)

    except Exception as e:
        log(f"Excepción crítica en worker {oid}: {e}", Colors.RED)
    finally:
        final_path = json_file.replace(".json", ".json.procesado")
        try: shutil.move(processing_file, final_path)
        except: pass

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777) 
    except: pass
    
    # Capturar señales de muerte
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    log(f"=== ORQUESTADOR PYTHON V7 (KAMIKAZE) ===", Colors.BLUE)

    # ThreadPoolExecutor no tiene modo "daemon", así que lo gestionamos manualmente
    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        while not shutdown_event.is_set():
            files = glob.glob(os.path.join(BUZON, "orden_*.json"))
            for json_file in files:
                if shutdown_event.is_set(): break
                executor.submit(process_order, json_file)
            
            # Espera fraccionada para respuesta rápida
            for _ in range(10):
                if shutdown_event.is_set(): break
                time.sleep(0.1)
        
        # Si salimos del bucle, es que nos han matado. Cancelamos todo.
        executor.shutdown(wait=False, cancel_futures=True)

if __name__ == "__main__":
    main()