#!/bin/bash
set -eE -o pipefail

# --- CONFIGURACIÓN DE LOGS ---
LOG_FILE="/tmp/deploy_simple_debug.log"

# --- RUTAS DINÁMICAS ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")" # tofu-k8s -> worker -> raiz

# --- TRAP DE ERRORES ---
handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CRÍTICO (Exit Code: $exit_code) en línea $LINENO.\033[0m"
    echo -e "\033[0;33m--- ÚLTIMAS LÍNEAS DEL LOG ($LOG_FILE) ---\033[0m"
    tail -n 15 "$LOG_FILE"
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo en despliegue. Revisa logs.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    fi
    exit $exit_code
}
trap 'handle_error' ERR

ORDER_ID=$1
RAW_CLIENT_NAME=$2

[ -z "$ORDER_ID" ] && ORDER_ID="manual"

if [ -z "$RAW_CLIENT_NAME" ]; then
    SSH_USER="cliente"
else
    SSH_USER=$(echo "$RAW_CLIENT_NAME" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')
fi
[ -z "$SSH_USER" ] && SSH_USER="cliente"

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

# --- FUNCIÓN STATUS ---
update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo "📊 [Progreso $1%] $2"
}

# --- INICIO ---
echo "--- INICIO LOG BRONCE ($ORDER_ID) ---" > "$LOG_FILE"
update_status 0 "Iniciando Plan Bronce (Simple)..."

# ANTI-ZOMBIES
update_status 5 "Limpiando residuos..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    if [ ! -z "$ZOMBIE" ]; then
        echo "💀 Eliminando zombie: $ZOMBIE" >> "$LOG_FILE"
        minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
    fi
done
rm -f /tmp/juju-* 2>/dev/null

# 20% - Minikube
update_status 20 "Provisionando Minikube..."
(minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=1200m \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check) >> "$LOG_FILE" 2>&1

# 40% - Configuración
update_status 40 "Configurando entorno..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1

# 60% - OpenTofu
update_status 60 "Desplegando infraestructura..."
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" >> "$LOG_FILE" 2>&1

# 85% - Espera
update_status 85 "Verificando servicio SSH..."
kubectl wait --for=condition=available --timeout=120s deployment/ssh-server >> "$LOG_FILE" 2>&1

# 100% - FINALIZADO
update_status 95 "Generando accesos..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
NODE_PORT=$(tofu output -raw ssh_port)
CMD_SSH="ssh $SSH_USER@$HOST_IP -p $NODE_PORT"
INFO_FINAL="[SSH ACCESO]\nUser: $SSH_USER\nPass: $SSH_PASS"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Clúster Bronce Listo!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_FINAL'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Bronce completado."