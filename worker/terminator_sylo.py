#!/usr/bin/env python3
import os
import time
import json
import glob
import subprocess
import sys

# ==========================================
# SYLO TERMINATOR V3 - NUCLEAR SILENCIOSO
# ==========================================
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")

DB_CONTAINER = "kylo-main-db"
DB_USER = "sylo_app"
DB_PASS = "sylo_app_pass"
DB_NAME = "kylo_main_db"

HOME = os.path.expanduser("~")
MINIKUBE_PROFILES = os.path.join(HOME, ".minikube", "profiles")
MINIKUBE_MACHINES = os.path.join(HOME, ".minikube", "machines")
MINIKUBE_CONFIG = os.path.join(HOME, ".minikube", "config", "config.json")

class Colors:
    RED = '\033[0;31m'
    MAGENTA = '\033[0;35m'
    GREEN = '\033[0;32m'
    NC = '\033[0m'

def log(message, color=Colors.NC):
    print(f"{color}{message}{Colors.NC}")
    sys.stdout.flush()

def run_shell(cmd):
    subprocess.run(cmd, shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def run_sudo_rm(path_pattern):
    cmd = f"sudo rm -rf {path_pattern}"
    subprocess.run(cmd, shell=True, stderr=subprocess.DEVNULL)

def update_db_cancelled(oid):
    sql = f"UPDATE orders SET status='cancelled' WHERE id={oid};"
    cmd = ["docker", "exec", "-i", DB_CONTAINER, "mysql", f"-u{DB_USER}", f"-p{DB_PASS}", "-D", DB_NAME, "--silent", "-e", sql]
    subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def find_profile_by_id(oid):
    try:
        result = subprocess.run(["minikube", "profile", "list"], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True)
        target_suffix = f"-{oid}"
        for line in result.stdout.split('\n'):
            parts = line.split()
            for part in parts:
                if part.endswith(target_suffix):
                    return part
    except: pass
    return None

def main():
    if not os.path.exists(BUZON): os.makedirs(BUZON)
    try: os.chmod(BUZON, 0o777)
    except: pass

    log("=== 💀 TERMINATOR ACTIVO (MODO SILENCIOSO) ===", Colors.MAGENTA)

    while True:
        # 1. PROTOCOLO HIROSHIMA
        hiroshima_signal = os.path.join(BUZON, "HIROSHIMA_EVENT.signal")
        if os.path.exists(hiroshima_signal):
            log("\n☢️  PROTOCOLO HIROSHIMA ACTIVADO", Colors.RED)
            run_shell("docker ps -a --format '{{.Names}}' | grep -E '^(Cliente|k8s|minikube)' | xargs -r docker rm -f")
            run_sudo_rm(f"{MINIKUBE_PROFILES}/Cliente*")
            run_sudo_rm(f"{MINIKUBE_MACHINES}/Cliente*")
            run_sudo_rm(MINIKUBE_CONFIG)
            run_shell(f"rm -f {BUZON}/accion_*.json")
            os.remove(hiroshima_signal)
            log("✅ ZONA CERO LIMPIA.\n", Colors.GREEN)

        # 2. FRANCOTIRADOR INDIVIDUAL (ELIMINAR UN CLIENTE)
        terminate_files = glob.glob(os.path.join(BUZON, "accion_*_terminate.json"))

        for action_file in terminate_files:
            try:
                with open(action_file, 'r') as f: data = json.load(f)
                oid = data.get("id")

                log(f"☠️  Eliminando Pedido #{oid}", Colors.RED)

                profile = find_profile_by_id(oid)
                if profile:
                    subprocess.run(["minikube", "delete", "-p", profile], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    run_sudo_rm(os.path.join(MINIKUBE_PROFILES, profile))

                run_shell(f"docker ps -a --format '{{{{.Names}}}}' | grep '\\-{oid}$' | xargs -r docker rm -f")
                update_db_cancelled(oid)
                os.remove(action_file) # Borramos el archivo JSON

                # También limpiamos los archivos de estado del Operator para que el Dashboard deje de mostrar cosas
                try: os.remove(os.path.join(BUZON, f"status_{oid}.json")) 
                except: pass
                try: os.remove(os.path.join(BUZON, f"web_source_{oid}.html")) 
                except: pass

                log(f"   ✅ Objetivo #{oid} Neutralizado", Colors.RED)

            except Exception as e:
                try: os.remove(action_file)
                except: pass

        time.sleep(2)

if __name__ == "__main__":
    try: main()
    except KeyboardInterrupt: pass