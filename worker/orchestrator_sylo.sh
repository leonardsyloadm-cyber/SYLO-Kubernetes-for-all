#!/bin/bash

# --- CONFIGURACIÓN DE RUTAS ---
BASE_DIR="$HOME/proyecto"
BUZON="$BASE_DIR/buzon-pedidos"

# Rutas a los scripts de despliegue (Ajustadas a tu estructura)
SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_PLATA="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
SCRIPT_ORO="$BASE_DIR/tofu-k8s/full-stack/deploy_oro.sh"

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
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - MONITORIZANDO          ${NC}"
echo -e "${BLUE}   Vigilando: $BUZON                            ${NC}"
echo -e "${BLUE}================================================${NC}"

while true; do
    # Buscamos archivos .json
    shopt -s nullglob
    for pedido in "$BUZON"/orden_*.json; do
        
        if [ -f "$pedido" ]; then
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA!${NC}"
            echo "📄 Archivo: $(basename "$pedido")"
            
            # Extraer datos del JSON
            PLAN_RAW=$(grep -o '"plan":"[^"]*"' "$pedido" | cut -d'"' -f4)
            CLIENTE=$(grep -o '"cliente":"[^"]*"' "$pedido" | cut -d'"' -f4)
            # Extraemos el ID numérico (ej: 17145...)
            ID=$(grep -o '"id":[^,]*' "$pedido" | cut -d':' -f2 | tr -d ' "')

            echo "👤 Cliente: $CLIENTE"
            echo "📦 Plan Solicitado: $PLAN_RAW"
            echo "🆔 ID Orden: $ID"
            
            echo "🚀 Iniciando script de despliegue..."
            echo "---------------------------------------------------"
            
            # --- CEREBRO DE DECISIÓN ---
            case "$PLAN_RAW" in
                "Bronce")
                    if [ -f "$SCRIPT_BRONCE" ]; then
                        echo -e "${YELLOW}🥉 Ejecutando Plan BRONCE (Script Simple)${NC}"
                        # Pasamos el ID como argumento para que el script actualice el status
                        bash "$SCRIPT_BRONCE" "$ID"
                    else
                        echo -e "${RED}❌ Error: Script Bronce no encontrado en $SCRIPT_BRONCE${NC}"
                    fi
                    ;;
                    
                "Plata")
                    if [ -f "$SCRIPT_PLATA" ]; then
                        echo -e "${BLUE}🥈 Ejecutando Plan PLATA (DB HA)${NC}"
                        bash "$SCRIPT_PLATA" "$ID"
                    else
                        echo -e "${RED}❌ Error: Script Plata no encontrado en $SCRIPT_PLATA${NC}"
                    fi
                    ;;
                
                "Oro")
                    if [ -f "$SCRIPT_ORO" ]; then
                        echo -e "${GREEN}🥇 Ejecutando Plan ORO (Full Stack)${NC}"
                        bash "$SCRIPT_ORO" "$ID"
                    else
                        echo -e "${RED}❌ Error: Script Oro no encontrado en $SCRIPT_ORO${NC}"
                    fi
                    ;;
                    
                *)
                    echo -e "${RED}❌ Error: Plan '$PLAN_RAW' no reconocido.${NC}"
                    ;;
            esac
            
            echo "---------------------------------------------------"
            
            # Movemos el pedido a "procesados" para no repetirlo
            mv "$pedido" "$pedido.procesado"
            
            echo "🗑️  Orden procesada y archivada."
            echo "👀 Volviendo a vigilar..."
        fi
        
    done
    
    # Descanso de 1 segundo para no saturar CPU
    sleep 1
done