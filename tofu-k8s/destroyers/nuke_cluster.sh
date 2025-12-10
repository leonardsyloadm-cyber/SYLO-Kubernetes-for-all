#!/bin/bash

# ==============================================================================
# ☢️  NUKE CLUSTER - SCRIPT DE DESTRUCCIÓN MASIVA
# ==============================================================================

TARGET_ID=$1

if [ -z "$TARGET_ID" ]; then
    echo "❌ Error: No se ha especificado el TARGET_ID para destruir."
    exit 1
fi

echo "🔍 Buscando objetivos que terminen en ID: $TARGET_ID ..."

# ------------------------------------------------------------------------------
# 1. OBTENER LISTA DE PERFILES (FIX PYTHON SYNTAX)
# ------------------------------------------------------------------------------
# Usamos sys.argv[1] para leer el TARGET_ID de forma segura y evitar SyntaxError
PROFILES_TO_KILL=$(minikube profile list -o json 2>/dev/null | python3 -c "
import sys, json

try:
    data = json.load(sys.stdin)
    # Buscamos en validos e invalidos (por si el cluster esta roto)
    valid = data.get('valid', [])
    invalid = data.get('invalid', [])
    all_profiles = valid + invalid
    
    target_id = sys.argv[1] # Recibimos el ID como argumento
    
    # Filtramos los que terminen en '-ID' (ej: ClienteOro-1)
    targets = [p['Name'] for p in all_profiles if p['Name'].endswith('-' + target_id)]
    
    print(' '.join(targets))
except Exception as e:
    # Si falla el JSON o no hay perfiles, no imprimimos nada
    pass
" "$TARGET_ID")

# ------------------------------------------------------------------------------
# 2. EJECUTAR LA PURGA
# ------------------------------------------------------------------------------

if [ -z "$PROFILES_TO_KILL" ]; then
    echo "⚠️  No se encontraron perfiles de Minikube activos o rotos para el ID $TARGET_ID."
    echo "🧹 Intentando limpieza de contenedores Docker huérfanos por si acaso..."
    # Intento de borrado directo en Docker por si Minikube ya no lo detecta
    docker ps -a --format '{{.Names}}' | grep "\-$TARGET_ID$" | xargs -r docker rm -f
    exit 0
fi

echo "🎯 Objetivos localizados: $PROFILES_TO_KILL"

for PROFILE in $PROFILES_TO_KILL; do
    echo "---------------------------------------------------"
    echo "💥 Destruyendo cluster: $PROFILE"
    
    # Paso 1: Intentar borrar con Minikube
    minikube delete -p "$PROFILE" 2>/dev/null
    
    # Paso 2: Asegurar que el contenedor de Docker muere (Doble Tap)
    # Esto soluciona los errores de 'unknown state' si Minikube se lía
    if docker ps -a --format '{{.Names}}' | grep -q "^$PROFILE$"; then
        echo "🗑️  Forzando eliminación de contenedor Docker remanente: $PROFILE"
        docker rm -f "$PROFILE" > /dev/null 2>&1
    fi
    
    echo "✅ Objetivo $PROFILE eliminado."
done

echo "💀 Purga finalizada para ID: $TARGET_ID"
exit 0