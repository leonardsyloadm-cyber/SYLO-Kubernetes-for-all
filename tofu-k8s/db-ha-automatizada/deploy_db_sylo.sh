#!/bin/bash
set -e
cd "$(dirname "$0")"

# --- 1. RECEPCIÓN DE ARGUMENTOS ---
ORDER_ID=$1
RAW_CLIENT_NAME=$2  # <--- NUEVO: Nombre del cliente

# Validación básica
[ -z "$ORDER_ID" ] && ORDER_ID="manual"

# --- SANITIZACIÓN DE USUARIO SSH ---
if [ -z "$RAW_CLIENT_NAME" ]; then
    SSH_USER="cliente"
else
    # Minúsculas y solo alfanumérico (Ej: "Juan Pérez" -> "juanperez")
    SSH_USER=$(echo "$RAW_CLIENT_NAME" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')
fi
[ -z "$SSH_USER" ] && SSH_USER="cliente"

# NOMBRE ESTANDARIZADO
CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$HOME/proyecto/buzon-pedidos/status_$ORDER_ID.json"
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

# --- OPTIMIZACIÓN TOFU: CACHÉ GLOBAL ---
export TF_PLUGIN_CACHE_DIR="$HOME/.terraform.d/plugin-cache"
mkdir -p "$TF_PLUGIN_CACHE_DIR"

# Generar Password SSH
SSH_PASS=$(openssl rand -base64 12)
export TF_VAR_ssh_password="$SSH_PASS"

# --- GESTIÓN DE ERRORES ---
handle_error() {
    echo "{\"percent\": 100, \"message\": \"Fallo en DB. Revisa logs.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    exit 1
}
trap 'handle_error' ERR

update_status() {
    echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    echo "📊 [Progreso $1%] $2"
}

# --- INICIO ---
update_status 0 "Iniciando Plan Plata (DB HA)..."

# ==========================================
# 🛑 BLOQUE ANTI-ZOMBIES (FIXED)
# ==========================================
update_status 5 "Limpiando residuos..."

# Busca cualquier perfil que termine en "-$ORDER_ID"
# AÑADIDO "|| true" PARA EVITAR EL ERROR FANTASMA SI NO HAY RESULTADOS
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)

for ZOMBIE in $ZOMBIES; do
    if [ ! -z "$ZOMBIE" ]; then
        echo "💀 Detectado zombie: $ZOMBIE. Eliminando..."
        sudo minikube delete -p "$ZOMBIE" > /dev/null 2>&1 || true
    fi
done

# Limpieza rápida de sistema
sudo rm -f /tmp/juju-* 2>/dev/null
# ==========================================


# 20% - Minikube
update_status 20 "Levantando Cluster DB..."
(sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=2048m \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check) > /dev/null 2>&1

# 40% - Permisos (CRÍTICO PARA TOFU)
update_status 40 "Configurando entorno..."
sudo rm -rf "$HOME/.minikube"
sudo cp -r /root/.minikube "$HOME/"
sudo chown -R "$USER":"$USER" "$HOME/.minikube"
mkdir -p "$HOME/.kube"
sudo cp /root/.kube/config "$HOME/.kube/config"
sudo chown "$USER":"$USER" "$HOME/.kube/config"
sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
kubectl config use-context "$CLUSTER_NAME" > /dev/null

# 50% - Tofu
update_status 50 "Desplegando infraestructura..."
rm -f terraform.tfstate*
tofu init -upgrade > /dev/null

# APLICAMOS CON LA NUEVA VARIABLE DE USUARIO
tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" > /dev/null

# 70% - Espera Inteligente
update_status 70 "Esperando arranque MySQL..."

kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=120s > /dev/null || true
kubectl wait --for=condition=Ready pod/mysql-slave-0 --timeout=120s > /dev/null || true

# Comandos de conexión TCP forzada
MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"
ADMIN_CMD="mysqladmin -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"

# Bucle rápido de conexión (ping cada 1s)
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

# Configuración Maestro
kubectl exec mysql-master-0 -- $MYSQL_CMD -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;"

M_STATUS=$(kubectl exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')

# Configuración Esclavo
kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;"

# 100% - FINAL Y DATOS
HOST_IP=$(sudo minikube ip -p "$CLUSTER_NAME")
# Sacamos el puerto SSH del output de Tofu
NODE_PORT=$(tofu output -raw ssh_port)

# USAMOS EL USUARIO PERSONALIZADO
CMD_SSH="ssh $SSH_USER@$HOST_IP -p $NODE_PORT"

INFO_DB="Maestro: mysql-master.default.svc.cluster.local\nEsclavo: mysql-slave.default.svc.cluster.local\nUser: kylo_user / Pass: kylo_password"
INFO_FINAL="[SSH ACCESO]\nUser: $SSH_USER\nPass: $SSH_PASS\n\n[DATABASE]\n$INFO_DB"

# Generamos el JSON final para la ventana verde
JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Base de Datos HA Lista!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_FINAL'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Plata completado."