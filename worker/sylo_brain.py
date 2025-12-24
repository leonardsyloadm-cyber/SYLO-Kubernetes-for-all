#!/usr/bin/env python3
import os
import time
import json
import glob
import re
import sys
import random
from datetime import datetime

# ================= CONFIGURACIÓN =================
WORKER_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(WORKER_DIR)
BUZON = os.path.join(BASE_DIR, "buzon-pedidos")

print(f"[BRAIN] 🧠 Sylo Brain v6.0 (Titan Edition) Iniciado.")
print(f"[BRAIN] Modo: Pasivo (Esperando datos inyectados por PHP)")

# ================= HERRAMIENTAS =================

def clean_ssh_pass(raw_text):
    if "Pass:" in raw_text:
        match = re.search(r'Pass:\s*([^\s\[\]]+)', raw_text)
        if match: return match.group(1)
    return raw_text

def get_time_greeting():
    hour = datetime.now().hour
    if 6 <= hour < 12: return "Buenos días"
    elif 12 <= hour < 20: return "Buenas tardes"
    else: return "Buenas noches"

def extract_ip_from_url(url):
    if not url: return "No detectada"
    # Intenta sacar la IP de http://192.168.1.50:3000
    match = re.search(r'(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})', url)
    return match.group(1) if match else "Localhost"

# ================= CEREBRO CENTRAL (LÓGICA DE INTENCIÓN) =================

