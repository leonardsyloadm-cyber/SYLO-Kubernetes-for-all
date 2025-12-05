#!/bin/bash

# --- CONFIGURACIÓN DE RUTAS ---
BASE_DIR="$HOME/proyecto"
BUZON="$BASE_DIR/buzon-pedidos"

# Rutas a los scripts de cada plan
SCRIPT_BRONCE="$BASE_DIR/tofu-k8s/k8s-simple/deploy_simple.sh"
SCRIPT_PLATA="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"
# SCRIPT_ORO (Aún no existe, usaremos el de Plata como placeholder o un echo)

# Asegurar que el buzón existe y tiene permisos
mkdir -p "$BUZON"
chmod 777 "$BUZON"

# Colores para que se vea bonito en la terminal
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - ESPERANDO SEÑALES      ${NC}"
echo -e "${BLUE}   Vigilando: $BUZON                            ${NC}"
echo -e "${BLUE}================================================${NC}"

while true; do
    # Buscamos archivos .json
    # shopt -s nullglob evita errores si no hay archivos
    shopt -s nullglob
    for pedido in "$BUZON"/*.json; do
        
        # Comprobamos si el archivo existe (por si acaso se borró justo antes)
        if [ -f "$pedido" ]; then
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA!${NC}"
            echo "📄 Archivo: $(basename "$pedido")"
            
            # Extraemos los datos del JSON usando grep y cut (método universal sin jq)
            # El JSON es tipo: {"plan":"Plata", "cliente":"Usuario", ...}
            
            PLAN_RAW=$(grep -o '"plan":"[^"]*"' "$pedido" | cut -d'"' -f4)
            CLIENTE=$(grep -o '"cliente":"[^"]*"' "$pedido" | cut -d'"' -f4)
            ID=$(grep -o '"id":[^,]*' "$pedido" | cut -d':' -f2 | tr -d ' "')

            echo "👤 Cliente: $CLIENTE"
            echo "📦 Plan Solicitado: $PLAN_RAW"
            echo "🆔 ID Pedido: $ID"
            
            # Convertimos a mayúsculas la primera letra por si acaso (Plata, plata, PLATA)
            # O simplemente comparamos strings
            
            echo "🚀 Iniciando script de despliegue..."
            echo "---------------------------------------------------"
            
            # --- CEREBRO DE DECISIÓN ---
            case "$PLAN_RAW" in
                "Bronce")
                    echo -e "${YELLOW}🥉 Ejecutando protocolo: KUBERNETES SIMPLE${NC}"
                    if [ -f "$SCRIPT_BRONCE" ]; then
                        bash "$SCRIPT_BRONCE"
                    else
                        echo -e "${RED}❌ Error: Script Bronce no encontrado en $SCRIPT_BRONCE${NC}"
                    fi
                    ;;
                    
                "Plata")
                    echo -e "${BLUE}🥈 Ejecutando protocolo: KUBERNETES + DB HA (Replicada)${NC}"
                    if [ -f "$SCRIPT_PLATA" ]; then
                        bash "$SCRIPT_PLATA"
                    else
                        echo -e "${RED}❌ Error: Script Plata no encontrado en $SCRIPT_PLATA${NC}"
                    fi
                    ;;
                    
                "Oro")
                    echo -e "${YELLOW}🥇 Ejecutando protocolo: FULL STACK (Web + DB)${NC}"
                    echo "⚠️  (Script ORO en desarrollo... simulando con Plata)"
                    if [ -f "$SCRIPT_PLATA" ]; then
                        bash "$SCRIPT_PLATA"
                    fi
                    ;;
                    
                *)
                    echo -e "${RED}❌ Error: Plan '$PLAN_RAW' no reconocido.${NC}"
                    ;;
            esac
            
            echo "---------------------------------------------------"
            
            # Movemos el pedido a "procesados" para no repetirlo en el bucle infinito
            # Cambiamos la extensión a .json.procesado
            mv "$pedido" "$pedido.procesado"
            
            echo "🗑️  Orden procesada y archivada."
            echo "👀 Volviendo a vigilar..."
        fi
        
    done
    
    # Descanso para no saturar la CPU (vigila cada 2 segundos)
    sleep 2
done
