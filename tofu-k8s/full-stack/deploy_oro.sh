#!/bin/bash
set -eE -o pipefail

# --- CONFIGURACIÓN DE LOGS ---
LOG_FILE="/tmp/deploy_oro_debug.log"

# --- RUTAS DINÁMICAS ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")" # tofu-k8s -> worker -> raiz

# --- TRAP DE ERRORES ---
handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CRÍTICO (Exit Code: $exit_code) en línea $LINENO.\033[0m"
    echo -e "\033[0;33m--- ÚLTIMAS LÍNEAS DEL LOG DE ERROR ($LOG_FILE) ---\033[0m"
    tail -n 20 "$LOG_FILE"
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo Crítico. Revisa la consola.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    fi
    exit $exit_code
}
trap 'handle_error' ERR

# --- VARIABLES ---
ORDER_ID=$1
RAW_CLIENT_NAME=$2 

[ -z "$ORDER_ID" ] && ORDER_ID="manual"

# --- SANITIZACIÓN ---
if [ -z "$RAW_CLIENT_NAME" ]; then
    SSH_USER="cliente"
else
    SSH_USER=$(echo "$RAW_CLIENT_NAME" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')
fi
[ -z "$SSH_USER" ] && SSH_USER="cliente"

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

# --- FUNCIÓN DE REPORTING ---
update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo -e "📊 \033[1;33m[Progreso $1%]\033[0m $2"
}

# --- WAIT MYSQL ---
wait_for_mysql_connection() {
    local pod=$1
    echo "   ⏳ Verificando salud interna de $pod..." >> "$LOG_FILE"
    for i in {1..30}; do
        if kubectl exec "$pod" -- mysqladmin ping -h 127.0.0.1 -u root -ppassword_root --silent >> "$LOG_FILE" 2>&1; then
             echo "   ✅ MySQL online en $pod" >> "$LOG_FILE"
             return 0
        fi
        sleep 2
    done
    echo "   ❌ Timeout esperando a MySQL en $pod" >> "$LOG_FILE"
    return 1
}

# --- INICIO ---
echo "--- INICIO DEL LOG ORO ---" > "$LOG_FILE"
update_status 0 "Iniciando Plan ORO..."

# BLOQUE ANTI-ZOMBIES
update_status 5 "Limpiando sistema..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    if [ ! -z "$ZOMBIE" ]; then
        echo "💀 Detectado zombie: $ZOMBIE. Eliminando..." >> "$LOG_FILE"
        minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
    fi
done
rm -f /tmp/juju-* 2>/dev/null || true
rm -rf /tmp/minikube.* 2>/dev/null || true

# 2. LEVANTAR MINIKUBE
update_status 20 "Levantando Cluster K8s (Oro)..."
minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=3 \
    --memory=3072m \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check >> "$LOG_FILE" 2>&1

update_status 40 "Configurando kubectl..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1

# 3. DESPLIEGUE MONOLÍTICO
update_status 50 "Aplicando infraestructura..."
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

tofu apply -auto-approve \
    -var="cluster_name=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" >> "$LOG_FILE" 2>&1

# 4. ESPERA DE SERVICIOS
update_status 60 "Esperando arranque MySQL..."
kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=300s >> "$LOG_FILE" 2>&1 || true
kubectl wait --for=condition=Ready pod/mysql-slave-0 --timeout=300s >> "$LOG_FILE" 2>&1 || true

wait_for_mysql_connection "mysql-master-0"
wait_for_mysql_connection "mysql-slave-0"

update_status 65 "Configurando replicación..."
MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"
kubectl exec mysql-master-0 -- $MYSQL_CMD -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;" >> "$LOG_FILE" 2>&1 || true

M_STATUS=$(kubectl exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')

if [ ! -z "$FILE" ]; then
    kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;" >> "$LOG_FILE" 2>&1 || true
fi

update_status 75 "Esperando Servidores Web y SSH..."
kubectl wait --for=condition=available deployment/nginx-ha --timeout=240s >> "$LOG_FILE" 2>&1
kubectl wait --for=condition=available deployment/ssh-server --timeout=240s >> "$LOG_FILE" 2>&1

# 5. FINALIZACIÓN
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
WEB_PORT=$(tofu output -raw web_port)
SSH_PORT=$(tofu output -raw ssh_port)

[ -z "$WEB_PORT" ] && WEB_PORT="30080"
[ -z "$SSH_PORT" ] && SSH_PORT="30022"
WEB_URL="http://$HOST_IP:$WEB_PORT"

CMD_SSH="ssh $SSH_USER@$HOST_IP -p $SSH_PORT" 
INFO_TEXT="[ACCESO WEB]\n$WEB_URL\n\n[ACCESO SSH]\nUser: $SSH_USER\nPass: $SSH_PASS\n\n[DB CONECTADA]\nCluster HA: Operativo"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Despliegue ORO Completado!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_TEXT'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo -e "✅ \033[0;32mDespliegue Oro completado con éxito.\033[0m"