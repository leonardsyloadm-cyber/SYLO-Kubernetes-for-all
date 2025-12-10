#!/bin/bash

# ==========================================
# CONFIGURACIÓN GENERAL
# ==========================================
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)" # Detecta la raíz del proyecto automáticamente
BUZON="$BASE_DIR/buzon-pedidos"

# Configuración de Base de Datos (Para sincronizar estado con la web)
DB_CONTAINER="kylo-main-db"
DB_USER="sylo_app"
DB_PASS="sylo_app_pass"
DB_NAME="kylo_main_db"

# Rutas a los scripts
SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_PLATA="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
SCRIPT_ORO="$BASE_DIR/tofu-k8s/full-stack/deploy_oro.sh"
SCRIPT_CUSTOM="$BASE_DIR/tofu-k8s/custom-stack/deploy_custom.sh"

# Colores
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

mkdir -p "$BUZON"
chmod 777 "$BUZON" 2>/dev/null

# --- FUNCIÓN NUEVA: Solo actualiza el estado (Sin adornos) ---
update_status() {
    local id=$1
    local status=$2
    # Actualizamos silenciosamente la DB
    docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" --silent --skip-column-names \
    -e "UPDATE orders SET status='$status' WHERE id=$id;"
}

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - MONITORIZANDO (LIVE)   ${NC}"
echo -e "${BLUE}   Vigilando carpeta: $BUZON                    ${NC}"
echo -e "${BLUE}================================================${NC}"

# Loop infinito
while true; do
    shopt -s nullglob
    for pedido in "$BUZON"/orden_*.json; do
        if [ -f "$pedido" ]; then
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA! Procesando: $(basename "$pedido")${NC}"
            
            # Usamos Python para leer el JSON de forma segura y robusta
            # Esto extrae las variables básicas
            eval $(python3 -c "import json; d=json.load(open('$pedido')); print(f'PLAN_RAW={d.get(\"plan\")} ID={d.get(\"id\")}')")
            
            echo "📦 Plan detectado: $PLAN_RAW"
            echo "🆔 ID del pedido:  $ID"

            # 1. CAMBIO DE ESTADO: CREATING (Para que la web sepa que estamos trabajando)
            update_status "$ID" "creating"

            case "$PLAN_RAW" in
                "Bronce")
                    if [ -f "$SCRIPT_BRONCE" ]; then
                        bash "$SCRIPT_BRONCE" "$ID"
                        
                        # Verificamos si salió bien
                        if [ $? -eq 0 ]; then
                            update_status "$ID" "active"
                        else
                            update_status "$ID" "error"
                        fi
                    fi
                    ;;
                    
                "Plata")
                    if [ -f "$SCRIPT_PLATA" ]; then
                        # Entramos al directorio para evitar errores de Tofu
                        (cd "$(dirname "$SCRIPT_PLATA")" && bash "./$(basename "$SCRIPT_PLATA")" "$ID")
                        
                        if [ $? -eq 0 ]; then
                            update_status "$ID" "active"
                        else
                            update_status "$ID" "error"
                        fi
                    fi
                    ;;
                
                "Oro")
                    if [ -f "$SCRIPT_ORO" ]; then
                        bash "$SCRIPT_ORO" "$ID"
                        
                        if [ $? -eq 0 ]; then
                            update_status "$ID" "active"
                        else
                            update_status "$ID" "error"
                        fi
                    fi
                    ;;

                "Personalizado")
                    if [ -f "$SCRIPT_CUSTOM" ]; then
                        echo -e "${BLUE}🎨 Iniciando Plan PERSONALIZADO...${NC}"
                        
                        # Extraemos los detalles técnicos (CPU, RAM, etc) usando Python
                        eval $(python3 -c "import json; d=json.load(open('$pedido')); s=d.get('specs',{}); print(f'CPU={s.get(\"cpu\")} RAM={s.get(\"ram\")} STORAGE={s.get(\"storage\")} DB_ENABLED={s.get(\"db_enabled\")} DB_TYPE={s.get(\"db_type\")} WEB_ENABLED={s.get(\"web_enabled\")} WEB_TYPE={s.get(\"web_type\")}')")

                        # Convertimos los booleanos
                        DB_ENABLED=${DB_ENABLED,,} 
                        WEB_ENABLED=${WEB_ENABLED,,}

                        echo "   ⚙️ Specs: CPU=$CPU | RAM=$RAM | DB=$DB_ENABLED | WEB=$WEB_ENABLED"
                        
                        # Ejecutamos pasando TODOS los argumentos
                        bash "$SCRIPT_CUSTOM" "$ID" "$CPU" "$RAM" "$STORAGE" "$DB_ENABLED" "$DB_TYPE" "$WEB_ENABLED" "$WEB_TYPE"
                        
                        if [ $? -eq 0 ]; then
                            update_status "$ID" "active"
                        else
                            update_status "$ID" "error"
                        fi
                    else
                        echo -e "${RED}❌ No encuentro el script custom: $SCRIPT_CUSTOM${NC}"
                        update_status "$ID" "error"
                    fi
                    ;;
                    
                *)
                    echo -e "${RED}⚠️  Plan desconocido: $PLAN_RAW${NC}"
                    update_status "$ID" "error"
                    ;;
            esac
            
            # Movemos a procesado
            mv "$pedido" "$pedido.procesado"
            echo -e "${BLUE}💤 Esperando siguiente pedido...${NC}"
        fi 
    done
    sleep 2
done