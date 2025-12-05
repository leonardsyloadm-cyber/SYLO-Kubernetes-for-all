#!/bin/bash

# --- CONFIGURACIÓN ---
BASE_DIR="$HOME/proyecto"
BUZON="$BASE_DIR/buzon-pedidos"

SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_DB="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
SCRIPT_WEB="$BASE_DIR/tofu-k8s/web-ha/deploy_web_ha.sh"

# Asegurar buzón
mkdir -p "$BUZON"
chmod 777 "$BUZON"

# Colores
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - SISTEMA ACTIVO         ${NC}"
echo -e "${BLUE}   Vigilando: $BUZON                            ${NC}"
echo -e "${BLUE}================================================${NC}"

while true; do
    shopt -s nullglob
    for pedido in "$BUZON"/orden_*.json; do
        
        if [ -f "$pedido" ]; then
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA!${NC}"
            echo "📄 Archivo: $(basename "$pedido")"
            
            # Extraer datos
            PLAN_RAW=$(grep -o '"plan":"[^"]*"' "$pedido" | cut -d'"' -f4)
            CLIENTE=$(grep -o '"cliente":"[^"]*"' "$pedido" | cut -d'"' -f4)
            ID=$(grep -o '"id":[^,]*' "$pedido" | cut -d':' -f2 | tr -d ' "')

            echo "👤 Cliente: $CLIENTE"
            echo "📦 Plan: $PLAN_RAW (ID: $ID)"
            
            # --- CEREBRO DE DECISIÓN ---
            case "$PLAN_RAW" in
                "Bronce")
                    echo -e "${YELLOW}🥉 Ejecutando Plan BRONCE${NC}"
                    # PASAMOS EL ID COMO ARGUMENTO
                    bash "$SCRIPT_BRONCE" "$ID"
                    ;;
                "Plata")
                    echo -e "${BLUE}🥈 Ejecutando Plan PLATA${NC}"
                    bash "$SCRIPT_DB"
                    ;;
                "Oro")
                    echo -e "${GREEN}🥇 Ejecutando Plan ORO${NC}"
                    bash "$SCRIPT_DB"
                    bash "$SCRIPT_WEB"
                    ;;
                *)
                    echo -e "${RED}❌ Error: Plan no reconocido.${NC}"
                    ;;
            esac
            
            # Mover a procesados
            mv "$pedido" "$pedido.procesado"
        fi
    done
    sleep 1
done