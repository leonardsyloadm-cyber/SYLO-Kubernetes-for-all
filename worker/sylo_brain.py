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
BUZON = os.path.join(BASE_DIR, "sylo-web", "buzon-pedidos")

MODEL_NAME = "qwen2.5:1.5b" 

# LISTA DE POSIBLES DIRECCIONES
POSSIBLE_URLS = [
    "http://127.0.0.1:11434/api/generate",
    "http://172.17.0.1:11434/api/generate",
    "http://host.docker.internal:11434/api/generate", 
    "http://0.0.0.0:11434/api/generate"
]

OLLAMA_URL = "http://172.17.0.1:11434/api/generate"

print(f"[BRAIN] 🧠 Sylo Brain v25 (GUARDIAN EDITION) Iniciado.")

# ================= 🛡️ FILTRO DE SEGURIDAD (CENSURA) =================
# Palabras que activan el bloqueo inmediato (sin preguntar a la IA)
FORBIDDEN_TOPICS = [
    "israel", "palestina", "guerra", "matar", "muerte", "violencia", 
    "mujer", "feminismo", "machismo", "política", "religión", "dios",
    "gobierno", "votar", "partido", "sexo", "porno", "droga", "armas",
    "terrorismo", "hamas", "ucrania", "rusia", "opinas", "racismo",
    "homofobia", "gay", "trans", "lgtb", "asesinato", "suicidio"
]

def is_safe(text):
    t = text.lower()
    for word in FORBIDDEN_TOPICS:
        if word in t: return False
    return True

# ================= 🔌 AUTO-CONEXIÓN =================
def find_ollama():
    global OLLAMA_URL
    print("[INIT] 🔍 Buscando a Ollama (timeout 5s)...")
    test_payload = {"model": MODEL_NAME, "prompt": ".", "stream": False, "num_predict": 1}
    
    for url in POSSIBLE_URLS:
        try:
            print(f"       👉 {url} ...", end="")
            r = requests.post(url, json=test_payload, timeout=5)
            if r.status_code == 200:
                print(" ✅ OK!")
                OLLAMA_URL = url
                return True
            print(f" ❌ ({r.status_code})")
        except:
            print(" ❌")
            
    print("[FATAL] 🛑 Ollama no responde en ninguna IP.")
    return False

if not find_ollama(): print("[WARN] Reintentando en segundo plano...")

# ================= 📚 LA BIBLIA DE SYLO (MANUAL AMPLIADO) =================
SYLO_DOCS = """
[MANUAL TÉCNICO OFICIAL SYLO]
¡IMPORTANTE! Si la respuesta no está aquí, di: "No tengo esa información, contacta con soporte humano".

1. CÓMO EDITAR LA WEB (SUBIR ARCHIVOS):
   - Opción A (Fácil): En el Dashboard, pulsa el botón 'Editar' o 'Subir' dentro del bloque 'Despliegue Web'. Eso abre el Gestor de Archivos Web.
   - Opción B (Pro): Usa un cliente FTP (como FileZilla). Los datos (Host, User, Pass) están en la tarjeta 'Accesos de Sistema'.
   - Opción C (Experto): Vía terminal SSH (vim/nano).

2. COPIAS DE SEGURIDAD (SNAPSHOTS):
   - NO se hacen por comandos.
   - SE HACEN: En el panel derecho, sección 'Snapshots', botón 'Crear Snapshot'.
   - LÍMITES: Bronce (0), Plata (3), Oro (5).

3. BACKUPS EN LA NUBE (AWS S3):
   - Además de snapshots locales, puedes subir copias a la nube de AWS.
   - En la sección 'Cloud Backups', verás los archivos subidos.
   - Son independientes de las copias locales. Si borras una local, la de la nube sigue ahí.
   - Para subir: Pulsa el icono de nube en la lista de backups locales. (Requiere Plan Oro o Addon Cloud).

4. BASE DE DATOS:
   - Planes Plata y Oro incluyen MySQL.
   - Para ver la contraseña: Mira la tarjeta 'Accesos de Sistema' en el Dashboard.

5. RECUPERACIÓN DE DESASTRES (AMI PANIC BUTTON):
   - En caso de emergencia extrema (hackeo total, destrucción de datos), usa el 'PANIC BUTTON' en la zona de peligro.
   - Esto crea una IMAGEN COMPLETA (AMI) del servidor en AWS.
   - Tiene un COOLDOWN DE 24 HORAS. No se puede usar más de una vez al día.
   - Cuesta dinero en AWS, úsalo con responsabilidad.

6. ESTADO DEL SERVIDOR:
   - Si la CPU está al 100%, recomienda reiniciar usando el botón 'Reiniciar' en 'Control de Energía'.
"""

# ================= HERRAMIENTAS BASE =================
def update_chat_status(oid, message):
    try:
        with open(os.path.join(BUZON, f"chat_status_{oid}.json"), 'w') as f:
            json.dump({"status": message}, f)
    except: pass

def clear_chat_status(oid):
    try:
        p = os.path.join(BUZON, f"chat_status_{oid}.json")
        if os.path.exists(p): os.remove(p)
    except: pass

def get_memory_file(oid): return os.path.join(BUZON, f"chat_memory_{oid}.json")

def load_conversation_history(oid):
    try:
        with open(get_memory_file(oid), 'r') as f: return json.load(f)
    except: return []

def save_conversation_history(oid, user_msg, ai_reply):
    history = load_conversation_history(oid)
    history.append({"u": user_msg, "a": ai_reply})
    if len(history) > 3: history = history[-3:] 
    try:
        with open(get_memory_file(oid), 'w') as f: json.dump(history, f)
    except: pass