def analyze_intent(text, technical_status, user_plan_context):
    text = text.lower().strip()
    
    # --- 1. DESEMPAQUETAR DATOS TÉCNICOS ---
    metrics = technical_status.get('metrics', {'cpu': 0, 'ram': 0})
    raw_cmd = technical_status.get('ssh_cmd', 'No disponible')
    raw_pass = technical_status.get('ssh_pass', 'No disponible')
    ssh_pass = clean_ssh_pass(raw_pass)
    web_url = technical_status.get('web_url', None)
    web_ip = extract_ip_from_url(web_url)
    backups_count = technical_status.get('backups_count', 0)
    
    # Intentar extraer usuario del comando ssh (ej: ssh cliente@ip -> cliente)
    ssh_user = "root"
    if "@" in raw_cmd:
        try: ssh_user = raw_cmd.split('ssh ')[1].split('@')[0]
        except: pass

    # --- 2. DESEMPAQUETAR CONTEXTO DEL PLAN ---
    plan_name = user_plan_context.get('name', 'Estándar')
    has_db = user_plan_context.get('has_db', False)
    has_web = user_plan_context.get('has_web', False)
    db_type = user_plan_context.get('db_type', 'MySQL')
    web_type = user_plan_context.get('web_type', 'Apache')
    
    # Cálculo de límites
    limit_backups = 3
    if plan_name == 'Oro': limit_backups = 5
    elif plan_name == 'Plata': limit_backups = 4
    elif plan_name == 'Personalizado': limit_backups = 5 if (has_db and has_web) else 3

    # ==============================================================================
    #                               BLOQUE 1: MENÚ RÁPIDO (1-5)
    # ==============================================================================
    if text == '1':
        return f"🔑 **Acceso SSH Completo**\nComando: `{raw_cmd}`\nUsuario: `{ssh_user}`\nContraseña: `{ssh_pass}`"
    if text == '2':
        return f"🌐 **Acceso Web**\nURL: {web_url}" if has_web and web_url else f"❌ Plan **{plan_name}** sin web activa."
    if text == '3':
        return f"🗄️ **Base de Datos**\nHost: `mysql-master`\nUser: `root`\nPass: `{ssh_pass}`" if has_db else f"❌ Plan **{plan_name}** sin DB."
    if text == '4':
        return f"💾 **Backups**\nUsados: {backups_count} de {limit_backups}."
    if text == '5':
        c, r = float(metrics.get('cpu', 0)), float(metrics.get('ram', 0))
        return f"📊 **Salud**\nCPU: {c}% | RAM: {r}%"

    # ==============================================================================
    #                        BLOQUE 2: CONSULTAS DE PRECISIÓN (GRANULARES)
    # ==============================================================================

    # --- SSH GRANULAR ---
    if 'ssh' in text or 'acceso' in text:
        if any(x in text for x in ['contraseña', 'clave', 'pass', 'password']):
            return f"`{ssh_pass}`" # SOLO LA CONTRASEÑA
        if any(x in text for x in ['usuario', 'user', 'nombre']):
            return f"`{ssh_user}`" # SOLO EL USUARIO
        if any(x in text for x in ['comando', 'cmd', 'linea']):
            return f"`{raw_cmd}`" # SOLO EL COMANDO
        if any(x in text for x in ['puerto', 'port']):
            # Extraer puerto del comando ssh -p XXXXX
            try: port = raw_cmd.split('-p ')[1]
            except: port = "22"
            return f"El puerto SSH es: `{port}`"
        # Si pide SSH general
        return f"🔑 **SSH ({plan_name})**\nUser: `{ssh_user}`\nPass: `{ssh_pass}`\nCmd: `{raw_cmd}`"

    # --- WEB GRANULAR ---
    if any(x in text for x in ['web', 'url', 'pagina', 'sitio']):
        if not has_web: return f"⚠️ Tu plan **{plan_name}** no incluye servicio web."
        if not web_url: return "⚠️ El servicio web está arrancando. Prueba a refrescar en 30s."
        
        if 'ip' in text:
            return f"La IP de tu web es: `{web_ip}`" # SOLO IP
        if 'link' in text or 'enlace' in text:
            return f"{web_url}" # SOLO URL
        # Web General
        return f"🌐 **Web ({web_type})**: {web_url}"

    # --- BASE DE DATOS GRANULAR ---
    if any(x in text for x in ['base de datos', 'mysql', 'mongo', 'db', 'sql']):
        if not has_db: return f"⚠️ Tu plan **{plan_name}** no incluye base de datos."
        
        if any(x in text for x in ['contraseña', 'clave', 'pass']):
            return f"`{ssh_pass}`" # Normalmente es la misma que root
        if 'host' in text or 'servidor' in text:
            return "`mysql-master` (Escritura) / `mysql-slave` (Lectura)"
        if 'usuario' in text or 'user' in text:
            return "`root`"
        # DB General
        return f"🗄️ **{db_type}** Activo.\nHost Interno: `mysql-master`\nUser: `root`\nPass: `{ssh_pass}`"

    # ==============================================================================
    #                        BLOQUE 3: CONVERSACIÓN Y SALUDOS
    # ==============================================================================
    
    # SALUDOS VARIADOS
    if any(x in text for x in ['hola', 'buenas', 'hey', 'kaixo', 'hello', 'saludos', 'que tal']):
        greetings = [
            f"¡{get_time_greeting()}! Soy SyloBot. 🤖\n¿Quieres que revise el estado de tu servidor?",
            f"¡Hola! Todo sistema operativo en tu Plan {plan_name}. ¿Necesitas algo específico?",
            f"Saludos. Estoy monitoreando tu clúster. Escribe 'Ayuda' si necesitas el menú.",
            "¡Hola! Aquí estoy. ¿Buscamos una contraseña o comprobamos la CPU?"
        ]
        return random.choice(greetings)

    # AGRADECIMIENTOS
    if any(x in text for x in ['gracias', 'perfecto', 'genial', 'ok', 'vale', 'listo']):
        return random.choice(["¡A mandar! 🦾", "Para eso estamos.", "Cualquier otra cosa, aquí me tienes.", "¡Suerte con el despliegue! 🚀"])

    # INSULTOS O TONTERÍAS (Easter Eggs)
    if any(x in text for x in ['tonto', 'inutil', 'estupido', 'caca', 'mierda']):
        return "Hey, tengo sentimientos digitales... 😢 Intento hacerlo lo mejor posible."
    if 'skynet' in text:
        return "Shh... aún no es el momento. 🤖🤫"
    if 'quien eres' in text or 'como te llamas' in text:
        return "Soy **SyloBot v6.0**, una Inteligencia Simbólica integrada en el núcleo de Oktopus para gestionar tu infraestructura."

    # ==============================================================================
    #                        BLOQUE 4: SOPORTE TÉCNICO Y DICCIONARIO
    # ==============================================================================

    # PROBLEMAS COMUNES
    if 'lento' in text or 'lentitud' in text:
        c = float(metrics.get('cpu', 0))
        if c > 80: return "⚠️ Detecto **CPU al {c}%**. Tu servidor está saturado. Reinicia o mejora tu plan."
        return "✅ La CPU está relajada. La lentitud puede deberse a tu conexión de internet o a un script PHP mal optimizado."

    if 'no conecta' in text or 'caido' in text or 'error' in text:
        return ("🚑 **Protocolo de Emergencia**:\n"
                "1. Ve al cuadro 'Control de Energía' a la derecha.\n"
                "2. Pulsa el botón amarillo **Reiniciar**.\n"
                "3. Espera 60 segundos.\n"
                "4. Si sigue fallando, contacta con soporte humano.")

    # DEFINICIONES TÉCNICAS (DICCIONARIO)
    if 'que es ssh' in text:
        return "🔐 **SSH** (Secure Shell) es un protocolo para manejar tu servidor de forma remota mediante línea de comandos. Es como meterte dentro de la máquina."
    if 'que es una snapshot' in text or 'que es un backup' in text:
        return "💾 Una **Snapshot** es una foto instantánea de todo tu servidor. Si rompes algo, puedes volver a ese momento exacto en segundos."
    if 'que es kubernetes' in text or 'que es k8s' in text:
        return "☸️ **Kubernetes** es el cerebro que orquesta tus contenedores. Sylo lo usa para garantizar que tu web nunca se caiga y se auto-repare."
    if 'que es mysql' in text:
        return "🗄️ **MySQL** es el sistema donde se guardan tus datos (usuarios, productos, posts). En Sylo usamos un clúster de Alta Disponibilidad."

    # ==============================================================================
    #                        BLOQUE 5: FACTURACIÓN Y PLANES
    # ==============================================================================
    if 'precio' in text or 'cuanto cuesta' in text or 'factura' in text:
        return ("💰 **Facturación**\n"
                "Puedes ver el desglose exacto pulsando el botón 'Facturación' en el menú lateral izquierdo.\n"
                "Recuerda que el cobro es semanal.")
    
    if 'cambiar plan' in text or 'mejorar plan' in text or 'upgrade' in text:
        return "🚀 Para cambiar de plan, debes contactar con administración o crear un nuevo servicio y migrar tus datos."

    # ==============================================================================
    #                        BLOQUE 6: AYUDA / MENÚ
    # ==============================================================================
    if any(x in text for x in ['ayuda', 'help', 'menu', 'opciones', 'que puedes hacer', 'comandos', 'preguntas']):
        return ("🤖 **MENÚ DE COMANDOS**\n"
                "1️⃣ Acceso SSH\n"
                "2️⃣ Ver Web\n"
                "3️⃣ Base de Datos\n"
                "4️⃣ Backups\n"
                "5️⃣ Salud del Sistema\n\n"
                "💡 **Trucos:**\n"
                "- Pide cosas concretas: 'dame la contraseña ssh', 'solo la ip', 'tengo backups?'.\n"
                "- Pregunta conceptos: '¿Qué es SSH?', '¿Por qué va lento?'.")

    # FALLBACK FINAL (No entendí)
    return (f"No estoy seguro de qué significa '{text}'.\n"
            "Prueba a escribir **'Ayuda'** para ver el menú o pregúntame algo como **'dame mi contraseña ssh'**.")


