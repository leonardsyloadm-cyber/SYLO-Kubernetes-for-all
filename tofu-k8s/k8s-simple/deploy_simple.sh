#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY BRONCE (SOLO SSH) - V15
# ==========================================

LOG_FILE="/tmp/deploy_simple_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR BRONCE (Exit: $exit_code). Ver log: $LOG_FILE\033[0m"
    BUZON_ERR="$PROJECT_ROOT/buzon-pedidos/status_$1.json"
    if [ -d "$(dirname "$BUZON_ERR")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo crítico Bronce.\", \"status\": \"error\"}" > "$BUZON_ERR"
    fi
    exit $exit_code
}
trap 'handle_error $1' ERR

# --- 1. RECEPCIÓN DE ARGUMENTOS (V15) ---
ORDER_ID=$1
SSH_USER_ARG=$2
OS_IMAGE_ARG=$3
# Ignoramos DB y Web en Bronce, pero los recibimos para que no descuadre
DB_NAME_ARG=$4
WEB_NAME_ARG=$5
SUBDOMAIN_ARG=$6

# --- SANITIZACIÓN ---
[ -z "$ORDER_ID" ] && ORDER_ID="manual"
if [ -z "$SSH_USER_ARG" ]; then SSH_USER="admin_bronce"; else SSH_USER="$SSH_USER_ARG"; fi
if [ -z "$SUBDOMAIN_ARG" ]; then SUBDOMAIN="cliente$ORDER_ID"; else SUBDOMAIN="$SUBDOMAIN_ARG"; fi

# Selección de Imagen (Bronce suele ser Alpine, pero permitimos Ubuntu si el usuario insiste)
IMAGE_OS="alpine:latest" 
if [ "$OS_IMAGE_ARG" == "ubuntu" ]; then IMAGE_OS="ubuntu:latest"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo "📊 $2"
}

# --- INICIO ---
echo "--- LOG BRONCE ($ORDER_ID) ---" > "$LOG_FILE"
echo "Params: User=$SSH_USER | OS=$IMAGE_OS | Dom=$SUBDOMAIN" >> "$LOG_FILE"

update_status 0 "Iniciando Plan Bronce (SSH)..."

# Limpieza
update_status 10 "Limpiando residuos..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
done

# Minikube (Bronce es ligero: 1CPU/1GB)
update_status 20 "Levantando Minikube..."
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=1 \
    --memory=1024m \
    --addons=default-storageclass \
    --force \
    --no-vtx-check >> "$LOG_FILE" 2>&1

update_status 40 "Configurando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# Apply (Pasamos variables nuevas)
update_status 50 "Desplegando Infraestructura..."
tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="os_image=$IMAGE_OS" \
    -var="subdomain=$SUBDOMAIN" >> "$LOG_FILE" 2>&1

# Espera
update_status 70 "Esperando Servicio SSH..."
kubectl --context "$CLUSTER_NAME" wait --for=condition=available deployment/ssh-server --timeout=120s >> "$LOG_FILE" 2>&1

# Final
update_status 90 "Generando accesos..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
NODE_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "Revisar")

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $NODE_PORT"
INFO_FINAL="[PLAN BRONCE]\nSubdominio: $SUBDOMAIN.sylobi.org\nOS Base: $IMAGE_OS\n\n[SSH ACCESO]\nUser: $SSH_USER\nPass: $SSH_PASS"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Bronce Listo!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_FINAL'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Bronce completado."