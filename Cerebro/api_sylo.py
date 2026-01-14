from fastapi import FastAPI, WebSocket
from fastapi.middleware.cors import CORSMiddleware
from routers import clientes
import uvicorn
import sys
import os
import logging
import asyncio

# Configuración de Logs para Oktopus (Buffer de línea para ver logs en tiempo real)
sys.stdout.reconfigure(line_buffering=True)

# Añadir el directorio actual al Path para que Python encuentre 'routers'
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

# Limpieza de logs molestos de uvicorn (para mantener la consola limpia)
logging.getLogger("uvicorn.access").disabled = True

from security.neuro_shield import NeuroShieldMiddleware
from comm.docker_terminal import DockerTerminal

app = FastAPI(title="Sylo Enterprise API", version="1.0-STABLE")

# Activar Sylo Neuro-Shield (Prioridad Alta - Antes de CORS si es posible, o después dependiendo de si queremos bloquear preflight, 
# pero RateLimit suele ir primero. FastAPI ejecuta middleware en orden inverso al añadido (onion layers), 
# así que si queremos que ejecute PRIMERO, lo añadimos al FINAL.
# PERO Starlette BaseHTTPMiddleware...
# Mejor añadamoslo explícitamente.
app.add_middleware(NeuroShieldMiddleware)

# Configuración CORS (Permite que el frontend PHP hable con la API Python)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], 
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Incluir las rutas de clientes
app.include_router(clientes.router, prefix="/api/clientes", tags=["Clientes"])

@app.get("/")
def root():
    return {"sistema": "Sylo Brain", "estado": "ONLINE (FILE-BASED)"}

# --- SYLO BASTION: WEBSOCKET CONSOLE ---
@app.websocket("/api/console/{container_id}")
async def websocket_console(websocket: WebSocket, container_id: str):
    await websocket.accept()
    print(f"🔌 [BASTION] Conexión SSH solicitada para {container_id}")
    
    terminal = DockerTerminal(container_id)
    success = await terminal.start()
    
    if not success:
        await websocket.close(code=1000, reason="Container not found or failed to start socket")
        return
        
    try:
        # Tarea de fondo: Leer del PTY -> Enviar al WebSocket
        # Usamos asyncio.create_task para no bloquear
        # Pasamos websocket.send_text como callback
        reader_task = asyncio.create_task(terminal.read_loop(websocket.send_text))
        
        # Bucle principal: Leer del WebSocket -> Escribir al PTY
        while True:
            data = await websocket.receive_text()
            await terminal.write(data)
            
    except Exception as e:
        print(f"⚠️ [BASTION] Error en conexión: {e}")
    finally:
        terminal.close()
        print(f"🔌 [BASTION] Desconectado de {container_id}")

if __name__ == "__main__":
    print("🧠 [SYLO BRAIN] API Restaurada y Lista. Escuchando en puerto 8001...", flush=True)
    uvicorn.run(app, host="0.0.0.0", port=8001, log_level="warning")
