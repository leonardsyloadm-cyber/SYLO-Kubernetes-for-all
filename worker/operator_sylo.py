#!/usr/bin/env python3
import sys, os, subprocess, time, json, datetime, threading, signal, shutil, glob, codecs

# --- CONFIGURACIÓN ---
sys.stdout.reconfigure(line_buffering=True)
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")
API_URL = "http://172.17.0.1:8001/api/clientes" 

status_lock = threading.Lock()
cmd_lock = threading.Lock()
shutdown_event = threading.Event()

# --- LOGS ---
C_RESET="\033[0m"; C_CYAN="\033[96m"; C_YELLOW="\033[93m"; C_GREEN="\033[92m"; C_RED="\033[91m"; C_GREY="\033[90m"
def log(msg, color=C_CYAN): print(f"{color}[{datetime.datetime.now().strftime('%H:%M:%S')}] {msg}{C_RESET}", flush=True)
def signal_handler(s, f): log("🛑 Operator detenido.", C_RED); shutdown_event.set(); sys.exit(0)

# --- EJECUTOR HABLADOR (VUELVEN LOS COMANDOS) ---
def run_command(cmd, timeout=300, silent=False):
    with cmd_lock:
        try:
            if not silent: 
                log(f"CMD > {cmd}", C_YELLOW)
            
            res = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=timeout)
            
            if res.returncode != 0:
                log(f"⚠️ ERROR ({res.returncode}): {res.stderr.strip()}", C_RED)
            elif not silent:
                out = res.stdout.strip()
                if out: log(f"   OUT: {out[:200]}...", C_GREY)
                
            return res.stdout.strip()
        except Exception as e: 
            log(f"❌ EXCEPCION CMD: {e}", C_RED)
            return ""

# --- API ---
try: import requests
except: pass

def report_progress(oid, tipo, status, pct, msg):
    log(f"📡 API REPORT: {status} ({pct}%)", C_GREY)
    try: 
        requests.post(f"{API_URL}/reportar/progreso", json={
            "id_cliente": int(oid), "tipo": tipo, "status_text": status, "percent": int(pct), "msg": str(msg)
        }, timeout=2)
    except: pass

def report_backups(oid):
    files = glob.glob(os.path.join(BUZON, f"backup_v{oid}_*.tar.gz"))
    backups = []
    for f in sorted(files, reverse=True):
        try:
            p = os.path.basename(f).split('_')
            if len(p)>=5: backups.append({"file":os.path.basename(f), "name":p[3], "type":p[2], "date":"Hoy"})
        except: pass
    try: requests.post(f"{API_URL}/reportar/lista_backups", json={"id_cliente": int(oid), "backups": backups}, timeout=2)
    except: pass

# --- UTILS ---
def wait_pods(prof, txt, retries=5):
    log(f"🔎 Buscando pods '{txt}'...", C_CYAN)
    for i in range(retries):
        raw = run_command(f"minikube -p {prof} kubectl -- get pods --no-headers", silent=True)
        for l in raw.splitlines():
            if txt in l and "Running" in l: 
                pod = l.split()[0]
                log(f"✅ Pod detectado: {pod}", C_GREEN)
                return [pod]
        time.sleep(1)
    return []

# ==========================================
# 🔥 LÓGICA DE TRABAJO (VERBOSE) 🔥
# ==========================================

def update_web(oid, prof, content, is_restore=False):
    t_type = "backup" if is_restore else "web"
    t_stat = "restoring" if is_restore else "web_updating"
    log(f"🔧 ACTUALIZANDO CONTENIDO WEB (Cliente {oid})...", C_GREEN)
    report_progress(oid, t_type, t_stat, 20, "Aplicando HTML...")
    
    try:
        web = wait_pods(prof, "web", 5)
        if not web: raise Exception("No hay pod web activo")
        
        # Guardar local
        tf = f"/tmp/idx_{oid}.html"
        with open(tf, "w", encoding="utf-8") as f: f.write(content)
        
        log(f"📦 Inyectando HTML en {web[0]}...", C_CYAN)
        run_command(f"minikube -p {prof} kubectl -- cp {tf} {web[0]}:/usr/share/nginx/html/index.html", silent=False)
        
        try: os.remove(tf)
        except: pass
        
        report_progress(oid, t_type, "web_completed" if not is_restore else "completed", 100, "Online")
        log("✅ WEB ACTUALIZADA CORRECTAMENTE", C_GREEN)
    except Exception as e:
        log(f"❌ Error Web: {e}", C_RED)
        report_progress(oid, t_type, "error", 0, str(e))

