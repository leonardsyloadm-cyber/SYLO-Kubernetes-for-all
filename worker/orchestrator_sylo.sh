#!/bin/bash

# ==========================================
# CONFIGURACIÓN GENERAL
# ==========================================
# Ajusta BASE_DIR si tu proyecto está en otro lado
BASE_DIR="$HOME/proyecto"
BUZON="$BASE_DIR/buzon-pedidos"

# Rutas ABSOLUTAS a los scripts de despliegue
# Asegúrate de que estos archivos existen en estas rutas exactas
SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_PLATA="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
SCRIPT_ORO="$BASE_DIR/tofu-k8s/full-stack/deploy_oro.sh"

# Colores para que se vea bonito y claro
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

# Aseguramos que el buzón existe
mkdir -p "$BUZON"
chmod 777 "$BUZON" 2>/dev/null

# ==========================================
# FUNCIÓN DE EJECUCIÓN (EL CORAZÓN DEL SCRIPT)
# ==========================================
ejecutar_despliegue() {
    local script_path=$1
    local id_pedido=$2
    local nombre_plan=$3

    if [ -f "$script_path" ]; then
        echo -e "${BLUE}▶ Iniciando despliegue del Plan ${nombre_plan}...${NC}"
        echo -e "${BLUE}▶ Script: $script_path${NC}"
        
        # 1. Obtenemos el directorio donde vive el script
        local work_dir=$(dirname "$script_path")
        local script_name=$(basename "$script_path")
        
        # 2. Ejecutamos en una sub-shell para aislar el entorno
        (
            # Entramos al directorio del script para que Tofu encuentre los .tf
            # y para que las rutas relativas (../../sylo-web/init.sql) funcionen.
            cd "$work_dir" || exit 1
            
            echo -e "${YELLOW}📂 Directorio de trabajo cambiado a: $(pwd)${NC}"
            
            # Damos permisos por si acaso
            chmod +x "$script_name"
            
            # 3. EJECUTAMOS EL SCRIPT
            # Al no poner '&' ni redirigir a /dev/null, verás TODO el output en pantalla
            ./"$script_name" "$id_pedido"
        )
        
        # Capturamos si salió bien o mal
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✅ Plan ${nombre_plan} desplegado con éxito.${NC}"
        else
            echo -e "${RED}❌ Error al ejecutar el Plan ${nombre_plan}.${NC}"
        fi
    else
        echo -e "${RED}❌ Error Crítico: No encuentro el script en: $script_path${NC}"
    fi
}

# ==========================================
# BUCLE PRINCIPAL
# ==========================================
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - MONITORIZANDO (LIVE)   ${NC}"
echo -e "${BLUE}   Vigilando carpeta: $BUZON                    ${NC}"
echo -e "${BLUE}================================================${NC}"

while true; do
    # shopt -s nullglob evita que el loop corra si no hay archivos
    shopt -s nullglob
    for pedido in "$BUZON"/orden_*.json; do
        
        if [ -f "$pedido" ]; then
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA! Procesando: $(basename "$pedido")${NC}"
            
            # Extracción robusta de datos usando grep y cut
            PLAN_RAW=$(grep -o '"plan":"[^"]*"' "$pedido" | cut -d'"' -f4)
            ID=$(grep -o '"id":[^,]*' "$pedido" | cut -d':' -f2 | tr -d ' "')

            echo "📦 Plan detectado: $PLAN_RAW"
            echo "🆔 ID del pedido:  $ID"
            
            case "$PLAN_RAW" in
                "Bronce")
                    ejecutar_despliegue "$SCRIPT_BRONCE" "$ID" "BRONCE"
                    ;;
                    
                "Plata")
                    ejecutar_despliegue "$SCRIPT_PLATA" "$ID" "PLATA"
                    ;;
                
                "Oro")
                    ejecutar_despliegue "$SCRIPT_ORO" "$ID" "ORO"
                    ;;
                    
                *)
                    echo -e "${RED}⚠️  Plan desconocido: $PLAN_RAW${NC}"
                    ;;
            esac
            
            # Marcamos como procesado para no volver a leerlo
            mv "$pedido" "$pedido.procesado"
            echo -e "${BLUE}💤 Esperando siguiente pedido...${NC}"
        fi 
    done
    sleep 2
done