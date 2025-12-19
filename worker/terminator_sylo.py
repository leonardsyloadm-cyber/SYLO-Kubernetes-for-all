#!/usr/bin/env python3
import os
import time
import json
import glob
import subprocess
import sys

# ==========================================
# SYLO TERMINATOR V4 - FIXED TARGETING
# ==========================================
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")

DB_CONTAINER = "kylo-main-db"
DB_USER = "sylo_app"
DB_PASS = "sylo_app_pass"
DB_NAME = "kylo_main_db"

HOME = os.path.expanduser("~")
MINIKUBE_PROFILES = os.path.join(HOME, ".minikube", "profiles")

class Colors:
    RED = '\033[91m'
    MAGENTA = '\033[95m'
    GREEN = '\033[92m'
    NC = '\033[0m'

def log(message, color=Colors.NC):
    print(f"{color}[TERMINATOR] {message}{Colors.NC}")
    sys.stdout.flush()

def run_shell(cmd):
    subprocess.run(cmd, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def update_db_cancelled(oid):
    sql = f"UPDATE orders SET status='cancelled' WHERE id={oid};"
    cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "-e", sql]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass

    log("VIGILANCIA ACTIVA... Esperando objetivos.", Colors.MAGENTA)

    while True:
        # Busca cualquier archivo que contenga 'terminate' en el nombre
        # El dashboard genera: accion_33_terminate_1234567.json
        terminate_files = glob.glob(os.path.join(BUZON, "*terminate*.json"))

        for action_file in terminate_files:
            try:
                log(f"Detectada orden: {os.path.basename(action_file)}", Colors.RED)
                
                with open(action_file, 'r') as f: data = json.load(f)
                oid = data.get("id")
                profile = f"sylo-cliente-{oid}"

                log(f"💥 ELIMINANDO KUBERNETE #{oid} ({profile})...", Colors.RED)

                # 1. Borrar Minikube Profile (Fuerza bruta)
                subprocess.run(["minikube", "delete", "-p", profile], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                
                # 2. Borrar Contenedor Docker (Doble seguridad)
                run_shell(f"docker rm -f {profile}")
                
                # 3. Actualizar Base de Datos
                update_db_cancelled(oid)
                
                # 4. Limpieza de archivos de estado del Operator
                for f in glob.glob(os.path.join(BUZON, f"*_{oid}.*")):
                    try: os.remove(f)
                    except: pass
                
                # 5. Borrar la orden de terminación
                os.remove(action_file)

                log(f"💀 Objetivo #{oid} ELIMINADO TOTALMENTE.", Colors.GREEN)

            except Exception as e:
                log(f"Error procesando: {e}", Colors.RED)
                try: os.remove(action_file) # Si falla, borramos para no buclear
                except: pass

        time.sleep(2)

if __name__ == "__main__":
    try: main()
    except KeyboardInterrupt: pass