#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY ORO (REDHAT CACHÉ EXPRESS) - V16
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

# --- SANITIZACIÓN ---
[ -z "$ORDER_ID" ] && ORDER_ID="manual"
SSH_USER="${SSH_USER_ARG:-admin_oro}"
DB_NAME="${DB_NAME_ARG:-sylo_db}"
WEB_NAME="${WEB_NAME_ARG:-Sylo Web Cluster}"
SUBDOMAIN="${SUBDOMAIN_ARG:-cliente$ORDER_ID}"

# --- 2. SELECCIÓN DE IMAGEN ---
IMAGE_WEB="nginx:latest" 
if [ "$OS_IMAGE_ARG" == "alpine" ]; then IMAGE_WEB="nginx:alpine"; fi
# Imagen REAL de RedHat
if [ "$OS_IMAGE_ARG" == "redhat" ]; then IMAGE_WEB="registry.access.redhat.com/ubi8/nginx-120"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo -e "📊 [Progreso $1%] $2"
}

# --- INICIO ---
echo "--- INICIO DEL LOG ORO ($ORDER_ID) ---" > "$LOG_FILE"
update_status 0 "Iniciando Plan ORO (Imagen: $OS_IMAGE_ARG)..."

# LIMPIEZA
update_status 5 "Limpiando sistema..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
done

# LEVANTAR CLUSTER
update_status 15 "Arrancando Cluster..."
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=3 \
    --memory=4000m \
    --addons=default-storageclass,ingress \
    --force >> "$LOG_FILE" 2>&1

# --- 🔥 AQUÍ ESTÁ LA MAGIA DE LA VELOCIDAD 🔥 ---
if [ "$OS_IMAGE_ARG" == "redhat" ]; then
    update_status 30 "Inyectando imagen RedHat (Carga Rápida)..."
    echo "Cargando imagen local en Minikube..." >> "$LOG_FILE"
    # Esto coge la imagen que bajaste con 'docker pull' y la mete en el cluster
    minikube -p "$CLUSTER_NAME" image load registry.access.redhat.com/ubi8/nginx-120 >> "$LOG_FILE" 2>&1 || echo "Warning: No se pudo cargar imagen local" >> "$LOG_FILE"
fi

update_status 40 "Configurando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# DESPLIEGUE TOFU
update_status 50 "Aplicando infraestructura..."
tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="db_name=$DB_NAME" \
    -var="web_custom_name=$WEB_NAME" \
    -var="image_web=$IMAGE_WEB" \
    -var="subdomain=$SUBDOMAIN" >> "$LOG_FILE" 2>&1

# ESPERA
update_status 60 "Esperando arranque de servicios..."
kubectl wait --for=condition=available deployment/nginx-ha --timeout=600s >> "$LOG_FILE" 2>&1
kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=300s >> "$LOG_FILE" 2>&1

# DB HA
update_status 75 "Configurando Base de Datos HA..."
sleep 15
MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"
for i in {1..5}; do
   kubectl exec mysql-master-0 -- $MYSQL_CMD -e "status" >> "$LOG_FILE" 2>&1 && break
   sleep 5
done

kubectl exec mysql-master-0 -- $MYSQL_CMD -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;" >> "$LOG_FILE" 2>&1

M_STATUS=$(kubectl exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')

if [ ! -z "$FILE" ]; then
    kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;" >> "$LOG_FILE" 2>&1
fi

# FINAL
update_status 90 "Generando accesos..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
# Recogemos puertos del output de Tofu
WEB_PORT=$(tofu output -raw web_port 2>/dev/null || echo "80")
SSH_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "2222")

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT"
INFO_TEXT="[PLAN ORO - REDHAT EDITION]
-------------------------------------------
Sistema: RedHat UBI 8 (Cargado desde Local)
URL WEB: http://$SUBDOMAIN.sylobi.org
IP INTERNA: $HOST_IP:$WEB_PORT

[ACCESO SSH]
Usuario: $SSH_USER
Password: $SSH_PASS

[BASE DE DATOS]
DB Name: $DB_NAME
Replica: ACTIVA"

JSON_STRING=$(python3 -c "import json, sys; print(json.dumps({
    'percent': 100, 
    'message': '¡Despliegue ORO Completado!', 
    'status': 'completed', 
    'ssh_cmd': sys.argv[1], 
    'ssh_pass': sys.argv[2]
}))" "$CMD_SSH" "$INFO_TEXT")

echo "$JSON_STRING" > "$BUZON_STATUS"
echo -e "✅ Despliegue Oro completado."