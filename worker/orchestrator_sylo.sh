#!/bin/bash

# Directorios
BASE_DIR="$HOME/proyecto"
BUZON="$BASE_DIR/buzon-pedidos"
SCRIPT_DB="$BASE_DIR/tofu-k8s/db-ha-automatizada/deploy_db_sylo.sh"

# Colores
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

# Asegurar que el buzón existe y tiene permisos para que Docker escriba
mkdir -p "$BUZON"
chmod 777 "$BUZON"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   🤖 ORQUESTADOR SYLO - ESPERANDO SEÑALES      ${NC}"
echo -e "${BLUE}   Vigilando: $BUZON                            ${NC}"
echo -e "${BLUE}================================================${NC}"

while true; do
    # Buscamos archivos .json en el buzón
    # Usamos ls para ver si hay archivos sin error si está vacío
     if ls "$BUZON"/*.json 1> /dev/null 2>&1; then
        
        for orden in "$BUZON"/*.json; do
            echo ""
            echo -e "${GREEN}📬 ¡NUEVA ORDEN RECIBIDA!${NC}"
            echo "📄 Archivo: $(basename "$orden")"
            
            # Leemos qué plan es (usando grep simple)
            PLAN=$(grep -o '"plan":"[^"]*"' "$orden" | cut -d'"' -f4)
            CLIENTE=$(grep -o '"cliente":"[^"]*"' "$orden" | cut -d'"' -f4)
            
            echo "👤 Cliente: $CLIENTE"
            echo "📦 Plan Solicitado: $PLAN"
            
            if [[ "$PLAN" == "Plata" ]]; then
                echo "🚀 Iniciando script de despliegue de Base de Datos..."
                echo "---------------------------------------------------"
                
                # EJECUTAMOS TU SCRIPT DE INFRAESTRUCTURA AQUÍ
                # Lo ejecutamos directamente para que veas el output en esta pantalla
                bash "$SCRIPT_DB"
                
                echo "---------------------------------------------------"
                echo -e "${GREEN}✅ Despliegue finalizado.${NC}"
            else
                echo "⚠️  Plan '$PLAN' no implementado aún en este orquestador."
            fi
            
            # Borramos la orden para no repetirla
            rm "$orden"
            echo "🗑️  Orden procesada y eliminada del buzón."
            echo "👀 Volviendo a vigilar..."
        done
    fi
    
    sleep 2
done
```

---

### 3. Cómo ponerlo en marcha (3 pasos sencillos)

#### Paso 1: Preparar el Buzón
```bash
mkdir -p ~/proyecto/buzon-pedidos
chmod 777 ~/proyecto/buzon-pedidos
```

#### Paso 2: Lanzar la Web (Conectada al Buzón)
Necesitamos reconstruir el contenedor para que tenga el nuevo PHP, y montarle el volumen del buzón para que pueda dejar los archivos allí.

```bash
cd ~/proyecto

# Borrar viejo
docker rm -f sylo-web

# Construir nuevo PHP
docker build -t sylo-web-php -f sylo-web/Dockerfile .

# LANZAR CON VOLUMEN (Vital)
docker run -d \
  -p 8080:80 \
  --name sylo-web \
  -v ~/proyecto/buzon-pedidos:/buzon \
  sylo-web-php
```

#### Paso 3: Encender el Orquestador
Abre una terminal, ponla visible en tu pantalla y ejecuta:

```bash
chmod +x ~/proyecto/orchestrator.sh
~/proyecto/orchestrator.sh
