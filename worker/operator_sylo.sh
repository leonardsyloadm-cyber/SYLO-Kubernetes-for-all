#!/bin/bash

# ==========================================
# SYLO OPERATOR - GESTIÓN DE ENERGÍA (CORREGIDO)
# ==========================================
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)" 
BUZON="$BASE_DIR/buzon-pedidos"

# Configuración DB
DB_CONTAINER="kylo-main-db"
DB_USER="sylo_app"
DB_PASS="sylo_app_pass"
DB_NAME="kylo_main_db"

# Colores
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

mkdir -p "$BUZON"

# --- FUNCIÓN: ACTUALIZAR ESTADO DB ---
update_db_status() {
    local id=$1
    local status=$2
    docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent --skip-column-names \
    -e "UPDATE orders SET status='$status' WHERE id=$id;"
}

# --- FUNCIÓN: ENCONTRAR NOMBRE DEL CLÚSTER (VERSIÓN INTELIGENTE) ---
# ESTA ES LA PARTE QUE ARREGLA TU PROBLEMA
# Busca cualquier perfil en minikube que termine en "-ID"
find_cluster_profile() {
    local id=$1
    # 1. Lista perfiles
    # 2. AWK saca solo la columna de nombres
    # 3. GREP busca el patrón "-ID" al final de la línea ($)
    local found=$(minikube profile list 2>/dev/null | awk '{print $2}' | grep -E -- "-$id$" | head -n 1)
    echo "$found"
}

echo -e "${CYAN}=== 🕹️  OPERATOR ACTIVO (ESPERANDO COMANDOS) ===${NC}"

while true; do
    shopt -s nullglob
    
    # Buscamos archivos de acción
    for action_file in "$BUZON"/accion_*.json; do
        if [ -f "$action_file" ]; then
            
            # 1. Leer datos del JSON
            eval $(python3 -c "import json; d=json.load(open('$action_file')); print(f'OID={d.get(\"id\")} ACT={d.get(\"action\")}')")
            
            echo -e "${YELLOW}⚡ Acción recibida: $ACT para Pedido #$OID${NC}"
            
            # 2. Buscar el clúster real
            PROFILE=$(find_cluster_profile "$OID")
            
            if [ -z "$PROFILE" ]; then
                echo -e "${RED}❌ Error: No encuentro la máquina física para el ID $OID${NC}"
                # Borramos el archivo para evitar bucle infinito
                rm -f "$action_file"
                continue
            fi

            echo "   🎯 Objetivo identificado: $PROFILE"

            # 3. Ejecutar la acción
            case "$ACT" in
                "START")
                    echo "   ▶️  Arrancando clúster..."
                    minikube start -p "$PROFILE" >/dev/null 2>&1
                    update_db_status "$OID" "active"
                    echo -e "${GREEN}   ✅ Clúster ONLINE${NC}"
                    ;;
                    
                "STOP")
                    echo "   ⏸️  Pausando/Apagando clúster..."
                    # ESTE COMANDO ES EL QUE CORTA EL ACCESO SSH
                    minikube stop -p "$PROFILE" >/dev/null 2>&1
                    update_db_status "$OID" "suspended"
                    echo -e "${GREEN}   ✅ Clúster OFFLINE (Apagado Real)${NC}"
                    ;;
                    
                "RESTART")
                    echo "   🔄 Reiniciando clúster..."
                    minikube stop -p "$PROFILE" >/dev/null 2>&1
                    sleep 2
                    minikube start -p "$PROFILE" >/dev/null 2>&1
                    update_db_status "$OID" "active"
                    echo -e "${GREEN}   ✅ Clúster REINICIADO${NC}"
                    ;;
                    
                *)
                    echo -e "${RED}   ❓ Acción desconocida: $ACT${NC}"
                    ;;
            esac
            
            # 4. Limpieza
            rm -f "$action_file"
        fi
    done
    
    sleep 2
done