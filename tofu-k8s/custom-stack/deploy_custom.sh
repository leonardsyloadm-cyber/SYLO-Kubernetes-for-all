#!/bin/bash
set -eE -o pipefail

# --- CONFIGURACIÓN DE LOGS ---
LOG_FILE="/tmp/deploy_custom_debug.log"

# --- TRAP DE ERRORES ---
handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CRÍTICO (Exit Code: $exit_code) en línea $LINENO.\033[0m"
    echo -e "\033[0;33m--- ÚLTIMAS LÍNEAS DEL LOG DE ERROR ($LOG_FILE) ---\033[0m"
    tail -n 20 "$LOG_FILE"
    echo "{\"percent\": 100, \"message\": \"Fallo Crítico. Revisa la consola.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    exit $exit_code
}
trap 'handle_error' ERR

# --- 1. RECEPCIÓN DE ARGUMENTOS ---
cd "$(dirname "$0")"

ORDER_ID=$1
CPU_REQ=$2
RAM_REQ=$3
STORAGE_REQ=$4
DB_ENABLED=$5
DB_TYPE=$6
WEB_ENABLED=$7
WEB_TYPE=$8

# Validación básica
[ -z "$ORDER_ID" ] && ORDER_ID="manual"
[ -z "$CPU_REQ" ] && CPU_REQ="2"
[ -z "$RAM_REQ" ] && RAM_REQ="4"

CLUSTER_NAME="ClienteCustom-$ORDER_ID"
BUZON_STATUS="$HOME/proyecto/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

# --- FUNCIONES ---
update_status() {
    echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    echo -e "📊 \033[1;33m[Progreso $1%]\033[0m $2"
}

# --- INICIO ---
echo "--- INICIO DEL LOG CUSTOM ---" > "$LOG_FILE"
update_status 0 "Iniciando Plan PERSONALIZADO..."

# 2. CÁLCULO DE RECURSOS PARA MINIKUBE
# Minikube necesita lo que pide el cliente + un poco de overhead para K8s
# Si el cliente pide 4GB, le damos 5GB a la VM.
VM_CPU=$((CPU_REQ + 1))
VM_RAM_MB=$((RAM_REQ * 1024 + 1024)) 

echo "🔧 Configuración solicitada: CPU=$CPU_REQ, RAM=${RAM_REQ}GB" >> "$LOG_FILE"
echo "🔧 Configuración VM Host: CPU=$VM_CPU, RAM=${VM_RAM_MB}MB" >> "$LOG_FILE"

# 3. LIMPIEZA
echo "🧹 Limpiando sistema..." >> "$LOG_FILE"
sudo rm -f /tmp/juju-* 2>/dev/null || true
sudo rm -rf /tmp/minikube.* 2>/dev/null || true
if [ -f /proc/sys/fs/protected_regular ]; then
    sudo sysctl fs.protected_regular=0 >> "$LOG_FILE" 2>&1
fi

if minikube profile list 2>/dev/null | grep -q "$CLUSTER_NAME"; then
    update_status 5 "Limpiando despliegue anterior..."
    sudo minikube delete -p "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
fi

# 4. LEVANTAR MINIKUBE ADAPTATIVO
update_status 20 "Levantando Cluster (${CPU_REQ}vCPU / ${RAM_REQ}GB)..."

sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus="$VM_CPU" \
    --memory="${VM_RAM_MB}m" \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check >> "$LOG_FILE" 2>&1

update_status 40 "Configurando kubectl..."
{
    sudo rm -rf "$HOME/.minikube"
    sudo cp -r /root/.minikube "$HOME/"
    sudo chown -R "$USER":"$USER" "$HOME/.minikube"
    mkdir -p "$HOME/.kube"
    sudo cp /root/.kube/config "$HOME/.kube/config"
    sudo chown "$USER":"$USER" "$HOME/.kube/config"
    sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
    kubectl config use-context "$CLUSTER_NAME"
} >> "$LOG_FILE" 2>&1

# 5. DESPLIEGUE TOFU CON VARIABLES DINÁMICAS
update_status 50 "Aplicando configuración a medida..."

rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# Pasamos TODAS las variables al main.tf
tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="cpu=$CPU_REQ" \
    -var="ram=$RAM_REQ" \
    -var="storage=$STORAGE_REQ" \
    -var="db_enabled=$DB_ENABLED" \
    -var="db_type=$DB_TYPE" \
    -var="web_enabled=$WEB_ENABLED" \
    -var="web_type=$WEB_TYPE" >> "$LOG_FILE" 2>&1

# 6. ESPERAS CONDICIONALES
update_status 70 "Esperando arranque de recursos..."

# Si activó DB, esperamos por la DB
if [ "$DB_ENABLED" = "true" ]; then
    echo "⏳ Esperando Base de Datos..." >> "$LOG_FILE"
    kubectl wait --for=condition=Ready pod -l app=custom-db --timeout=300s >> "$LOG_FILE" 2>&1 || true
fi

# Si activó Web, esperamos por la Web
if [ "$WEB_ENABLED" = "true" ]; then
    echo "⏳ Esperando Servidor Web..." >> "$LOG_FILE"
    kubectl wait --for=condition=available deployment/custom-web --timeout=300s >> "$LOG_FILE" 2>&1 || true
fi

# 7. FINALIZACIÓN Y REPORTE
update_status 90 "Obteniendo datos de acceso..."

HOST_IP=$(sudo minikube ip -p "$CLUSTER_NAME")

# Obtenemos puertos de forma segura (pueden ser N/A si no se desplegó)
WEB_PORT=$(tofu output -raw web_port)
SSH_PORT=$(tofu output -raw ssh_port)

# Formateo del mensaje final
INFO_TEXT="[CONFIGURACIÓN]\nCPU: ${CPU_REQ} / RAM: ${RAM_REQ}GB / HDD: ${STORAGE_REQ}GB\n"

if [ "$WEB_ENABLED" = "true" ]; then
    WEB_URL="http://$HOST_IP:$WEB_PORT"
    CMD_SSH="ssh cliente@$HOST_IP -p $SSH_PORT"
    INFO_TEXT="${INFO_TEXT}\n[ACCESO WEB]\n$WEB_URL\n\n[ACCESO SSH]\nUser: cliente\nPass: $SSH_PASS"
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