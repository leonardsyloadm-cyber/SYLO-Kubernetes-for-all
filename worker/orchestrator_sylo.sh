#!/bin/bash

# --- CONFIGURACIÓN DE RUTAS (AJUSTADAS A TU ÁRBOL) ---
BASE_DIR="$HOME/proyecto"
BUZON="$BASE_DIR/buzon-pedidos"

# Rutas exactas a tus scripts
SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_DB="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
# Fíjate que aquí apunto a la carpeta que sale en tu imagen "web-ha-automatizada"
SCRIPT_WEB="$BASE_DIR/tofu-k8s/web-ha-automatizada/deploy_web_ha.sh"

# Asegurar que el buzón existe
mkdir -p "$BUZON"
chmod 777 "$BUZON"

# Colores
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - LISTO PARA TRABAJAR    ${NC}"
echo -e "${BLUE}   Vigilando: $BUZON                            ${NC}"
echo -e "${BLUE}================================================${NC}"

while true; do
    shopt -s nullglob
    for pedido in "$BUZON"/*.json; do
        
        if [ -f "$pedido" ]; then
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA!${NC}"
            echo "📄 Archivo: $(basename "$pedido")"
            
            PLAN_RAW=$(grep -o '"plan":"[^"]*"' "$pedido" | cut -d'"' -f4)
            CLIENTE=$(grep -o '"cliente":"[^"]*"' "$pedido" | cut -d'"' -f4)
            
            echo "👤 Cliente: $CLIENTE"
            echo "📦 Plan Solicitado: $PLAN_RAW"
            
            echo "🚀 Iniciando despliegue..."
            echo "---------------------------------------------------"
            
            case "$PLAN_RAW" in
                "Bronce")
                    echo -e "${YELLOW}🥉 Ejecutando Plan BRONCE (Cluster Base)${NC}"
                    bash "$SCRIPT_BRONCE"
                    ;;
                    
                "Plata")
                    echo -e "${BLUE}🥈 Ejecutando Plan PLATA (DB HA)${NC}"
                    bash "$SCRIPT_DB"
                    ;;
                
                "Oro")
                    echo -e "${GREEN}🥇 Ejecutando Plan ORO (WEB HA + DB HA)${NC}"
                    
                    # NOTA: En esta versión, ejecutamos el script WEB HA como demostración del Plan Oro
                    # (Crea el cluster ClienteWeb-XXXX con Nginx Replicado)
                    
                    if [ -f "$SCRIPT_WEB" ]; then
                        bash "$SCRIPT_WEB"
                    else
                        echo -e "${RED}❌ Error: No encuentro el script en: $SCRIPT_WEB${NC}"
                    fi
                    ;;
                    
                *)
                    echo -e "${RED}❌ Error: Plan '$PLAN_RAW' no reconocido.${NC}"
                    ;;
            esac
            
            echo "---------------------------------------------------"
            mv "$pedido" "$pedido.procesado"
            echo "🗑️  Orden procesada y archivada."
            echo "👀 Esperando siguientes pedidos..."
        fi
        
    done
    sleep 2
done