def do_backup(oid, prof, name):
    log(f"📦 INICIANDO COPIA DE SEGURIDAD FULL: {name}", C_GREEN)
    report_progress(oid, "backup", "creating", 10, "Iniciando...")
    
    ts = datetime.datetime.now().strftime('%Y%m%d%H%M%S')
    final_tar = os.path.join(BUZON, f"backup_v{oid}_full_{name}_{ts}.tar.gz")
    
    try:
        web = wait_pods(prof, "web", 5)
        if web:
            report_progress(oid, "backup", "creating", 40, "Empaquetando recursos...")
            log(f"📡 Extrayendo archivos desde el Pod...", C_CYAN)
            
            # Pipe: tar remoto -> gzip local
            cmd = f"minikube -p {prof} kubectl -- exec {web[0]} -- tar cf - -C /usr/share/nginx/html . | gzip > {final_tar}"
            run_command(cmd, silent=False)
            
            report_progress(oid, "backup", "completed", 100, "Completado")
            report_backups(oid)
            log(f"✅ BACKUP FINALIZADO: {os.path.basename(final_tar)}", C_GREEN)
        else:
            raise Exception("Imposible hacer backup: Pod Web no encontrado")
    except Exception as e:
        log(f"❌ Error Backup: {e}", C_RED)
        report_progress(oid, "backup", "error", 0, str(e))

def do_restore(oid, prof, fn):
    log(f"♻️ INICIANDO RESTAURACIÓN: {fn}", C_GREEN)
    report_progress(oid, "backup", "restoring", 10, "Preparando...")
    
    tar_path = os.path.join(BUZON, fn)
    if not os.path.exists(tar_path):
        log(f"❌ El archivo {fn} no existe en el buzón", C_RED)
        return

    try:
        web = wait_pods(prof, "web", 5)
        if web:
            report_progress(oid, "backup", "restoring", 40, "Borrando actual...")
            # Limpiar destino
            run_command(f"minikube -p {prof} kubectl -- exec {web[0]} -- sh -c 'rm -rf /usr/share/nginx/html/*'", silent=False)
            
            report_progress(oid, "backup", "restoring", 60, "Inyectando backup...")
            # Copiar y descomprimir
            run_command(f"minikube -p {prof} kubectl -- cp {tar_path} {web[0]}:/tmp/restore.tar.gz", silent=False)
            run_command(f"minikube -p {prof} kubectl -- exec {web[0]} -- tar xzf /tmp/restore.tar.gz -C /usr/share/nginx/html/", silent=False)
            
            report_progress(oid, "backup", "completed", 100, "Éxito")
            log("✅ RESTAURACIÓN COMPLETADA", C_GREEN)
        else:
            raise Exception("Pod Web no activo para restaurar")
    except Exception as e:
        log(f"❌ Error Restore: {e}", C_RED)
        report_progress(oid, "backup", "error", 0, str(e))

# --- BUCLES ---
def task_loop():
    while not shutdown_event.is_set():
        for f in glob.glob(os.path.join(BUZON, "accion_*.json")):
            try:
                log(f"📨 ORDEN DETECTADA: {os.path.basename(f)}", C_YELLOW)
                with open(f) as fh: d = json.load(fh)
                os.remove(f)
                oid = d.get('id_cliente'); act = str(d.get('action') or d.get('accion')).upper(); prof = f"sylo-cliente-{oid}"
                t = threading.Thread(target=process, args=(oid, prof, act, d))
                t.start()
            except: pass
        time.sleep(0.5)

def process(oid, prof, act, d):
    if act == "BACKUP": do_backup(oid, prof, d.get('backup_name'))
    elif act == "RESTORE_BACKUP": do_restore(oid, prof, d.get('filename_to_restore'))
    elif act == "UPDATE_WEB": update_web(oid, prof, d.get('html_content'))
    elif act == "DELETE_BACKUP": 
        p = os.path.join(BUZON, d.get('filename_to_delete'))
        if os.path.exists(p): os.remove(p); report_backups(oid)

def metrics_loop():
    while not shutdown_event.is_set():
        try:
            out = run_command("docker ps --format '{{.Names}}'", silent=True)
            for l in out.splitlines():
                if "sylo-cliente-" in l:
                    oid = l.replace("sylo-cliente-", "")
                    
                    # DOMINIO REAL DESDE DB
                    sub = run_command(f'docker exec -i kylo-main-db mysql -N -usylo_app -psylo_app_pass -D kylo_main_db -e "SELECT subdomain FROM order_specs WHERE order_id={oid}"', silent=True).strip()
                    url = f"http://{sub}.sylobi.org" if sub and "NULL" not in sub else f"http://cliente{oid}.sylobi.org"
                    
                    # Reportar a API
                    requests.post(f"{API_URL}/reportar/metricas", json={
                        "id_cliente": int(oid), 
                        "metrics": {"cpu":12,"ram":25}, 
                        "ssh_cmd": "root@sylo", 
                        "web_url": url
                    }, timeout=1)
                    report_backups(oid)
        except: pass
        time.sleep(2)

if __name__ == "__main__":
    signal.signal(signal.SIGTERM, signal_handler)
    log("===============================================", C_GREEN)
    log("=== OPERATOR V43 (LOUD + REAL DOMAINS)      ===", C_GREEN)
    log("===============================================", C_GREEN)
    t1 = threading.Thread(target=task_loop, daemon=True)
    t2 = threading.Thread(target=metrics_loop, daemon=True)
    t1.start(); t2.start()
    while True: time.sleep(1)