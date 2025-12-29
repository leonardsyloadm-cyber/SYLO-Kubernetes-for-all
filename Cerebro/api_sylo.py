from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from routers import clientes
import uvicorn
import sys
import os
import logging

# Configuración de Logs para Oktopus
sys.stdout.reconfigure(line_buffering=True)

# Paths
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

# Limpieza de consola
logging.getLogger("uvicorn.access").disabled = True

app = FastAPI(title="Sylo Enterprise API", version="1.0-STABLE")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], 
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(clientes.router, prefix="/api/clientes", tags=["Clientes"])

@app.get("/")
def root():
    return {"sistema": "Sylo Brain", "estado": "ONLINE (FILE-BASED)"}

if __name__ == "__main__":
    print("🧠 [SYLO BRAIN] API Restaurada y Lista.", flush=True)
    uvicorn.run(app, host="0.0.0.0", port=8001, log_level="warning")