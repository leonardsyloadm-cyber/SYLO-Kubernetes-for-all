#!/bin/bash

# ==========================================
# ORQUESTADOR SYLO (V3 - CLIENT AWARE)
# ==========================================
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)" 
BUZON="$BASE_DIR/buzon-pedidos"

# DB Config
DB_CONTAINER="kylo-main-db"
DB_USER="sylo_app"
DB_PASS="sylo_app_pass"
DB_NAME="kylo_main_db"

# Rutas scripts
SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_PLATA="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
SCRIPT_ORO="$BASE_DIR/tofu-k8s/full-stack/deploy_oro.sh"
SCRIPT_CUSTOM="$BASE_DIR/tofu-k8s/custom-stack/deploy_custom.sh"

# Colores
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

mkdir -p "$BUZON"
chmod 777 "$BUZON" 2>/dev/null

# --- FUNCIÓN REPORTAR ---
report_progress() {
    local id=$1
    local percent=$2
    local msg=$3
    echo "{\"percent\": $percent, \"message\": \"$msg\", \"status\": \"creating\"}" > "$BUZON/status_$id.json"
    chmod 777 "$BUZON/status_$id.json" 2>/dev/null
}

# --- FUNCIÓN ACTUALIZAR DB ---
update_db_state() {
    local id=$1
    local status=$2
    docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent --skip-column-names \
    -e "UPDATE orders SET status='$status' WHERE id=$id;"
}

# --- FUNCIÓN MONITORIZACIÓN ---
enable_monitoring() {
    local profile=$1
    echo -e "${CYAN}   🔌 Inyectando sonda de monitorización (Metrics Server) en $profile...${NC}"
    minikube addons enable metrics-server -p "$profile" >/dev/null 2>&1
    sleep 5
}

echo -e "${BLUE}=== ORQUESTADOR LIVE (V3 - CLIENT AWARE) ===${NC}"

while true; do
    shopt -s nullglob
    for pedido in "$BUZON"/orden_*.json; do
        if [ -f "$pedido" ]; then
            # 1. EXTRACCIÓN DE DATOS (AHORA INCLUYE EL CLIENTE)
            # Usamos Python para parsear el JSON de forma segura. Si 'cliente' no existe, pone 'cliente_generico'.
            eval $(python3 -c "import json; d=json.load(open('$pedido')); print(f'PLAN_RAW={d.get(\"plan\")} ID={d.get(\"id\")} CLIENTE={d.get(\"cliente\", \"cliente_generico\")}')")
            
            # Sanitización simple del nombre del cliente para bash (quitamos comillas raras si las hubiera)
            CLIENTE=$(echo "$CLIENTE" | tr -d '"' | tr -d "'")

            echo -e "${GREEN}👉 Procesando ID: $ID | Plan: $PLAN_RAW | Cliente: $CLIENTE${NC}"

            update_db_state "$ID" "creating"
            report_progress "$ID" 5 "Inicializando..."

            # Definimos el nombre estándar del perfil para luego activar monitorización
            # Esto debe coincidir con lo que usan tus scripts de deploy (sylo-cliente-$ID)
            CLUSTER_PROFILE="sylo-cliente-$ID"

            case "$PLAN_RAW" in
                "Bronce")
                    if [ -f "$SCRIPT_BRONCE" ]; then
                        report_progress "$ID" 10 "Iniciando Bronce..."
                        # PASAMOS EL CLIENTE COMO ARGUMENTO $2
                        bash "$SCRIPT_BRONCE" "$ID" "$CLIENTE"
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
                            enable_monitoring "$CLUSTER_PROFILE"
                            update_db_state "$ID" "active"
                        else
                            report_progress "$ID" 0 "Fallo crítico."
                            update_db_state "$ID" "error"
                        fi
                    fi
                    ;;
                    
                "Plata")
                    if [ -f "$SCRIPT_PLATA" ]; then
                        report_progress "$ID" 10 "Iniciando Plata..."
                        # PASAMOS EL CLIENTE COMO ARGUMENTO $2
                        (cd "$(dirname "$SCRIPT_PLATA")" && bash "./$(basename "$SCRIPT_PLATA")" "$ID" "$CLIENTE")
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
                            enable_monitoring "$CLUSTER_PROFILE"
                            update_db_state "$ID" "active"
                        else
                            report_progress "$ID" 0 "Fallo en Plata."
                            update_db_state "$ID" "error"
                        fi
                    fi
                    ;;
                
                "Oro")
                    if [ -f "$SCRIPT_ORO" ]; then
                        report_progress "$ID" 10 "Iniciando Oro..."
                        # PASAMOS EL CLIENTE COMO ARGUMENTO $2
                        bash "$SCRIPT_ORO" "$ID" "$CLIENTE"
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
                            enable_monitoring "$CLUSTER_PROFILE"
                            update_db_state "$ID" "active"
                        else
                            report_progress "$ID" 0 "Fallo en Oro."
                            update_db_state "$ID" "error"
                        fi
                    fi
                    ;;

                "Personalizado")
                    if [ -f "$SCRIPT_CUSTOM" ]; then
                        eval $(python3 -c "import json; d=json.load(open('$pedido')); s=d.get('specs',{}); print(f'CPU={s.get(\"cpu\")} RAM={s.get(\"ram\")} STORAGE={s.get(\"storage\")} DB_ENABLED={s.get(\"db_enabled\")} DB_TYPE={s.get(\"db_type\")} WEB_ENABLED={s.get(\"web_enabled\")} WEB_TYPE={s.get(\"web_type\")}')")
                        DB_ENABLED=${DB_ENABLED,,} 
                        WEB_ENABLED=${WEB_ENABLED,,}
                        
                        report_progress "$ID" 10 "Leyendo config..."
                        # PASAMOS EL CLIENTE COMO ARGUMENTO $9 (EL ÚLTIMO)
                        bash "$SCRIPT_CUSTOM" "$ID" "$CPU" "$RAM" "$STORAGE" "$DB_ENABLED" "$DB_TYPE" "$WEB_ENABLED" "$WEB_TYPE" "$CLIENTE"
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
                            enable_monitoring "$CLUSTER_PROFILE"
                            update_db_state "$ID" "active"
                        else
                            report_progress "$ID" 0 "Fallo Custom."
                            update_db_state "$ID" "error"
                        fi
                    else
                        update_db_state "$ID" "error"
                    fi
                    ;;
            esac
            
            mv "$pedido" "$pedido.procesado"
        fi 
    done
    sleep 1
done