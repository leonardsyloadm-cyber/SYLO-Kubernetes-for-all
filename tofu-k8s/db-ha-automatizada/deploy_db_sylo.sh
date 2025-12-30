#!/bin/bash
set -eE -o pipefail

# ==========================================
# DEPLOY PLATA (SOLO DB HA + SSH) - V15
# ==========================================

LOG_FILE="/tmp/deploy_plata_debug.log"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR PLATA (Exit: $exit_code). Ver log: $LOG_FILE\033[0m"
    BUZON_ERR="$PROJECT_ROOT/buzon-pedidos/status_$1.json"
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
SUBDOMAIN_ARG=$6

[ -z "$ORDER_ID" ] && ORDER_ID="manual"
if [ -z "$SSH_USER_ARG" ]; then SSH_USER="admin_plata"; else SSH_USER="$SSH_USER_ARG"; fi
if [ -z "$DB_NAME_ARG" ]; then DB_NAME="sylo_db"; else DB_NAME="$DB_NAME_ARG"; fi

CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$PROJECT_ROOT/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

update_status() {
    if [ -d "$(dirname "$BUZON_STATUS")" ]; then
        echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    fi
    echo "📊 $2"
}

# --- INICIO ---
echo "--- LOG PLATA ($ORDER_ID) ---" > "$LOG_FILE"
update_status 0 "Iniciando Plan Plata (DB + SSH)..."

# Limpieza
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)
for ZOMBIE in $ZOMBIES; do
    minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
done

# Minikube
update_status 20 "Levantando Minikube..."
(minikube start -p "$CLUSTER_NAME" --driver=docker --cpus=2 --memory=2048m --addons=default-storageclass --force) >> "$LOG_FILE" 2>&1

update_status 40 "Configurando Tofu..."
kubectl config use-context "$CLUSTER_NAME" >> "$LOG_FILE" 2>&1
cd "$SCRIPT_DIR"
rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# Apply
update_status 50 "Desplegando Infraestructura..."
tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" \
    -var="db_name=$DB_NAME" >> "$LOG_FILE" 2>&1

# Espera Pods
update_status 70 "Esperando MySQL Pods..."
kubectl --context "$CLUSTER_NAME" wait --for=condition=Ready pod/mysql-master-0 --timeout=120s >> "$LOG_FILE" 2>&1
kubectl --context "$CLUSTER_NAME" wait --for=condition=Ready pod/mysql-slave-0 --timeout=120s >> "$LOG_FILE" 2>&1

# --- MEJORA V15: ESTABILIZACIÓN ---
update_status 75 "Estabilizando motor de base de datos..."
sleep 20 # Damos tiempo extra para que el socket de MySQL esté listo

# Replicación
update_status 85 "Configurando Replicación..."
MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"

# Intentar comando con reintento por si el socket tarda un poco más
n=0
until [ "$n" -ge 5 ]
do
   kubectl --context "$CLUSTER_NAME" exec mysql-master-0 -- $MYSQL_CMD -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;" >> "$LOG_FILE" 2>&1 && break
   n=$((n+1)) 
   echo "Retrying MySQL connection..." >> "$LOG_FILE"
   sleep 5
done

M_STATUS=$(kubectl --context "$CLUSTER_NAME" exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')

kubectl --context "$CLUSTER_NAME" exec mysql-slave-0 -- $MYSQL_CMD -e "STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;" >> "$LOG_FILE" 2>&1

# Final
update_status 95 "Generando accesos..."
HOST_IP=$(minikube ip -p "$CLUSTER_NAME")
NODE_PORT=$(tofu output -raw ssh_port 2>/dev/null || echo "Revisar")
CMD_SSH="ssh $SSH_USER@$HOST_IP -p $NODE_PORT"
INFO_DB="[DATABASE HA]\nName: $DB_NAME\nMaestro: mysql-master\nEsclavo: mysql-slave"
INFO_FINAL="[SSH ACCESO]\nUser: $SSH_USER\nPass: $SSH_PASS\n\n$INFO_DB"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Plata Lista!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_FINAL'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Plata completado."