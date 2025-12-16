#!/bin/bash

# ==========================================
# SYLO TERMINATOR V3 - NUCLEAR SILENCIOSO
# ==========================================
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)" 
BUZON="$BASE_DIR/buzon-pedidos"

# Configuración DB
DB_CONTAINER="kylo-main-db"
DB_USER="sylo_app"
DB_PASS="sylo_app_pass"
DB_NAME="kylo_main_db"

# Colores
RED='\033[0;31m'
MAGENTA='\033[0;35m'
GREEN='\033[0;32m'
NC='\033[0m'

echo -e "${MAGENTA}=== 💀 TERMINATOR ACTIVO (MODO SILENCIOSO) ===${NC}"

while true; do
    shopt -s nullglob
    
    # ---------------------------------------------------------
    # 1. PROTOCOLO HIROSHIMA (LIMPIEZA FLASH)
    # ---------------------------------------------------------
    if [ -f "$BUZON/HIROSHIMA_EVENT.signal" ]; then
        echo -e "\n${RED}☢️  PROTOCOLO HIROSHIMA ACTIVADO${NC}"
        
        # A) EL TRUCO: No preguntamos a Minikube. Matamos DOCKER directamente.
        # Esto evita que salgan los 50 fantasmas de la lista corrupta.
        echo "   🔥 Eliminando contenedores activos..."
        docker ps -a --format '{{.Names}}' | grep -E "^(Cliente|k8s|minikube)" | xargs -r docker rm -f >/dev/null 2>&1

        # B) Limpieza de disco (Por si acaso quedó basura)
        echo "   🧹 Asegurando limpieza de disco..."
        sudo rm -rf ~/.minikube/profiles/Cliente* 2>/dev/null
        sudo rm -rf ~/.minikube/machines/Cliente* 2>/dev/null
        
        # C) Limpieza de la 'libreta' corrupta para que no moleste más
        rm -f ~/.minikube/config/config.json 2>/dev/null

        # D) Limpieza de archivos de órdenes
        rm -f "$BUZON"/accion_*.json
        rm -f "$BUZON/HIROSHIMA_EVENT.signal"
        
        echo -e "${GREEN}✅ ZONA CERO LIMPIA. INFRAESTRUCTURA RESETEADA.${NC}\n"
    fi

    # ---------------------------------------------------------
    # 2. FRANCOTIRADOR (ELIMINAR UN SOLO CLIENTE)
    # ---------------------------------------------------------
    for action_file in "$BUZON"/accion_*_terminate.json; do
        if [ -f "$action_file" ]; then
            
            # Leer ID
            eval $(python3 -c "import json; d=json.load(open('$action_file')); print(f'OID={d.get(\"id\")}')")
            
            echo -e "${RED}☠️  Eliminando Pedido #$OID${NC}"
            
            # Intentar borrar perfil específico (si existe)
            TARGET_PROFILE=$(minikube profile list 2>/dev/null | awk '{print $2}' | grep -E -- "-$OID$" | head -n 1)
            
            if [ ! -z "$TARGET_PROFILE" ]; then
                minikube delete -p "$TARGET_PROFILE" >/dev/null 2>&1
                sudo rm -rf ~/.minikube/profiles/$TARGET_PROFILE 2>/dev/null
            fi

            # Limpieza Docker directa
            docker ps -a --format '{{.Names}}' | grep "\-$OID$" | xargs -r docker rm -f >/dev/null 2>&1
            
            # Actualizar DB a cancelled (Papelera)
            docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent \
            -e "UPDATE orders SET status='cancelled' WHERE id=$OID;" 2>/dev/null

            rm -f "$action_file"
            
            echo -e "${RED}   ✅ Objetivo #$OID Neutralizado y en Papelera${NC}"
        fi
    done
    
    sleep 2
done