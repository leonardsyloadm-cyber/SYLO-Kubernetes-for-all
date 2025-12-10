#!/bin/bash

# ==========================================
# SYLO TERMINATOR - GESTOR DE DESTRUCCIÓN
# ==========================================
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)" 
BUZON="$BASE_DIR/buzon-pedidos"

# DB Config
DB_CONTAINER="kylo-main-db"
DB_USER="sylo_app"
DB_PASS="sylo_app_pass"
DB_NAME="kylo_main_db"

# Colores
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Asegurar permisos
mkdir -p "$BUZON"
chmod 777 "$BUZON" 2>/dev/null

# --- FUNCIÓN BORRAR FILA DB ---
delete_db_row() {
    local id=$1
    docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent --skip-column-names \
    -e "DELETE FROM orders WHERE id=$id;"
}

echo -e "${RED}=== 💀 TERMINATOR ACTIVO (ESPERANDO VÍCTIMAS) ===${NC}"
echo -e "${YELLOW}    Vigilando: $BUZON ${NC}"

while true; do
    shopt -s nullglob

    # ---------------------------------------------------------
    # 1. ELIMINACIÓN INDIVIDUAL (kill_orden_X.json)
    # ---------------------------------------------------------
    for killfile in "$BUZON"/kill_orden_*.json; do
        if [ -f "$killfile" ]; then
            # Extraer ID del nombre del archivo
            KILL_ID=$(echo "$killfile" | grep -oE '[0-9]+')
            
            echo -e "${RED}Target adquirido: ID $KILL_ID ${NC}"
            
            # 1. Destruir Minikube (Probamos todos los perfiles posibles)
            echo "   🔥 Eliminando clústeres..."
            minikube delete -p "ClienteBronce-$KILL_ID" >/dev/null 2>&1
            minikube delete -p "ClientePlata-$KILL_ID" >/dev/null 2>&1
            minikube delete -p "ClienteOro-$KILL_ID" >/dev/null 2>&1
            minikube delete -p "ClienteCustom-$KILL_ID" >/dev/null 2>&1
            
            # 2. Limpieza forzada de Docker (por si queda basura)
            docker rm -f "ClienteBronce-$KILL_ID" "ClientePlata-$KILL_ID" "ClienteOro-$KILL_ID" "ClienteCustom-$KILL_ID" >/dev/null 2>&1

            # 3. Borrar de la Base de Datos (Panel Admin)
            echo "   💾 Borrando registro DB..."
            delete_db_row "$KILL_ID"
            
            # 4. Limpiar archivos del buzón
            rm -f "$killfile" \
                  "$BUZON/status_$KILL_ID.json" \
                  "$BUZON/orden_$KILL_ID.json" \
                  "$BUZON/orden_$KILL_ID.json.procesado"
            
            echo -e "${YELLOW}   ✅ Objetivo $KILL_ID neutralizado.${NC}"
        fi
    done

    # ---------------------------------------------------------
    # 2. PURGA TOTAL (PURGE_ALL.signal)
    # ---------------------------------------------------------
    if [ -f "$BUZON/PURGE_ALL.signal" ]; then
        echo -e "${RED}☢️  ALERTA NUCLEAR: PURGANDO TODO EL SISTEMA...${NC}"
        
        # 1. Borrar todos los perfiles de minikube
        minikube delete --all
        
        # 2. Borrar todos los contenedores de clientes
        # Filtramos por nombres que contengan "Cliente" para no borrar tu DB o Web
        docker ps -a --format "{{.Names}}" | grep "Cliente" | xargs -r docker rm -f
        
        # 3. Limpiar todos los archivos del buzón
        rm -f "$BUZON"/*.json "$BUZON"/*.procesado "$BUZON"/*.signal
        
        echo -e "${YELLOW}✨ Sistema reseteado a fábrica.${NC}"
    fi

    sleep 2
done