#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY BRONCE (SIMPLE WEB) - V18 (Blindado)
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

# --- ARGUMENTOS ---
ORDER_ID=$1
SSH_USER_ARG=$2 # (Opcional en Bronce, pero lo cogemos por si acaso)
OS_IMAGE_ARG=$3
DB_NAME_ARG=$4
WEB_NAME_ARG=$5
SUBDOMAIN_ARG=$6

# --- GESTIÓN DE IDENTIDAD ---
OWNER_ID="${TF_VAR_owner_id:-admin}"

[ -z "$ORDER_ID" ] && ORDER_ID="manual"
if [ -z "$SUBDOMAIN_ARG" ]; then SUBDOMAIN="bronce$ORDER_ID"; else SUBDOMAIN="$SUBDOMAIN_ARG"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 8) # Pass más corta para plan barato :P

# Variable para Tofu
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
echo "Params: Owner=$OWNER_ID | Cluster=$CLUSTER_NAME" >> "$LOG_FILE"

update_status 0 "Iniciando Plan Bronce..."

# Limpieza de zombis
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
done

# --- MINIKUBE SEGURO (LOW SPEC) ---
update_status 20 "Arrancando Mini-Cluster Seguro..."
# Nota: Plan Bronce usa menos recursos (1 CPU, 1GB RAM) pero lleva Calico
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cni=calico \
    --cpus=1 \
    --memory=1100m \
    --addons=default-storageclass,ingress,metrics-server \
    --force >> "$LOG_FILE" 2>&1

update_status 40 "Preparando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# --- APPLY (Con Owner ID) ---
update_status 50 "Desplegando Web..."
# Pasamos variables genéricas, el main.tf de simple debe estar preparado
tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="subdomain=$SUBDOMAIN" \
    -var="owner_id=$OWNER_ID" >> "$LOG_FILE" 2>&1

# Espera
update_status 80 "Esperando Pods..."
# En Bronce asumimos que el deployment se llama 'web-simple' o similar en tu main.tf
# Si falla aquí, revisa el nombre del recurso en tu main.tf de simple
kubectl wait --for=condition=available deployment --all --timeout=300s >> "$LOG_FILE" 2>&1

# Final
update_status 95 "Finalizando..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
# Intentamos sacar puerto si existe output, si no puerto 80 estándar
WEB_PORT=$(tofu output -raw node_port 2>/dev/null || echo "80")

INFO_TEXT="[PLAN BRONCE]
-----------------------
Estado: ONLINE
URL: http://$SUBDOMAIN.sylobi.org
IP: $HOST_IP:$WEB_PORT
Owner ID: $OWNER_ID
Nota: Plan sin persistencia."

# JSON Final
JSON_STRING=$(python3 -c "import json; print(json.dumps({
    'percent': 100, 
    'message': '¡Bronce Listo!', 
    'status': 'completed', 
    'ssh_cmd': 'No SSH', 
    'ssh_pass': '''$INFO_TEXT'''
}))")

echo "$JSON_STRING" > "$BUZON_STATUS"
echo "✅ Despliegue Bronce completado."