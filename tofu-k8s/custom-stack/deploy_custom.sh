#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY CUSTOM (PERSONALIZADO) - V27
# Sintaxis Segura, Multi-OS y Limpieza
# ==========================================
# (Moves into proper place below)

LOG_FILE="/tmp/deploy_custom_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CUSTOM (Exit: $exit_code). Ver log: $LOG_FILE\033[0m"
    BUZON_ERR="$PROJECT_ROOT/sylo-web/buzon-pedidos/status_$1.json"
    if [ -d "$(dirname "$BUZON_ERR")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo Crítico Custom.\", \"status\": \"error\"}" > "$BUZON_ERR"
    fi
    exit $exit_code
}
trap 'handle_error $1' ERR

# --- ARGUMENTOS (Vienen del Orquestador V21) ---
ORDER_ID=$1
CPU_REQ=$2
RAM_REQ=$3
STORAGE_REQ=$4
DB_ENABLED=$5
DB_TYPE=$6
WEB_ENABLED=$7
WEB_TYPE=$8
SSH_USER_ARG=$9
OS_IMAGE_ARG=${10}
DB_NAME_ARG=${11}
WEB_NAME_ARG=${12}
SUBDOMAIN_ARG=${13}
STATIC_IP_ARG=${14}

# --- IDENTIDAD ---
OWNER_ID="${TF_VAR_owner_id:-admin}"

# --- DEFAULTS ---
[ -z "$ORDER_ID" ] && ORDER_ID="manual"

# --- ISOLATION FIX (Correct Placement) ---
export KUBECONFIG="$HOME/.kube/config_sylo_$ORDER_ID"
touch "$KUBECONFIG"
[ -z "$CPU_REQ" ] && CPU_REQ="2"
[ -z "$RAM_REQ" ] && RAM_REQ="4"
if [ -z "$SSH_USER_ARG" ]; then SSH_USER="cliente"; else SSH_USER="$SSH_USER_ARG"; fi
if [ -z "$DB_NAME_ARG" ]; then DB_NAME="custom_db"; else DB_NAME="$DB_NAME_ARG"; fi
if [ -z "$WEB_NAME_ARG" ]; then WEB_NAME="Custom Cluster"; else WEB_NAME="$WEB_NAME_ARG"; fi
if [ -z "$SUBDOMAIN_ARG" ]; then SUBDOMAIN="cliente$ORDER_ID"; else SUBDOMAIN="$SUBDOMAIN_ARG"; fi

# --- LÓGICA DE IMÁGENES Y PUERTOS ---
IMAGE_WEB="nginx:latest"
MOUNT_PATH="/usr/share/nginx/html"
WEB_PORT_INTERNAL=80

if [ "$WEB_TYPE" == "nginx" ]; then
    if [ "$OS_IMAGE_ARG" == "alpine" ]; then
        IMAGE_WEB="nginx:alpine"
        MOUNT_PATH="/usr/share/nginx/html"
    elif [ "$OS_IMAGE_ARG" == "redhat" ]; then
        IMAGE_WEB="registry.access.redhat.com/ubi8/nginx-120"
        MOUNT_PATH="/opt/app-root/src"
        WEB_PORT_INTERNAL=8080
    fi
elif [ "$WEB_TYPE" == "apache" ]; then
    IMAGE_WEB="httpd:latest"
    MOUNT_PATH="/usr/local/apache2/htdocs"
    if [ "$OS_IMAGE_ARG" == "alpine" ]; then IMAGE_WEB="httpd:alpine"; fi
    if [ "$OS_IMAGE_ARG" == "redhat" ]; then
        IMAGE_WEB="registry.access.redhat.com/ubi8/httpd-24"
        MOUNT_PATH="/var/www/html"
        WEB_PORT_INTERNAL=8080
    fi
fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/sylo-web/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -hex 8) 

# Recursos VM Minikube
VM_CPU=$((CPU_REQ + 1))
VM_RAM_MB=$((RAM_REQ * 1024 + 1024)) 

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo -e "📊 [Progreso $1%] $2"
}

# --- INICIO ---
echo "--- LOG CUSTOM ($ORDER_ID) ---" > "$LOG_FILE"
echo "Specs: Owner=$OWNER_ID | OS=$OS_IMAGE_ARG | Web=$WEB_TYPE | DB=$DB_TYPE" >> "$LOG_FILE"
update_status 0 "Iniciando Custom ($OS_IMAGE_ARG)..."

# --- LIMPIEZA PROFUNDA ---
update_status 5 "Limpiando recursos..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do 
    minikube delete -p "$ZOMBIE" --purge >> "$LOG_FILE" 2>&1 || true
done
docker volume prune -f >> "$LOG_FILE" 2>&1 || true
docker network prune -f >> "$LOG_FILE" 2>&1 || true

# --- MINIKUBE ---
# --- MINIKUBE ---
update_status 15 "Arrancando Cluster Personalizado ($STATIC_IP_ARG)..."

# COMANDO DETERMINISTA
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cni=calico \
    --static-ip "$STATIC_IP_ARG" \
    --cpus=$VM_CPU --memory=${VM_RAM_MB}m \
    --addons=default-storageclass,ingress,metrics-server \
    --force >> "$LOG_FILE" 2>&1

# FIX: Update context immediately
minikube -p "$CLUSTER_NAME" update-context >> "$LOG_FILE" 2>&1

