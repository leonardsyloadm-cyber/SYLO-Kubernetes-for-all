#!/bin/bash

# ==========================================
# ORQUESTADOR SYLO (FINAL - RESPETANDO SCRIPTS)
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
NC='\033[0m'

mkdir -p "$BUZON"
chmod 777 "$BUZON" 2>/dev/null

# --- FUNCIÓN REPORTAR (Solo para pasos intermedios) ---
report_progress() {
    local id=$1
    local percent=$2
    local msg=$3
    echo "{\"percent\": $percent, \"message\": \"$msg\", \"status\": \"creating\"}" > "$BUZON/status_$id.json"
    chmod 777 "$BUZON/status_$id.json" 2>/dev/null
}

# --- FUNCIÓN ACTUALIZAR DB (Estado final) ---
update_db_state() {
    local id=$1
    local status=$2
    docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent --skip-column-names \
    -e "UPDATE orders SET status='$status' WHERE id=$id;"
}

echo -e "${BLUE}=== ORQUESTADOR LIVE ===${NC}"

while true; do
    shopt -s nullglob
    for pedido in "$BUZON"/orden_*.json; do
        if [ -f "$pedido" ]; then
            eval $(python3 -c "import json; d=json.load(open('$pedido')); print(f'PLAN_RAW={d.get(\"plan\")} ID={d.get(\"id\")}')")
            
            echo -e "${GREEN}👉 Procesando ID: $ID | Plan: $PLAN_RAW${NC}"

            update_db_state "$ID" "creating"
            
            # Progreso inicial
            report_progress "$ID" 5 "Inicializando..."

            case "$PLAN_RAW" in
                "Bronce")
                    if [ -f "$SCRIPT_BRONCE" ]; then
                        report_progress "$ID" 10 "Iniciando Bronce..."
                        bash "$SCRIPT_BRONCE" "$ID"
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
                            # ¡NO SOBREESCRIBIMOS EL 100%! El script ya lo hizo.
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
                        (cd "$(dirname "$SCRIPT_PLATA")" && bash "./$(basename "$SCRIPT_PLATA")" "$ID")
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
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
                        bash "$SCRIPT_ORO" "$ID"
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
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
                        bash "$SCRIPT_CUSTOM" "$ID" "$CPU" "$RAM" "$STORAGE" "$DB_ENABLED" "$DB_TYPE" "$WEB_ENABLED" "$WEB_TYPE"
                        RET=$?
                        
                        if [ $RET -eq 0 ]; then
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