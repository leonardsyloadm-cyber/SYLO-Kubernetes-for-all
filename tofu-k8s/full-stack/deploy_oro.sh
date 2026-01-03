#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY ORO (FULL STACK HA) - V19
# RedHat/Alpine/Ubuntu + MySQL HA + Web
# ==========================================

LOG_FILE="/tmp/deploy_oro_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

# --- TRAP DE ERRORES ---
handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CRÍTICO ORO (Exit: $exit_code). Ver logs: $LOG_FILE\033[0m"
    BUZON_ERR="$PROJECT_ROOT/buzon-pedidos/status_$1.json"
    if [ -d "$(dirname "$BUZON_ERR")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo Crítico en el despliegue Oro.\", \"status\": \"error\"}" > "$BUZON_ERR"
    fi
    exit $exit_code
}
trap 'handle_error $1' ERR

# --- 1. RECEPCIÓN DE ARGUMENTOS ---
ORDER_ID=$1
SSH_USER_ARG=$2
OS_IMAGE_ARG=$3
DB_NAME_ARG=$4
WEB_NAME_ARG=$5
SUBDOMAIN_ARG=$6

# --- GESTIÓN DE IDENTIDAD ---
OWNER_ID="${TF_VAR_owner_id:-admin}"

# --- SANITIZACIÓN ---
[ -z "$ORDER_ID" ] && ORDER_ID="manual"
SSH_USER="${SSH_USER_ARG:-admin_oro}"
DB_NAME="${DB_NAME_ARG:-sylo_db}"
WEB_NAME="${WEB_NAME_ARG:-Sylo Web Cluster}"
SUBDOMAIN="${SUBDOMAIN_ARG:-cliente$ORDER_ID}"

# --- 2. SELECCIÓN DE IMAGEN WEB ---
# Si es RedHat, usamos la imagen UBI. Si es Alpine, nginx:alpine. Si no, nginx standard.
IMAGE_WEB="nginx:latest" 
if [ "$OS_IMAGE_ARG" == "alpine" ]; then IMAGE_WEB="nginx:alpine"; fi
if [ "$OS_IMAGE_ARG" == "redhat" ]; then IMAGE_WEB="registry.access.redhat.com/ubi8/nginx-120"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -hex 8) # Hexadecimal seguro

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo -e "📊 [Progreso $1%] $2"
}

# --- INICIO ---
echo "--- INICIO DEL LOG ORO ($ORDER_ID) ---" > "$LOG_FILE"
echo "Params: Owner=$OWNER_ID | Image=$OS_IMAGE_ARG | WebImg=$IMAGE_WEB" >> "$LOG_FILE"
update_status 0 "Iniciando Plan ORO (Imagen: $OS_IMAGE_ARG)..."

# --- LIMPIEZA PROFUNDA (Evita errores de MySQL persistente) ---
update_status 5 "Limpiando sistema y discos..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" --purge >> "$LOG_FILE" 2>&1 || true
done
docker volume prune -f >> "$LOG_FILE" 2>&1 || true

# --- LEVANTAR CLUSTER ---
update_status 15 "Arrancando Cluster High-Spec..."
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cni=calico \
    --cpus=3 \
    --memory=4000m \
    --addons=default-storageclass,ingress,metrics-server \
    --force >> "$LOG_FILE" 2>&1

# CARGA REDHAT (Optimización)
if [ "$OS_IMAGE_ARG" == "redhat" ]; then
    update_status 30 "Pre-cargando RedHat UBI..."
    minikube -p "$CLUSTER_NAME" image pull registry.access.redhat.com/ubi8/nginx-120 >> "$LOG_FILE" 2>&1 || true
fi

update_status 40 "Configurando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# --- DESPLIEGUE TOFU ---
update_status 50 "Aplicando infraestructura Full Stack..."
tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="db_name=$DB_NAME" \
    -var="web_custom_name=$WEB_NAME" \
    -var="image_web=$IMAGE_WEB" \
    -var="subdomain=$SUBDOMAIN" \
    -var="owner_id=$OWNER_ID" >> "$LOG_FILE" 2>&1

# --- ESPERA ---
update_status 60 "Esperando arranque de servicios..."
kubectl wait --for=condition=available deployment/nginx-ha --timeout=300s >> "$LOG_FILE" 2>&1
kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=300s >> "$LOG_FILE" 2>&1

# --- DB HA SETUP (Con Respiro) ---
update_status 75 "Inicializando Cluster MySQL..."
sleep 30 # Respiro vital para que MySQL cree sus archivos

# Comando de conexión (sin host para usar socket local)
MYSQL_CMD="mysql -u root -ppassword_root"

# Check de vida
n=0
until [ "$n" -ge 10 ]
do
   if kubectl exec mysql-master-0 -- mysqladmin -u root -ppassword_root ping >> "$LOG_FILE" 2>&1; then
       break
   fi
   n=$((n+1)) 
   echo "Waiting for MySQL... ($n/10)" >> "$LOG_FILE"
   sleep 5
done

# Configuración Replicación
kubectl exec mysql-master-0 -- sh -c "$MYSQL_CMD -e \"CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;\"" >> "$LOG_FILE" 2>&1

M_STATUS=$(kubectl exec mysql-master-0 -- sh -c "$MYSQL_CMD -e 'SHOW MASTER STATUS\G'")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')

if [ ! -z "$FILE" ]; then
    kubectl exec mysql-slave-0 -- sh -c "$MYSQL_CMD -e \"STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;\"" >> "$LOG_FILE" 2>&1
fi

# --- FINAL ---
update_status 90 "Generando reporte..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
# Capturamos puertos limpios
WEB_PORT=$(tofu output -raw web_port 2>/dev/null || echo "80")
SSH_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "2222")

# Nombre bonito para la API
OS_PRETTY="Linux Genérico"
if [[ "$OS_IMAGE_ARG" == "alpine" ]]; then OS_PRETTY="Alpine Linux (Optimizado)"; fi
if [[ "$OS_IMAGE_ARG" == "ubuntu" ]]; then OS_PRETTY="Ubuntu Server LTS"; fi
if [[ "$OS_IMAGE_ARG" == "redhat" ]]; then OS_PRETTY="RedHat Enterprise (UBI)"; fi

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT"
URL_WEB="http://$SUBDOMAIN.sylobi.org"

INFO_TEXT="[PLAN ORO - FULL STACK]
-------------------------------------------
Sistema: $OS_PRETTY
URL WEB: $URL_WEB
IP INTERNA: $HOST_IP:$WEB_PORT
Owner ID: $OWNER_ID

[ACCESO SSH]
Usuario: $SSH_USER
Password: $SSH_PASS

[BASE DE DATOS]
DB Name: $DB_NAME
Replica: ACTIVA (Master/Slave)"

# --- JSON GENERATION (PYTHON SAFE) ---
python3 -c "
import json
import sys

data = {
    'percent': 100, 
    'message': '¡Despliegue ORO Completado!', 
    'status': 'completed', 
    'ssh_cmd': sys.argv[1], 
    'ssh_pass': sys.argv[2],
    'web_url': sys.argv[3],
    'os_info': sys.argv[4]
}

with open('$BUZON_STATUS', 'w') as f:
    json.dump(data, f)
" "$CMD_SSH" "$INFO_TEXT" "$URL_WEB" "$OS_PRETTY"

echo -e "✅ Despliegue Oro completado."