# --- TOOLKIT LOADER (Shared Volume) ---
update_status 30 "Preparando Toolkit..."
kubectl apply -f "$SCRIPT_DIR/toolkit-loader.yaml" >> "$LOG_FILE" 2>&1
# Wait for Job completion
kubectl wait --for=condition=complete job/toolkit-loader --timeout=120s >> "$LOG_FILE" 2>&1 || echo "Warning: Toolkit job timeout" >> "$LOG_FILE"

# --- FIX: Ensure KUBECONFIG is populated ---
minikube -p "$CLUSTER_NAME" update-context >> "$LOG_FILE" 2>&1

# 🔥 HARD FIX: Force correct IP in Kubeconfig
# Minikube sometimes writes localhost:80 or 127.0.0.1:xxx which fails inside Docker/Tofu
REAL_IP=$(minikube -p "$CLUSTER_NAME" ip)
echo "--- DEBUG: Real IP is $REAL_IP ---" >> "$LOG_FILE"

if [ ! -z "$REAL_IP" ]; then
    kubectl config set-cluster "$CLUSTER_NAME" --server="https://$REAL_IP:8443" --insecure-skip-tls-verify=true >> "$LOG_FILE" 2>&1
    kubectl config set-context "$CLUSTER_NAME" --cluster="$CLUSTER_NAME" --user="$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
    kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
fi

echo "--- DEBUG KUBECONFIG (AFTER FIX) ---" >> "$LOG_FILE"
kubectl config view >> "$LOG_FILE" 2>&1

update_status 40 "Configurando Tofu..."
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# --- APPLY ---
update_status 50 "Aplicando Infraestructura..."
tofu apply -auto-approve \
    -var="kubeconfig_path=$KUBECONFIG" \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="cpu=$CPU_REQ" \
    -var="ram=$RAM_REQ" \
    -var="storage=$STORAGE_REQ" \
    -var="db_enabled=$DB_ENABLED" \
    -var="db_type=$DB_TYPE" \
    -var="db_name=$DB_NAME" \
    -var="web_enabled=$WEB_ENABLED" \
    -var="web_type=$WEB_TYPE" \
    -var="web_custom_name=$WEB_NAME" \
    -var="image_web=$IMAGE_WEB" \
    -var="subdomain=$SUBDOMAIN" \
    -var="web_mount_path=$MOUNT_PATH" \
    -var="web_port_internal=$WEB_PORT_INTERNAL" \
    -var="owner_id=$OWNER_ID" \
    -var="os_image=$OS_IMAGE_ARG" >> "$LOG_FILE" 2>&1

# --- ESPERAS CONDICIONALES ---
update_status 70 "Verificando servicios..."

if [ "$DB_ENABLED" = "true" ]; then 
    echo "Esperando DB..." >> "$LOG_FILE"
    kubectl wait --for=condition=available deployment/custom-db --timeout=300s >> "$LOG_FILE" 2>&1 || true
    sleep 15
fi

if [ "$WEB_ENABLED" = "true" ]; then 
    echo "Esperando Web..." >> "$LOG_FILE"
    kubectl wait --for=condition=available deployment/custom-web --timeout=300s >> "$LOG_FILE" 2>&1 || true
fi

# SSH siempre activo
kubectl wait --for=condition=available deployment/ssh-server --timeout=300s >> "$LOG_FILE" 2>&1

# --- FINAL ---
update_status 90 "Finalizando..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
WEB_PORT=$(tofu output -raw web_port 2>/dev/null || echo "N/A")
SSH_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "N/A")

# Nombre bonito
OS_PRETTY="Linux ($OS_IMAGE_ARG)"
if [[ "$OS_IMAGE_ARG" == "alpine" ]]; then OS_PRETTY="Alpine Linux"; fi
if [[ "$OS_IMAGE_ARG" == "ubuntu" ]]; then OS_PRETTY="Ubuntu Server"; fi
if [[ "$OS_IMAGE_ARG" == "redhat" ]]; then OS_PRETTY="RedHat Enterprise"; fi

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT"

INFO_TEXT="[SPECS CUSTOM]
Owner ID: $OWNER_ID
SO: $OS_PRETTY
CPU: $CPU_REQ / RAM: $RAM_REQ GB"

if [ "$WEB_ENABLED" = "true" ]; then 
    INFO_TEXT="$INFO_TEXT
    
[WEB]
URL: http://$HOST_IP:$WEB_PORT
Server: $WEB_TYPE (Port $WEB_PORT_INTERNAL)"
fi

if [ "$DB_ENABLED" = "true" ]; then 
    INFO_TEXT="$INFO_TEXT
    
[DATABASE]
Motor: $DB_TYPE
Nombre: $DB_NAME
User: root / Pass: root"
fi

INFO_TEXT="$INFO_TEXT

[SSH]
User: $SSH_USER
Pass: $SSH_PASS"

# --- JSON SEGURO ---
python3 -c "
import json
import sys

data = {
    'percent': 100, 
    'message': '¡Despliegue Custom Listo!', 
    'status': 'completed', 
    'ssh_cmd': sys.argv[1], 
    'ssh_pass': sys.argv[2],
    'os_info': sys.argv[3],
    'web_url': sys.argv[4] if sys.argv[4] != 'N/A' else ''
}

with open('$BUZON_STATUS', 'w') as f:
    json.dump(data, f)
" "$CMD_SSH" "$INFO_TEXT" "$OS_PRETTY" "$WEB_PORT"

echo "✅ Despliegue Custom completado."