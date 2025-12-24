#!/usr/bin/env python3
import os
import time
import json
import glob
import re
import sys
import requests
from datetime import datetime

# ================= CONFIGURACIÓN =================
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")

OLLAMA_URL = "http://localhost:11434/api/generate"
MODEL_NAME = "qwen2.5:1.5b" 

print(f"[BRAIN] 🧠 Sylo Brain v12 (Precision UI) Iniciado.")

# ================= HERRAMIENTAS DE ESTADO =================

def update_chat_status(oid, message):
    status_file = os.path.join(BUZON, f"chat_status_{oid}.json")
    try:
        with open(status_file, 'w') as f:
            json.dump({"status": message}, f)
    except: pass

def clear_chat_status(oid):
    status_file = os.path.join(BUZON, f"chat_status_{oid}.json")
    if os.path.exists(status_file):
        try: os.remove(status_file)
        except: pass

# ================= HERRAMIENTAS TÉCNICAS =================

def clean_ssh_pass(raw_text):
    if "Pass:" in raw_text:
        match = re.search(r'Pass:\s*([^\s\[\]]+)', raw_text)
        if match: return match.group(1)
    return raw_text

def get_time_greeting():
    h = datetime.now().hour
    if 6 <= h < 12: return "Buenos días"
    elif 12 <= h < 20: return "Buenas tardes"
    return "Buenas noches"

def ask_ollama(user_msg, tech_data, plan_data, limit_backups, oid):
    try:
        update_chat_status(oid, "🔍 Localizando elementos en pantalla...")
        time.sleep(0.5)

        # ---------------------------------------------------------
        # MAPA DE PRECISIÓN (ESTO ES LO QUE ARREGLA TU PROBLEMA)
        # ---------------------------------------------------------
        ui_map = """
        [MAPA EXACTO DEL DASHBOARD - ÚSALO PARA GUIAR AL USUARIO]
        
        1. ZONA IZQUIERDA (DATOS):
           - "Accesos de Sistema": Es la tarjeta negra en el centro-izda. Contiene: IP, Usuario Root, Contraseña y Datos MySQL.
           - "Métricas": Las gráficas de CPU y RAM están arriba a la izquierda.

        2. ZONA DERECHA (ACCIONES):
           - "Despliegue Web": Parte superior derecha.
             * Botón "Ver Sitio Web" (Azul): Abre la web.
             * Botón "Editar" (Gris): Abre el editor de código.
             * Botón "Subir" (Gris): Para subir archivos .html.
           
           - "Control de Energía": Parte media derecha.
             * Botón "Reiniciar" (Amarillo) / "Apagar" (Rojo) / "Encender" (Verde).

           - "Snapshots" (Backups): Parte inferior derecha.
             * Botón "Crear Snapshot": El único botón para hacer copias. NO dar comandos.
             * Lista de copias: Justo debajo del botón.
        """

        knowledge_base = """
        [PLANES SYLO]
        - Bronce (5€): 1 Core, 1GB RAM. Sin Web/DB.
        - Plata (15€): 2 Cores, 4GB RAM. Con MySQL.
        - Oro (30€): 4 Cores, 8GB RAM. Full Stack.
        """

        real_time_status = f"""
        [ESTADO ACTUAL DEL CLIENTE]
        - Plan: {plan_data.get('name')}
        - Web Activa: {'SÍ' if plan_data.get('has_web') else 'NO'}
        - CPU Actual: {tech_data.get('metrics', {}).get('cpu')}%
        - Backups: {tech_data.get('backups_count')}/{limit_backups}
        """

        system_prompt = f"""
        Eres SyloBot, el navegador de este Dashboard.
        
        {ui_map}
        {knowledge_base}
        {real_time_status}

        REGLAS DE RESPUESTA:
        1. Sé MUY CONCRETO con la ubicación. Ejemplo: "Usa el botón 'Editar' en la zona derecha, sección Despliegue Web".
        2. Si preguntan por contraseñas o IP: "Mira la tarjeta 'Accesos de Sistema' a tu izquierda".
        3. Si preguntan por Backups: "Botón 'Crear Snapshot' abajo a la derecha".
        4. Máximo 25 palabras.
        """

        payload = {
            "model": MODEL_NAME,
            "prompt": f"{system_prompt}\n\nPREGUNTA USUARIO: {user_msg}\n\nGUÍA VISUAL:",
            "stream": False,
            "keep_alive": "60m",
            "options": {"temperature": 0.1, "num_ctx": 4096, "num_predict": 50}
        }
        
        update_chat_status(oid, "✍️ Redactando instrucciones...")
        print(f"[AI] Procesando para #{oid}: '{user_msg}'...")
        
        r = requests.post(OLLAMA_URL, json=payload, timeout=90)
        
        clear_chat_status(oid)
        
        if r.status_code == 200:
            # Limpiamos comillas extra si la IA las pone
            return r.json()['response'].strip().replace('"', '')
        return "⚠️ Cerebro saturado."
        
    except Exception as e:
        clear_chat_status(oid)
        return "⚠️ Error IA."

