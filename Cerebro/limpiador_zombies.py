#!/usr/bin/env python3
import os
import subprocess
import shutil
import json
import sys

# ==========================================
# EXTERMINADOR DE ZOMBIES V4 (NUCLEAR)
# ==========================================

TARGET_PREFIXES = [
    "sylo-cliente-", 
    "ClienteCustom", 
    "ClienteDB", 
    "ClienteOro", 
    "ClientePlata", 
    "ClienteWeb",
    "ClienteBronce"
]

def get_real_home():
    """Obtiene el HOME real incluso si se ejecuta con sudo"""
    if os.geteuid() == 0:
        user = os.environ.get('SUDO_USER')
        if user:
            return os.path.expanduser(f"~{user}")
    return os.path.expanduser("~")

def clean_minikube_config(home, profile_name):
    """Borra el perfil del archivo de registro interno de Minikube"""
    config_path = os.path.join(home, ".minikube", "config", "config.json")
    if not os.path.exists(config_path): return

    try:
        with open(config_path, 'r') as f:
            data = json.load(f)
        
        if profile_name in data:
            del data[profile_name]
            with open(config_path, 'w') as f:
                json.dump(data, f, indent=4)
            print(f"   🧠 Memoria Minikube borrada para: {profile_name}")
    except Exception as e:
        print(f"   ⚠️ Error editando config.json: {e}")

def force_delete(profile, home):
    print(f"🔥 ANIQUILANDO ZOMBIE: {profile}")
    
    # 1. Intento oficial (para que limpie redes si puede)
    subprocess.run(f"minikube delete -p {profile}", shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    
    # 2. Borrado Docker (Fuerza bruta)
    subprocess.run(f"docker rm -f {profile}", shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    
    # 3. Borrado de carpeta física
    profile_path = os.path.join(home, ".minikube", "profiles", profile)
    if os.path.exists(profile_path):
        try:
            shutil.rmtree(profile_path)
            print(f"   📂 Carpeta eliminada: {profile}")
        except: pass

    # 4. CIRUGÍA: Borrar del config.json (Esto evita que reaparezca en la lista)
    clean_minikube_config(home, profile)

def main():
    home = get_real_home()
    print(f"💀 ESCANEANDO ZOMBIES (Modo Nuclear)...")

    # Obtener lista oficial de Minikube (incluso los rotos)
    try:
        raw_list = subprocess.check_output("minikube profile list -o json", shell=True, text=True)
        # Minikube a veces devuelve texto basura antes del JSON, buscamos la primera {
        json_start = raw_list.find('{')
        if json_start != -1:
            data = json.loads(raw_list[json_start:])
            valid_profiles = data.get('valid', [])
            invalid_profiles = data.get('invalid', [])
            all_profiles_struct = valid_profiles + invalid_profiles
            minikube_known_names = [p['Name'] for p in all_profiles_struct]
        else:
            minikube_known_names = []
    except:
        minikube_known_names = []

    # Obtener contenedores Docker reales vivos
    try:
        docker_out = subprocess.check_output("docker ps -a --format '{{.Names}}'", shell=True, text=True)
        running_containers = docker_out.split('\n')
    except:
        running_containers = []

    zombies_count = 0

    # CASO A: Minikube cree que existe, pero Docker dice que NO (o está roto)
    for mk_name in minikube_known_names:
        is_target = any(mk_name.startswith(prefix) for prefix in TARGET_PREFIXES)
        if is_target:
            # Si no está en Docker, es un zombie confirmado
            if mk_name not in running_containers:
                force_delete(mk_name, home)
                zombies_count += 1
            # Si está en "invalid" list de minikube, es zombie
            elif any(p['Name'] == mk_name for p in data.get('invalid', [])):
                force_delete(mk_name, home)
                zombies_count += 1

    # CASO B: Carpeta huérfana en disco (que ni Minikube lista ya)
    profiles_dir = os.path.join(home, ".minikube", "profiles")
    if os.path.exists(profiles_dir):
        for folder in os.listdir(profiles_dir):
            is_target = any(folder.startswith(prefix) for prefix in TARGET_PREFIXES)
            if is_target and folder not in minikube_known_names:
                print(f"🧟 ZOMBIE HUÉRFANO DETECTADO: {folder}")
                force_delete(folder, home)
                zombies_count += 1

    if zombies_count == 0:
        print("✅ Sistema limpio. No se detectaron zombies.")
    else:
        print(f"✅ PURGA NUCLEAR COMPLETADA. {zombies_count} eliminados.")

if __name__ == "__main__":
    main()