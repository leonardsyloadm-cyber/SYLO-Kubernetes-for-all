#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY BRONCE (SOLO SSH / ALPINE) - V23
# Fix: Contraseña Hexadecimal (Sin símbolos raros)
# ==========================================

LOG_FILE="/tmp/deploy_simple_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR BRONCE (Exit: $exit_code). Ver log: $LOG_FILE\033[0m"
    BUZON_ERR="$PROJECT_ROOT/sylo-web/buzon-pedidos/status_$1.json"
    if [ -d "$(dirname "$BUZON_ERR")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo crítico Bronce.\", \"status\": \"error\"}" > "$BUZON_ERR"
    fi
    exit $exit_code
}
trap 'handle_error $1' ERR

# --- ARGUMENTOS ---
ORDER_ID=$1
SSH_USER_ARG=$2 
OS_IMAGE_REQ=$3 
SUBDOMAIN_ARG=$6
STATIC_IP_ARG=$7

OWNER_ID="${TF_VAR_owner_id:-admin}"
OS_REAL="alpine"
OS_PRETTY="Alpine Linux (CLI Only)"

[ -z "$ORDER_ID" ] && ORDER_ID="manual"
if [ -z "$SSH_USER_ARG" ]; then SSH_USER="cliente"; else SSH_USER="$SSH_USER_ARG"; fi
if [ -z "$SUBDOMAIN_ARG" ]; then SUBDOMAIN="bronce$ORDER_ID"; else SUBDOMAIN="$SUBDOMAIN_ARG"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/sylo-web/buzon-pedidos/status_$ORDER_ID.json"

# --- 🔥 FIX: USAR HEX PARA EVITAR SÍMBOLOS QUE ROMPEN LA CADENA ---
# Antes: base64 (daba problemas con = + /)
# Ahora: hex (solo 0-9 y a-f, indestructible)
SSH_PASS=$(openssl rand -hex 8) 

TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo "📊 $2"
}

# --- INICIO ---
echo "--- LOG BRONCE ($ORDER_ID) ---" > "$LOG_FILE"
echo "Params: Owner=$OWNER_ID | User=$SSH_USER | Cluster=$CLUSTER_NAME" >> "$LOG_FILE"

update_status 0 "Iniciando Plan Bronce (Alpine Terminal)..."

# Limpieza
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
done
docker network prune -f >> "$LOG_FILE" 2>&1 || true

# --- MINIKUBE ---
# --- MINIKUBE ---
update_status 20 "Arrancando Minikube (Low Spec) [$STATIC_IP_ARG]..."

# COMANDO DETERMINISTA
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cni=calico \
    --static-ip "$STATIC_IP_ARG" \
    --cpus=1 --memory=1100m \
    --addons=default-storageclass,metrics-server \
    --force >> "$LOG_FILE" 2>&1

# FIX: Update context immediately
minikube -p "$CLUSTER_NAME" update-context >> "$LOG_FILE" 2>&1

update_status 40 "Configurando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# --- APPLY ---
update_status 50 "Desplegando SSH Box..."
tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_user=$SSH_USER" \
    -var="ssh_password=$SSH_PASS" \
    -var="owner_id=$OWNER_ID" \
    -var="os_image=$OS_REAL" >> "$LOG_FILE" 2>&1

# Espera
update_status 80 "Verificando acceso..."
kubectl wait --for=condition=available deployment/ssh-server --timeout=300s >> "$LOG_FILE" 2>&1

# Final
update_status 95 "Finalizando..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
SSH_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "2222")

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT"

INFO_TEXT="[PLAN BRONCE - TERMINAL]
-----------------------
Sistema: $OS_PRETTY
Estado: ONLINE 🟢
Owner ID: $OWNER_ID

[ACCESO SSH]
Usuario: $SSH_USER
Pass: $SSH_PASS

[NOTA]
Este plan NO incluye servidor web ni base de datos."

# JSON FINAL
JSON_STRING=$(python3 -c "import json, sys; print(json.dumps({
    'percent': 100, 
    'message': '¡Bronce Listo!', 
    'status': 'completed', 
    'ssh_cmd': sys.argv[1], 
    'ssh_pass': sys.argv[2],
    'os_info': sys.argv[3],
    'web_url': '' 
}))" "$CMD_SSH" "$INFO_TEXT" "$OS_PRETTY")

echo "$JSON_STRING" > "$BUZON_STATUS"
echo "✅ Despliegue Bronce completado."