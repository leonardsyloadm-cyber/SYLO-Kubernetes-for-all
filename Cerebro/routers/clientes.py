from fastapi import APIRouter
from pydantic import BaseModel
import json
import os
import time
import glob
from typing import Optional, List

router = APIRouter()

# Rutas relativas para encontrar el buzón
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) 
BUZON_PEDIDOS = os.path.join(os.path.dirname(BASE_DIR), "sylo-web", "buzon-pedidos")
if not os.path.exists(BUZON_PEDIDOS): os.makedirs(BUZON_PEDIDOS, exist_ok=True)

# ==========================================
# MODELOS DE DATOS (ACTUALIZADOS V21)
# ==========================================

# 1. Especificaciones del Cluster (Lo que viene del Frontend)
class EspecificacionesCluster(BaseModel):
    # Recursos Hardware
    cpu: int = 1
    ram: int = 1
    storage: int = 10
    
    # Toggles de Software
    db_enabled: bool = False
    db_type: str = "mysql"
    web_enabled: bool = False
    web_type: str = "nginx"
    
    # Personalización e Identidad
    cluster_alias: str = "Mi Cluster Sylo"
    subdomain: str 
    ssh_user: str = "admin_sylo"
    os_image: str = "ubuntu" # 'alpine', 'ubuntu', 'redhat'
    
    # Nombres Personalizados
    db_custom_name: Optional[str] = None
    web_custom_name: Optional[str] = None
    
    # Sylo Toolbelt & Pricing
    tools: List[str] = []
    price: float = 0.0

# 2. Orden de Creación (Pedido Completo)
class OrdenCreacion(BaseModel):
    id_cliente: int
    plan: str
    cliente_nombre: str = "cliente_api"
    specs: EspecificacionesCluster
    # ID del usuario dueño (Crítico para seguridad/aislamiento)
    id_usuario_real: str = "admin" 

# 3. Acciones sobre el cluster (Backups, etc)
class OrdenAccion(BaseModel):
    id_cliente: int
    accion: str
    backup_type: Optional[str] = "full"
    backup_name: Optional[str] = "Backup API"
    filename_to_restore: Optional[str] = ""
    html_content: Optional[str] = "" 
    filename_to_delete: Optional[str] = ""

# 4. Reporte de Métricas y Estado Final (Lo que envían los scripts)
class ReporteMetrica(BaseModel):
    id_cliente: int
    metrics: dict
    ssh_cmd: str = ""
    installed_tools: List[str] = []
    web_url: str = ""
    # 🔥 CAMBIO VITAL: Campo nuevo para recibir el nombre bonito del OS
    os_info: Optional[str] = "Linux Genérico"

class ReporteProgreso(BaseModel):
    id_cliente: int
    tipo: str
    status_text: str
    percent: int
    msg: str

class ReporteContenidoWeb(BaseModel):
    id_cliente: int
    html_content: str

class ReporteListaBackups(BaseModel):
    id_cliente: int
    backups: List[dict]

class OrdenIA(BaseModel):
    id_cliente: int
    mensaje: str
    contexto_plan: dict = {}

# ==========================================
# HELPERS (Gestión de Archivos)
# ==========================================
def guardar_json(nombre, data):
    try:
        ruta = os.path.join(BUZON_PEDIDOS, nombre)
        ruta_tmp = ruta + ".tmp"
        with open(ruta_tmp, 'w') as f: json.dump(data, f)
        os.chmod(ruta_tmp, 0o666)
        os.replace(ruta_tmp, ruta)
    except Exception as e: print(f"❌ Error escritura JSON: {e}", flush=True)

def guardar_html(nombre, contenido):
    try:
        ruta = os.path.join(BUZON_PEDIDOS, nombre)
        with open(ruta, 'w', encoding='utf-8') as f: f.write(contenido)
        os.chmod(ruta, 0o666)
    except Exception as e: print(f"❌ Error escritura HTML: {e}", flush=True)

# ==========================================
# ENDPOINTS
# ==========================================

@router.post("/crear")
async def solicitar_creacion(datos: OrdenCreacion):
    # Serializamos la orden a JSON para que el Orquestador la lea
    payload = {
        "id": datos.id_cliente, 
        "plan": datos.plan, 
        "cliente": datos.cliente_nombre, 
        "specs": datos.specs.dict(),
        "timestamp": time.time(),
        "id_usuario_real": datos.id_usuario_real
    }
    guardar_json(f"orden_{datos.id_cliente}.json", payload)
    
    print(f"📨 [API] Nuevo Pedido Recibido. Plan: {datos.plan} | Dueño: {datos.id_usuario_real}", flush=True)
    return {"status": "OK", "msg": "Orden validada y encolada"}

@router.post("/accion")
async def solicitar_accion(datos: OrdenAccion):
    if datos.accion.upper() == "UPDATE_WEB" and datos.html_content:
        guardar_html(f"web_source_{datos.id_cliente}.html", datos.html_content)
        print(f"💾 [API] HTML persistido para cliente {datos.id_cliente}")
        
    payload = datos.dict()
    payload["action"] = datos.accion.upper()
    guardar_json(f"accion_{datos.id_cliente}_{int(time.time()*1000)}.json", payload)
    
    # 🔥 RESETEAR STATUS para evitar "falsos positivos" de acciones anteriores
    # Si pedimos un power action, limpiamos el power_status anterior
    if datos.accion.upper() in ["STOP", "START", "RESTART"]:
         reset_payload = {"status": "pending", "progress": 5, "msg": "Enviando orden..."}
         guardar_json(f"power_status_{datos.id_cliente}.json", reset_payload)

class ReporteTools(BaseModel):
    id_cliente: int
    tools: List[str]

