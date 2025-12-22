#!/bin/bash
set -e
# --- RUTAS DINÁMICAS ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")" # tofu-k8s -> worker -> raiz

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
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

export TF_PLUGIN_CACHE_DIR="$HOME/.terraform.d/plugin-cache"
mkdir -p "$TF_PLUGIN_CACHE_DIR"

SSH_PASS=$(openssl rand -base64 12)
export TF_VAR_ssh_password="$SSH_PASS"

handle_error() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": 100, \"message\": \"Fallo en DB. Revisa logs.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    fi
    exit 1
}
trap 'handle_error' ERR

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo "📊 [Progreso $1%] $2"
}

update_status 0 "Iniciando Plan Plata (DB HA)..."

# ANTI-ZOMBIES
update_status 5 "Limpiando residuos..."
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    if [ ! -z "$ZOMBIE" ]; then
        echo "💀 Detectado zombie: $ZOMBIE. Eliminando..."
        minikube delete -p "$ZOMBIE" > /dev/null 2>&1 || true
    fi
done
rm -f /tmp/juju-* 2>/dev/null

# 20% - Minikube
update_status 20 "Levantando Cluster DB..."
(minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=2048m \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check) > /dev/null 2>&1

update_status 40 "Configurando entorno..."
kubectl config use-context "$CLUSTER_NAME" > /dev/null

# 50% - Tofu
update_status 50 "Desplegando infraestructura..."
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade > /dev/null

tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" > /dev/null

# 70% - Espera
update_status 70 "Esperando arranque MySQL..."
kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=120s > /dev/null || true
kubectl wait --for=condition=Ready pod/mysql-slave-0 --timeout=120s > /dev/null || true

MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"
ADMIN_CMD="mysqladmin -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"

wait_for_mysql() {
  local pod=$1
  for i in {1..60}; do
    if kubectl exec "$pod" -- $ADMIN_CMD ping --silent > /dev/null 2>&1; then return 0; fi
    sleep 1
  done
  return 1
}

wait_for_mysql "mysql-master-0"
wait_for_mysql "mysql-slave-0"

# 85% - Replicación
update_status 85 "Conectando replicación..."
kubectl exec mysql-master-0 -- $MYSQL_CMD -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;"

M_STATUS=$(kubectl exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')

kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;"

# 100% - FINAL
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
NODE_PORT=$(tofu output -raw ssh_port)
CMD_SSH="ssh $SSH_USER@$HOST_IP -p $NODE_PORT"

INFO_DB="Maestro: mysql-master.default.svc.cluster.local\nEsclavo: mysql-slave.default.svc.cluster.local\nUser: kylo_user / Pass: kylo_password"
INFO_FINAL="[SSH ACCESO]\nUser: $SSH_USER\nPass: $SSH_PASS\n\n[DATABASE]\n$INFO_DB"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Base de Datos HA Lista!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_FINAL'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Plata completado."