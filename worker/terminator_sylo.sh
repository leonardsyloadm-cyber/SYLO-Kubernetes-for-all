#!/bin/bash

# ==============================================================================
# 🤖 TERMINATOR SYLO - VIGILANTE SELECTIVO
# ==============================================================================

# 1. CONFIGURACIÓN DE RUTAS
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NUKE_SCRIPT="$SCRIPT_DIR/../tofu-k8s/destroyers/nuke_cluster.sh"
WATCH_DIR="/home/ivan/proyecto/buzon-pedidos"

# 2. VERIFICACIONES INICIALES
if [ ! -d "$WATCH_DIR" ]; then
    mkdir -p "$WATCH_DIR"
fi

if [ ! -x "$NUKE_SCRIPT" ]; then
    chmod +x "$NUKE_SCRIPT"
fi

echo "================================================"
echo "   💀 TERMINATOR SYLO - SISTEMA DE PURGA        "
echo "   Vigilando: $WATCH_DIR                        "
echo "   🎯 Solo atacará archivos: kill_*.json        "
echo "================================================"

# 3. BUCLE DE VIGILANCIA
inotifywait -m -e close_write -e moved_to --format '%f' "$WATCH_DIR" | while read FILENAME
do
    FULL_PATH="$WATCH_DIR/$FILENAME"

    # 🔥 CORRECCIÓN CRÍTICA: SOLO ATACAR SI EMPIEZA POR 'kill_'
    if [[ "$FILENAME" == kill_*.json ]]; then
        
        echo ""
        echo "🚨 ¡ALERTA DE ELIMINACIÓN RECIBIDA!"
        echo "📄 Archivo hostil detectado: $FILENAME"

        # Extraer ID
        TARGET_ID=$(python3 -c "import sys, json; print(json.load(open('$FULL_PATH')).get('id', ''))" 2>/dev/null)

        if [ -z "$TARGET_ID" ]; then
            echo "⚠️  JSON inválido. Eliminando basura."
            rm -f "$FULL_PATH"
            continue
        fi

        echo "🎯 Objetivo confirmado ID: $TARGET_ID"
        echo "🚀 Lanzando nuke_cluster.sh..."

        # Ejecutar Nuke
        bash "$NUKE_SCRIPT" "$TARGET_ID"

        # Limpieza
        rm -f "$FULL_PATH"
        echo "👀 Regresando a modo vigilancia..."
        echo "------------------------------------------------"

    elif [[ "$FILENAME" == *.json ]]; then
        # Si es un JSON pero no empieza por kill_, lo ignoramos (es un amigo)
        echo "🛡️  Archivo seguro detectado ($FILENAME). El Terminator lo ignora."
    fi
done