@router.post("/reportar/tools")
async def reportar_tools(datos: ReporteTools):
    try:
        archivo_status = os.path.join(BUZON_PEDIDOS, f"status_{datos.id_cliente}.json")
        data = {}
        if os.path.exists(archivo_status):
            with open(archivo_status, 'r') as f: data = json.load(f)
            
        data["installed_tools"] = datos.tools
        # No tocamos last_update o metrics para no colisionar
        
        guardar_json(f"status_{datos.id_cliente}.json", data)
        print(f"🛠️ [API] Tools guardadas para Cliente {datos.id_cliente}: {datos.tools}", flush=True)
        return {"status": "ok"}
    except Exception as e:
        print(f"❌ [API] Error guardando tools: {e}", flush=True)
        return {"status": "error", "msg": str(e)}


@router.post("/reportar/metricas")
async def recibir_metricas(datos: ReporteMetrica):
    # Guardamos el estado final que envía el script bash/python
    archivo_status = os.path.join(BUZON_PEDIDOS, f"status_{datos.id_cliente}.json")
    
    # Leer estado completo anterior para no perder NADA (status, msg, tools...)
    data = {}
    if os.path.exists(archivo_status):
        try: 
            with open(archivo_status, 'r') as f: 
                data = json.load(f)
        except: pass

    # Actualizar campos de métricas (Siempre)
    data["metrics"] = datos.metrics
    
    # Solo actualizar info estática si viene dato real
    if datos.ssh_cmd: data["ssh_cmd"] = datos.ssh_cmd
    if datos.web_url: data["web_url"] = datos.web_url
    if datos.os_info: data["os_info"] = datos.os_info
    
    # Solo sobrescribir tools si vienen nuevas
    if datos.installed_tools:
        data["installed_tools"] = datos.installed_tools
        
    data["last_update"] = time.time()

    guardar_json(f"status_{datos.id_cliente}.json", data)
    return {"status": "recibido"}

@router.post("/reportar/progreso")
async def recibir_progreso(datos: ReporteProgreso):
    if datos.tipo == "web":
        nombre_archivo = f"web_status_{datos.id_cliente}.json"
    elif datos.tipo == "power":
        nombre_archivo = f"power_status_{datos.id_cliente}.json"
    else:
        nombre_archivo = f"backup_status_{datos.id_cliente}.json"

    estado = datos.status_text
    if datos.percent >= 100: estado = "completed"
    
    payload = {"status": estado, "progress": datos.percent, "msg": datos.msg}
    guardar_json(nombre_archivo, payload)
    return {"status": "procesado"}

@router.post("/reportar/contenido_web")
async def recibir_contenido_web(datos: ReporteContenidoWeb):
    guardar_html(f"web_source_{datos.id_cliente}.html", datos.html_content)
    return {"status": "guardado"}

@router.post("/reportar/lista_backups")
async def recibir_lista_backups(datos: ReporteListaBackups):
    guardar_json(f"backups_list_{datos.id_cliente}.json", datos.backups)
    return {"status": "guardado"}

@router.get("/estado/{id_cliente}")
async def leer_estado(id_cliente: int):
    # Estado base por si no hay archivos aún
    data = {
        "metrics": {"cpu":0, "ram":0}, 
        "ssh_cmd": "Cargando...", 
        "web_url": "",
        "os_info": "Pendiente...", # Default 
        "installed_tools": [],
        "backups_list": [], 
        "backup_progress": None, 
        "web_progress": None,
        "power_progress": None,
        "html_source": "" 
    }
    
    # Leemos todos los JSONs generados por los workers
    try:
        with open(os.path.join(BUZON_PEDIDOS, f"status_{id_cliente}.json"), 'r') as f: data.update(json.load(f))
    except: pass
    
    try:
        with open(os.path.join(BUZON_PEDIDOS, f"backups_list_{id_cliente}.json"), 'r') as f: data["backups_list"] = json.load(f)
    except: pass
    
    try:
        with open(os.path.join(BUZON_PEDIDOS, f"backup_status_{id_cliente}.json"), 'r') as f: data["backup_progress"] = json.load(f)
    except: pass
    
    try:
        with open(os.path.join(BUZON_PEDIDOS, f"web_status_{id_cliente}.json"), 'r') as f: data["web_progress"] = json.load(f)
    except: pass

    try:
        with open(os.path.join(BUZON_PEDIDOS, f"power_status_{id_cliente}.json"), 'r') as f: data["power_progress"] = json.load(f)
    except: pass
    
    try:
        ruta_html = os.path.join(BUZON_PEDIDOS, f"web_source_{id_cliente}.html")
        if os.path.exists(ruta_html):
            with open(ruta_html, 'r', encoding='utf-8') as f: data["html_source"] = f.read()
    except: pass

    return data

@router.post("/chat")
async def solicitar_ia(datos: OrdenIA):
    req_id = f"req_{datos.id_cliente}_{int(time.time())}"
    guardar_json(f"chat_request_{req_id}.json", {"msg": datos.mensaje, "context_plan": datos.contexto_plan, "timestamp": time.time()})
    return {"status": "OK", "req_id": req_id}

@router.get("/chat/leer/{id_cliente}")
async def leer_respuesta_ia(id_cliente: int):
    patron = os.path.join(BUZON_PEDIDOS, f"chat_response_*{id_cliente}*.json")
    archivos = glob.glob(patron)
    if archivos:
        try:
            # Ordenamos por fecha para leer el último
            archivos.sort(key=os.path.getmtime, reverse=True)
            with open(archivos[0], 'r') as f: data = json.load(f)
            os.remove(archivos[0]) # Lo borramos tras leer para no repetir
            return data
        except: pass
    return {"reply": None}