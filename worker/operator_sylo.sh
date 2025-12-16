#!/bin/bash

# ==========================================
# SYLO OPERATOR - GESTIÓN DE ENERGÍA (NO DESTRUCCIÓN)
# ==========================================
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)" 
BUZON="$BASE_DIR/buzon-pedidos"

# Configuración DB
DB_CONTAINER="kylo-main-db"
DB_USER="sylo_app"
DB_PASS="sylo_app_pass"
DB_NAME="kylo_main_db"

# Red
SYLO_NETWORK="sylo-net"
SUBNET_PREFIX="192.168.200"

# Colores
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

mkdir -p "$BUZON"

update_db_status() {
    local id=$1
    local status=$2
    docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent --skip-column-names \
    -e "UPDATE orders SET status='$status' WHERE id=$id;"
}

find_cluster_profile() {
    local id=$1
    local found=$(minikube profile list 2>/dev/null | awk '{print $2}' | grep -E -- "-$id$" | head -n 1)
    echo "$found"
}

refresh_connection_info() {
    local id=$1
    local ip=$2
    local profile=$3
    local json_file="$BUZON/status_$id.json"

    if [ -f "$json_file" ]; then
        local NEW_PORT=$(minikube -p "$profile" kubectl -- get svc ssh-service -o jsonpath='{.spec.ports[0].nodePort}' 2>/dev/null)
        if [ ! -z "$NEW_PORT" ]; then
             python3 -c "import json; f='$json_file'; d=json.load(open(f)); d['ssh_cmd']='ssh cliente@$ip -p $NEW_PORT'; json.dump(d, open(f,'w'), indent=4)" 2>/dev/null
        fi
    fi
}

echo -e "${CYAN}=== 🕹️  OPERATOR ACTIVO (IGNORANDO TERMINATE) ===${NC}"

while true; do
    shopt -s nullglob
    
    # MODIFICACIÓN CLAVE: Solo buscamos acciones de energía. Ignoramos terminate.
    # Usamos llaves {} para buscar múltiples patrones a la vez
    for action_file in "$BUZON"/accion_*_{start,stop,restart}.json; do
        if [ -f "$action_file" ]; then
            
            eval $(python3 -c "import json; d=json.load(open('$action_file')); print(f'OID={d.get(\"id\")} ACT={d.get(\"action\")}')")
            
            echo -e "${YELLOW}⚡ Procesando $ACT para Pedido #$OID${NC}"
            
            PROFILE=$(find_cluster_profile "$OID")
            
            if [ -z "$PROFILE" ]; then
                echo -e "${RED}❌ Error: Máquina no encontrada para ID $OID${NC}"
                rm -f "$action_file"
                continue
            fi

            STATIC_IP="${SUBNET_PREFIX}.${OID}"

            case "$ACT" in
                "START")
                    echo "   ▶️  Arrancando..."
                    minikube start -p "$PROFILE" --network "$SYLO_NETWORK" --static-ip "$STATIC_IP" >/dev/null 2>&1
                    refresh_connection_info "$OID" "$STATIC_IP" "$PROFILE"
                    update_db_status "$OID" "active"
                    echo -e "${GREEN}   ✅ ENCENDIDO${NC}"
                    ;;
                    
                "STOP")
                    echo "   ⏸️  Apagando..."
                    minikube stop -p "$PROFILE" >/dev/null 2>&1
                    update_db_status "$OID" "suspended"
                    echo -e "${GREEN}   ✅ APAGADO${NC}"
                    ;;
                    
                "RESTART")
                    echo "   🔄 Reiniciando..."
                    minikube stop -p "$PROFILE" >/dev/null 2>&1
                    sleep 2
                    minikube start -p "$PROFILE" --network "$SYLO_NETWORK" --static-ip "$STATIC_IP" >/dev/null 2>&1
                    refresh_connection_info "$OID" "$STATIC_IP" "$PROFILE"
                    update_db_status "$OID" "active"
                    echo -e "${GREEN}   ✅ REINICIADO${NC}"
                    ;;
            esac
            
            rm -f "$action_file"
        fi
    done
    sleep 2
done