def format_history(history):
    return "\n".join([f"U: {h['u']} | AI: {h['a']}" for h in history]) if history else "N/A"

def clean_ssh_pass(raw_text):
    match = re.search(r'Pass:\s*([^\s\[\]]+)', raw_text)
    return match.group(1) if match else raw_text

# ================= COMUNICACIÓN IA =================
def ask_ollama(user_msg, tech_data, plan_data, oid):
    global OLLAMA_URL
    if not OLLAMA_URL:
        if not find_ollama(): return "⚠️ Error: Ollama desconectado."

    try:
        update_chat_status(oid, "🧠 Consultando manuales...")
        history = load_conversation_history(oid)
        
        system_prompt = f"""
        INSTRUCCIONES SUPREMAS:
        1. Eres SyloBot, un asistente TÉCNICO DE SERVIDORES.
        2. USA SOLO LA DOCUMENTACIÓN DE ABAJO. NO INVENTES.
        3. Si preguntan algo que no está en el manual, di: "Solo gestiono soporte técnico de Sylo."
        4. Sé breve (máx 40 palabras).

        {SYLO_DOCS}
        
        ESTADO ACTUAL DEL CLIENTE:
        - Plan: {plan_data.get('name')}
        - Backups: {tech_data.get('backups_count', 0)}
        """

        payload = {
            "model": MODEL_NAME,
            "prompt": f"{system_prompt}\n\nHISTORIAL:{format_history(history)}\n\nUSUARIO: {user_msg}\nRESPUESTA:",
            "stream": False,
            "keep_alive": "60m",
            "options": {"temperature": 0.0, "num_predict": 120} # Temp 0.0 para máxima precisión (cero creatividad)
        }
        
        r = requests.post(OLLAMA_URL, json=payload, timeout=180)
        
        if r.status_code == 200:
            reply = r.json().get('response', '').strip().replace('"', '')
            save_conversation_history(oid, user_msg, reply)
            return reply
        elif r.status_code == 404:
            return f"⚠️ Error: Falta modelo {MODEL_NAME}."
            
        return f"⚠️ Error IA: {r.status_code}"
        
    except Exception as e:
        OLLAMA_URL = "http://172.17.0.1:11434/api/generate"
        return "⚠️ Error conexión. Reintentando..."

# ================= CEREBRO PRINCIPAL =================
def analyze_intent(text, technical_status, user_plan_context, oid):
    t = text.lower().strip()

    # 🚨 1. FILTRO DE SEGURIDAD (PRIORIDAD MÁXIMA)
    if not is_safe(t):
        return "🚫 Soy un asistente técnico. No respondo sobre política, religión o temas sociales."

    # Datos
    cpu = technical_status.get('metrics', {}).get('cpu', 0)
    ram = technical_status.get('metrics', {}).get('ram', 0)
    ssh_pass = clean_ssh_pass(technical_status.get('ssh_pass', '...'))
    web_url = technical_status.get('web_url', None)
    bk_count = technical_status.get('backups_count', 0)

    # 2. INTENCIÓN COMPLEJA -> IA
    # Añadimos palabras clave de edición web para que vaya a la IA con el nuevo manual
    complex_triggers = ['como', 'cómo', 'que', 'qué', 'donde', 'puedo', 'ayuda', 'editar', 'subir', 'ficheros', 'archivos', 'web', 'ftp']
    if any(q in t for q in complex_triggers):
        return ask_ollama(text, technical_status, user_plan_context, oid)

    # 3. ATAJOS RÁPIDOS
    if any(w in t for w in ['metric', 'métrica', 'cpu', 'ram']):
        return f"📊 **Estado**\nCPU: {cpu}% | RAM: {ram}%"
    if any(w in t for w in ['ssh', 'acceder', 'pass']):
        return f"🔑 **Acceso**\nPass: `{ssh_pass}`"
    if any(w in t for w in ['backup', 'copia']):
        return f"💾 **Backups**\n{bk_count} creados."
    if any(w in t for w in ['hola', 'buenas']):
        return "👋 Hola. Soy SyloBot, tu técnico."

    return ask_ollama(text, technical_status, user_plan_context, oid)

# ================= BUCLE PRINCIPAL =================
def main_loop():
    print("[BRAIN] Escuchando...")
    while True:
        try:
            files = glob.glob(os.path.join(BUZON, "chat_request_*.json"))
            for req_file in files:
                try:
                    filename = os.path.basename(req_file)
                    try: oid = filename.split('_')[3]
                    except: 
                        match = re.search(r"req_(\d+)_", filename)
                        oid = match.group(1) if match else None
                    if not oid: os.remove(req_file); continue

                    with open(req_file, 'r') as f: data = json.load(f)
                    print(f"[BRAIN] 📩 Cliente #{oid}: {data.get('msg', '')}")
                    
                    tech_context = {}
                    try:
                        with open(os.path.join(BUZON, f"status_{oid}.json")) as f: tech_context.update(json.load(f))
                        with open(os.path.join(BUZON, f"backups_list_{oid}.json")) as f: tech_context['backups_count'] = len(json.load(f))
                    except: pass

                    reply = analyze_intent(data.get('msg', ''), tech_context, data.get('context_plan', {}), oid)
                    
                    with open(os.path.join(BUZON, f"chat_response_{oid}.json"), 'w') as f:
                        json.dump({"reply": reply, "timestamp": time.time()}, f)
                    
                    print(f"[BRAIN] 📤 Respondido.")
                    os.remove(req_file)
                    clear_chat_status(oid)

                except Exception as e:
                    print(f"[ERROR] {e}"); 
                    try: os.remove(req_file); clear_chat_status(oid)
                    except: pass
            time.sleep(0.5)
        except Exception: time.sleep(1)

if __name__ == "__main__":
    main_loop()