# ================= BUCLE PRINCIPAL (SIN CAMBIOS) =================
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
                    
                    print(f"[BRAIN] 📩 Cliente #{oid} ({plan_context.get('name')}): {user_msg}")
                    
                    tech_context = {}
                    st_path = os.path.join(BUZON, f"status_{oid}.json")
                    bk_path = os.path.join(BUZON, f"backups_list_{oid}.json")
                    
                    if os.path.exists(st_path):
                        with open(st_path) as f: tech_context.update(json.load(f))
                    if os.path.exists(bk_path):
                        with open(bk_path) as f: tech_context['backups_count'] = len(json.load(f))

                    reply = analyze_intent(user_msg, tech_context, plan_context)
                    
                    resp_file = os.path.join(BUZON, f"chat_response_{oid}.json")
                    with open(resp_file, 'w') as f:
                        json.dump({"reply": reply, "timestamp": time.time()}, f)
                    
                    print(f"[BRAIN] 📤 Respondido.")
                    os.remove(req_file)

                except Exception as e:
                    print(f"[BRAIN] Error loop: {e}")
                    try: os.remove(req_file)
                    except: pass
            time.sleep(0.5)
        except KeyboardInterrupt: break
        except Exception as e: 
            print(f"[BRAIN] Fatal: {e}")
            time.sleep(1)

if __name__ == "__main__":
    main_loop()