# ================= CEREBRO PRINCIPAL =================

def analyze_intent(text, technical_status, user_plan_context, oid):
    text_clean = text.lower().strip()
    
    # DATOS
    metrics = technical_status.get('metrics', {'cpu': 0, 'ram': 0})
    raw_cmd = technical_status.get('ssh_cmd', 'No disponible')
    raw_pass = technical_status.get('ssh_pass', 'No disponible')
    ssh_pass = clean_ssh_pass(raw_pass)
    web_url = technical_status.get('web_url', None)
    backups_count = technical_status.get('backups_count', 0)
    
    plan_name = user_plan_context.get('name', 'Estándar')
    has_db = user_plan_context.get('has_db', False)
    has_web = user_plan_context.get('has_web', False)
    db_type = user_plan_context.get('db_type', 'MySQL')
    web_type = user_plan_context.get('web_type', 'Apache')
    
    limit_backups = 3
    if plan_name == 'Oro': limit_backups = 5
    elif plan_name == 'Plata': limit_backups = 4
    elif plan_name == 'Personalizado': limit_backups = 5 if (has_db and has_web) else 3

    # --- CARRIL RÁPIDO ---
    if text_clean == '1': return f"🔑 **SSH**\nCmd: `{raw_cmd}`\nUser: `root`\nPass: `{ssh_pass}`"
    if text_clean == '2': return f"🌐 **Web**\nURL: {web_url}" if has_web else "❌ Sin web."
    if text_clean == '3': return f"🗄️ **DB**\nHost: `mysql-master`\nPass: `{ssh_pass}`" if has_db else "❌ Sin DB."
    if text_clean == '4': return f"💾 **Backups**\n{backups_count}/{limit_backups} usados."
    if text_clean == '5': return f"📊 **Salud**\nCPU: {metrics.get('cpu')}% | RAM: {metrics.get('ram')}%"

    if text_clean in ['hola', 'buenas', 'menu', 'ayuda', 'opciones']:
        return (f"¡{get_time_greeting()}! 🤖\n"
                "**MENÚ RÁPIDO**\n"
                "1️⃣ SSH  |  2️⃣ Web\n"
                "3️⃣ DB   |  4️⃣ Backups\n"
                "5️⃣ Salud\n"
                "💡 *O pregúntame algo.*")

    if 'contraseña' in text_clean: return f"`{ssh_pass}`"
    
    # --- CARRIL IA (LENTO) ---
    return ask_ollama(text, technical_status, user_plan_context, limit_backups, oid)


# ================= BUCLE PRINCIPAL =================
def main_loop():
    while True:
        try:
            files = glob.glob(os.path.join(BUZON, "chat_request_*.json"))
            for req_file in files:
                try:
                    filename = os.path.basename(req_file)
                    oid = filename.split('_')[2].split('.')[0]
                    with open(req_file, 'r') as f:
                        data = json.load(f)
                        user_msg = data.get('msg', '')
                        plan_context = data.get('context_plan', {})
                    
                    print(f"[BRAIN] 📩 Cliente #{oid}: {user_msg}")
                    
                    tech_context = {}
                    st_path = os.path.join(BUZON, f"status_{oid}.json")
                    bk_path = os.path.join(BUZON, f"backups_list_{oid}.json")
                    
                    if os.path.exists(st_path):
                        with open(st_path) as f: tech_context.update(json.load(f))
                    if os.path.exists(bk_path):
                        with open(bk_path) as f: tech_context['backups_count'] = len(json.load(f))

                    reply = analyze_intent(user_msg, tech_context, plan_context, oid)
                    
                    resp_file = os.path.join(BUZON, f"chat_response_{oid}.json")
                    with open(resp_file, 'w') as f:
                        json.dump({"reply": reply, "timestamp": time.time()}, f)
                    
                    print(f"[BRAIN] 📤 Respondido.")
                    os.remove(req_file)
                    clear_chat_status(oid)

                except Exception as e:
                    try: 
                        os.remove(req_file)
                        clear_chat_status(oid)
                    except: pass
            time.sleep(0.5)
        except KeyboardInterrupt: break
        except Exception as e: 
            print(f"[BRAIN] Fatal: {e}")
            time.sleep(1)

if __name__ == "__main__":
    main_loop()