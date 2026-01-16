#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY PLATA SIMPLE (MySQL Single + SSH) - V26
# Fix: Output JSON Limpio para Alpine/Ubuntu
# ==========================================

LOG_FILE="/tmp/deploy_plata_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR PLATA (Exit: $exit_code). Ver log: $LOG_FILE\033[0m"
    BUZON_ERR="$PROJECT_ROOT/sylo-web/buzon-pedidos/status_$1.json"
    if [ -d "$(dirname "$BUZON_ERR")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo crítico Plata.\", \"status\": \"error\"}" > "$BUZON_ERR"
    fi
    exit $exit_code
}
trap 'handle_error $1' ERR

# --- ARGUMENTOS ---
ORDER_ID=$1
SSH_USER_ARG=$2
OS_IMAGE_ARG=$3
DB_NAME_ARG=$4
SUBDOMAIN_ARG=$5
STATIC_IP_ARG=$6

OWNER_ID="${TF_VAR_owner_id:-admin}"

[ -z "$ORDER_ID" ] && ORDER_ID="manual"
if [ -z "$SSH_USER_ARG" ]; then SSH_USER="admin_plata"; else SSH_USER="$SSH_USER_ARG"; fi
if [ -z "$DB_NAME_ARG" ]; then DB_NAME="sylo_db"; else DB_NAME="$DB_NAME_ARG"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/sylo-web/buzon-pedidos/status_$ORDER_ID.json"
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
echo "--- LOG PLATA SIMPLE ($ORDER_ID) ---" > "$LOG_FILE"
echo "Params: Owner=$OWNER_ID | DB=$DB_NAME | OS=$OS_IMAGE_ARG" >> "$LOG_FILE"

update_status 0 "Iniciando Plan Plata (Simple)..."

# --- LIMPIEZA TOTAL ---
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" --purge >> "$LOG_FILE" 2>&1 || true
done
docker volume prune -f >> "$LOG_FILE" 2>&1 || true

# --- MINIKUBE ---
# --- MINIKUBE ---
update_status 20 "Levantando Minikube [$STATIC_IP_ARG]..."

# COMANDO DETERMINISTA
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cni=calico \
    --static-ip "$STATIC_IP_ARG" \
    --cpus=2 --memory=2048m \
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
update_status 50 "Desplegando Infraestructura ($OS_IMAGE_ARG)..."
tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="db_name=$DB_NAME" \
    -var="owner_id=$OWNER_ID" \
    -var="os_image=$OS_IMAGE_ARG" >> "$LOG_FILE" 2>&1

# Espera Pods
update_status 80 "Esperando servicios..."
kubectl --context "$CLUSTER_NAME" wait --for=condition=available deployment/mysql-server --timeout=300s >> "$LOG_FILE" 2>&1
kubectl --context "$CLUSTER_NAME" wait --for=condition=available deployment/ssh-server --timeout=300s >> "$LOG_FILE" 2>&1

# Final
update_status 95 "Generando accesos..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
# Redirigimos stderr a /dev/null para que solo capturemos el número
SSH_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "2222")
DB_PORT=$(tofu output -raw db_port 2>/dev/null || echo "3306")

OS_PRETTY="Linux Genérico"
if [[ "$OS_IMAGE_ARG" == "ubuntu" ]]; then OS_PRETTY="Ubuntu Server LTS"; fi
if [[ "$OS_IMAGE_ARG" == "alpine" ]]; then OS_PRETTY="Alpine Linux (Optimizado)"; fi

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT"

INFO_DB="[DATABASE]
Motor: MySQL 8.0 (Single)
Host: $HOST_IP
Puerto Externo: $DB_PORT
User: root
Pass: password_root
DB Name: $DB_NAME"

INFO_FINAL="[SSH ACCESO]
Sistema: $OS_PRETTY
User: $SSH_USER
Pass: $SSH_PASS

$INFO_DB"

# --- GENERACIÓN DE JSON SEGURA ---
# Usamos un script de Python embebido para garantizar JSON válido
# y escribimos DIRECTAMENTE al archivo de estado, sin echos intermedios
python3 -c "
import json
import sys

data = {
    'percent': 100, 
    'message': '¡Plata Lista!', 
    'status': 'completed', 
    'ssh_cmd': sys.argv[1], 
    'ssh_pass': sys.argv[2],
    'os_info': sys.argv[3]
}

with open('$BUZON_STATUS', 'w') as f:
    json.dump(data, f)
" "$CMD_SSH" "$INFO_FINAL" "$OS_PRETTY"

echo "✅ Despliegue Plata completado."