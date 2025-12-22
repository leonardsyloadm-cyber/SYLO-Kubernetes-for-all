#!/bin/bash
set -eE -o pipefail

# --- CONFIGURACIÓN DE LOGS (Usuario actual) ---
LOG_FILE="/tmp/deploy_custom_debug.log"

# --- 1. RECEPCIÓN DE ARGUMENTOS ---
# Detectamos dónde estamos para ser portables
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")" # Subimos dos niveles (tofu-k8s -> worker -> raiz)
# Alternativa si la estructura es proyecto/tofu-k8s/custom-stack/:
# PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

ORDER_ID=$1
CPU_REQ=$2
RAM_REQ=$3
STORAGE_REQ=$4
DB_ENABLED=$5
DB_TYPE=$6
WEB_ENABLED=$7
WEB_TYPE=$8
RAW_CLIENT_NAME=$9

# Validación básica
[ -z "$ORDER_ID" ] && ORDER_ID="manual"
[ -z "$CPU_REQ" ] && CPU_REQ="2"
[ -z "$RAM_REQ" ] && RAM_REQ="4"

# --- SANITIZACIÓN DE USUARIO SSH ---
if [ -z "$RAW_CLIENT_NAME" ]; then
    SSH_USER="cliente"
else
    SSH_USER=$(echo "$RAW_CLIENT_NAME" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')
fi
[ -z "$SSH_USER" ] && SSH_USER="cliente"

# NOMBRE DEL CLUSTER Y RUTAS DINÁMICAS
CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

# --- TRAP DE ERRORES ---
handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CRÍTICO (Exit Code: $exit_code) en línea $LINENO.\033[0m"
    echo -e "\033[0;33m--- ÚLTIMAS LÍNEAS DEL LOG DE ERROR ($LOG_FILE) ---\033[0m"
    tail -n 20 "$LOG_FILE"
    # Intentamos escribir en el buzón si existe la ruta
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo Crítico. Revisa la consola.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    fi
    exit $exit_code
}
trap 'handle_error' ERR

# --- FUNCIONES ---
update_status() {
    # Solo escribimos si el directorio existe
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo -e "📊 \033[1;33m[Progreso $1%]\033[0m $2"
}

# --- INICIO ---
echo "--- INICIO DEL LOG CUSTOM ($ORDER_ID) ---" > "$LOG_FILE"

# ==========================================
# 🛑 BLOQUE ANTI-ZOMBIES
# ==========================================
update_status 5 "Escaneando procesos zombies..."
echo "🧹 Buscando residuos del Cliente #$ORDER_ID..." >> "$LOG_FILE"

ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)

for ZOMBIE in $ZOMBIES; do
    if [ ! -z "$ZOMBIE" ]; then
        echo "💀 Detectado zombie: $ZOMBIE. Eliminando..." >> "$LOG_FILE"
        minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
    fi
done

rm -f /tmp/sylo_web_${ORDER_ID}.html
# ==========================================

# 2. CÁLCULO DE RECURSOS
update_status 10 "Calculando recursos..."
VM_CPU=$((CPU_REQ + 1))
VM_RAM_MB=$((RAM_REQ * 1024 + 1024)) 

echo "🔧 Configuración: CPU=$CPU_REQ, RAM=${RAM_REQ}GB, User=$SSH_USER" >> "$LOG_FILE"

# 3. LIMPIEZA DE SISTEMA (Solo temporales de usuario)
echo "🧹 Limpiando sistema archivos..." >> "$LOG_FILE"
rm -f /tmp/juju-* 2>/dev/null || true
rm -rf /tmp/minikube.* 2>/dev/null || true

# 4. LEVANTAR MINIKUBE
update_status 20 "Levantando Cluster Limpio ($CLUSTER_NAME)..."

# Usamos minikube sin sudo si es posible (recomendado), o con sudo si el usuario lo requiere por Docker
# Asumimos que el usuario tiene permisos en el grupo docker
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus="$VM_CPU" \
    --memory="${VM_RAM_MB}m" \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check >> "$LOG_FILE" 2>&1

update_status 40 "Configurando kubectl..."
# Minikube configura automáticamente ~/.kube/config del usuario actual al iniciar.
# No hace falta copiar de /root/ si no usamos sudo.
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1

# 5. DESPLIEGUE TOFU
update_status 50 "Aplicando infraestructura..."
cd "$SCRIPT_DIR" # Aseguramos estar en la carpeta del .tf
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="cpu=$CPU_REQ" \
    -var="ram=$RAM_REQ" \
    -var="storage=$STORAGE_REQ" \
    -var="db_enabled=$DB_ENABLED" \
    -var="db_type=$DB_TYPE" \
    -var="web_enabled=$WEB_ENABLED" \
    -var="web_type=$WEB_TYPE" >> "$LOG_FILE" 2>&1

# 6. ESPERAS
update_status 70 "Esperando arranque de servicios..."
if [ "$DB_ENABLED" = "true" ]; then
    echo "⏳ Esperando Base de Datos..." >> "$LOG_FILE"
    kubectl wait --for=condition=Ready pod -l app=custom-db --timeout=300s >> "$LOG_FILE" 2>&1 || true
fi
if [ "$WEB_ENABLED" = "true" ]; then
    echo "⏳ Esperando Servidor Web..." >> "$LOG_FILE"
    kubectl wait --for=condition=available deployment/custom-web --timeout=300s >> "$LOG_FILE" 2>&1 || true
fi

# 7. FINALIZACIÓN
update_status 90 "Generando credenciales..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
WEB_PORT=$(tofu output -raw web_port)
SSH_PORT=$(tofu output -raw ssh_port)

INFO_TEXT="[CONFIGURACIÓN]\nCPU: ${CPU_REQ} / RAM: ${RAM_REQ}GB / HDD: ${STORAGE_REQ}GB\n"

if [ "$WEB_ENABLED" = "true" ]; then
    WEB_URL="http://$HOST_IP:$WEB_PORT"
    CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT"
    INFO_TEXT="${INFO_TEXT}\n[ACCESO WEB]\n$WEB_URL\n\n[ACCESO SSH]\nUser: $SSH_USER\nPass: $SSH_PASS"
else
    CMD_SSH="N/A"
    INFO_TEXT="${INFO_TEXT}\n[WEB/SSH]\nNo solicitado."
fi

if [ "$DB_ENABLED" = "true" ]; then
    INFO_TEXT="${INFO_TEXT}\n\n[BASE DE DATOS]\nTipo: $DB_TYPE\nHost Interno: custom-db-service"
else
    INFO_TEXT="${INFO_TEXT}\n\n[BASE DE DATOS]\nNo solicitada."
fi

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Despliegue PERSONALIZADO Completado!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_TEXT'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo -e "✅ \033[0;32mDespliegue Custom completado con éxito.\033[0m"