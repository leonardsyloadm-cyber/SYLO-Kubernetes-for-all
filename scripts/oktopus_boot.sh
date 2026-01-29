#!/bin/bash
set -e

# Directorio del proyecto
PROJECT_DIR="/home/leonard/proyecto"
cd "$PROJECT_DIR"

# Cargar variables de entorno si existen
if [ -f ".env" ]; then
    export $(grep -v '^#' .env | xargs)
fi

echo "=== Iniciando Secuencia de Arranque de Oktopus ==="

# 1. Gestionar conexión Tailscale
if [ -n "$TAILSCALE_AUTH_KEY" ]; then
    echo "[+] TAILSCALE_AUTH_KEY detectada. Intentando autenticación automática..."
    
    # Intentar subir tailscale. 
    # --accept-dns=false y --accept-routes=false pueden ayudar en redes restrictivas
    sudo tailscale up --authkey="$TAILSCALE_AUTH_KEY" --reset --accept-dns=false --accept-routes=false
    
    if [ $? -eq 0 ]; then
        echo "[+] Tailscale conectado exitosamente."
        tailscale ip -4
    else
        echo "[!] Error al conectar Tailscale. Continuando arranque de Oktopus de todas formas..."
    fi
else
    echo "[-] No se encontró TAILSCALE_AUTH_KEY. Saltando configuración automática de red."
    echo "    Asegúrate de que Tailscale esté conectado manualmente si es necesario."
fi

# 2. Iniciar Oktopus
echo "[+] Iniciando Oktopus (Cerebro)..."
# Usar el python del venv si existe, sino el del sistema
if [ -d "venv" ]; then
    PYTHON_EXEC="venv/bin/python3"
else
    PYTHON_EXEC="python3"
fi

$PYTHON_EXEC Cerebro/Oktopus.py
