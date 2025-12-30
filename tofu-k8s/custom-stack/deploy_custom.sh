#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY CUSTOM (PERSONALIZADO) - V17 (PUERTO DINAMICO)
# ==========================================

LOG_FILE="/tmp/deploy_custom_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CUSTOM (Exit: $exit_code). Ver log: $LOG_FILE\033[0m"
    tail -n 20 "$LOG_FILE"
    BUZON_ERR="$PROJECT_ROOT/buzon-pedidos/status_$1.json"
    if [ -d "$(dirname "$BUZON_ERR")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo Crítico Custom.\", \"status\": \"error\"}" > "$BUZON_ERR"
    fi
    exit $exit_code
}
trap 'handle_error $1' ERR

# --- 1. RECEPCIÓN DE ARGUMENTOS ---
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

# --- 2. DEFAULTS ---
[ -z "$ORDER_ID" ] && ORDER_ID="manual"
[ -z "$CPU_REQ" ] && CPU_REQ="2"
[ -z "$RAM_REQ" ] && RAM_REQ="4"
if [ -z "$SSH_USER_ARG" ]; then SSH_USER="cliente"; else SSH_USER="$SSH_USER_ARG"; fi
if [ -z "$DB_NAME_ARG" ]; then DB_NAME="custom_db"; else DB_NAME="$DB_NAME_ARG"; fi
if [ -z "$WEB_NAME_ARG" ]; then WEB_NAME="Custom Cluster"; else WEB_NAME="$WEB_NAME_ARG"; fi
if [ -z "$SUBDOMAIN_ARG" ]; then SUBDOMAIN="cliente$ORDER_ID"; else SUBDOMAIN="$SUBDOMAIN_ARG"; fi

# --- 3. CÁLCULO INTELIGENTE: IMAGEN, RUTA Y PUERTO ---
# Aquí definimos la "Matriz" de compatibilidad completa

IMAGE_WEB="nginx:latest"
MOUNT_PATH="/usr/share/nginx/html"
WEB_PORT_INTERNAL=80  # Default estándar

if [ "$WEB_TYPE" == "nginx" ]; then
    # --- CONFIGURACIÓN NGINX ---
    if [ "$OS_IMAGE_ARG" == "alpine" ]; then
        IMAGE_WEB="nginx:alpine"
        MOUNT_PATH="/usr/share/nginx/html"
        WEB_PORT_INTERNAL=80
    elif [ "$OS_IMAGE_ARG" == "redhat" ]; then
        # RedHat Nginx usa puerto 8080 y ruta opt
        IMAGE_WEB="registry.access.redhat.com/ubi8/nginx-120"
        MOUNT_PATH="/opt/app-root/src"
        WEB_PORT_INTERNAL=8080
    else 
        # Ubuntu/Standard
        IMAGE_WEB="nginx:latest"
        MOUNT_PATH="/usr/share/nginx/html"
        WEB_PORT_INTERNAL=80
    fi

elif [ "$WEB_TYPE" == "apache" ]; then
    # --- CONFIGURACIÓN APACHE ---
    if [ "$OS_IMAGE_ARG" == "alpine" ]; then
        IMAGE_WEB="httpd:alpine"
        MOUNT_PATH="/usr/local/apache2/htdocs"
        WEB_PORT_INTERNAL=80
    elif [ "$OS_IMAGE_ARG" == "redhat" ]; then
        # 🔥 RedHat Apache usa puerto 8080 🔥
        IMAGE_WEB="registry.access.redhat.com/ubi8/httpd-24"
        MOUNT_PATH="/var/www/html"
        WEB_PORT_INTERNAL=8080
    else
        # Ubuntu
        IMAGE_WEB="ubuntu/apache2"
        MOUNT_PATH="/var/www/html"
        WEB_PORT_INTERNAL=80
    fi
fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)
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
echo "Specs: $OS_IMAGE_ARG | $WEB_TYPE | DB: $DB_TYPE" >> "$LOG_FILE"
echo "Selected Image: $IMAGE_WEB | Path: $MOUNT_PATH | Port: $WEB_PORT_INTERNAL" >> "$LOG_FILE"
update_status 0 "Iniciando Custom ($OS_IMAGE_ARG)..."

# Limpieza
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true; done

# Minikube
update_status 20 "Levantando Cluster..."
minikube start -p "$CLUSTER_NAME" --driver=docker --cpus="$VM_CPU" --memory="${VM_RAM_MB}m" --addons=default-storageclass,ingress --force >> "$LOG_FILE" 2>&1

update_status 40 "Configurando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# Apply CON TODAS LAS VARIABLES (INCLUYENDO PUERTO)
update_status 50 "Aplicando Infraestructura..."
tofu apply -auto-approve \
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
    -var="web_port_internal=$WEB_PORT_INTERNAL" >> "$LOG_FILE" 2>&1

# Esperas
update_status 70 "Verificando servicios..."
if [ "$DB_ENABLED" = "true" ]; then kubectl wait --for=condition=Ready pod -l app=custom-db --timeout=300s >> "$LOG_FILE" 2>&1 || true; fi
if [ "$WEB_ENABLED" = "true" ]; then kubectl wait --for=condition=available deployment/custom-web --timeout=300s >> "$LOG_FILE" 2>&1 || true; fi

# Credenciales
update_status 90 "Finalizando..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
WEB_PORT=$(tofu output -raw web_port 2>/dev/null || echo "N/A")
SSH_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "N/A")

INFO_TEXT="[SPECS]\nSO: $OS_IMAGE_ARG\nWeb: $WEB_TYPE (Port $WEB_PORT_INTERNAL)\nDB: $DB_TYPE"
if [ "$WEB_ENABLED" = "true" ]; then INFO_TEXT="$INFO_TEXT\n\n[WEB]\nURL: http://$HOST_IP:$WEB_PORT"; fi
if [ "$DB_ENABLED" = "true" ]; then INFO_TEXT="$INFO_TEXT\n\n[DATABASE]\nMotor: $DB_TYPE\nNombre: $DB_NAME"; fi
INFO_TEXT="$INFO_TEXT\n\n[SSH]\nUser: $SSH_USER\nPass: $SSH_PASS"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Despliegue Listo!', 'status': 'completed', 'ssh_cmd': 'ssh $SSH_USER@$HOST_IP -p $SSH_PORT', 'ssh_pass': '''$INFO_TEXT'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"
echo "✅ Despliegue completado."