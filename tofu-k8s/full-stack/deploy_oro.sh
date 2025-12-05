#!/bin/bash
set -e
# Directorio base del proyecto
BASE_DIR="$HOME/proyecto/tofu-k8s"

ORDER_ID=$1
CLUSTER_NAME="ClienteOro-$ORDER_ID"
BUZON_STATUS="$HOME/proyecto/buzon-pedidos/status_$ORDER_ID.json"
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

# --- OPTIMIZACIÓN: USAR CACHÉ DE TOFU ---
export TF_PLUGIN_CACHE_DIR="$HOME/.terraform.d/plugin-cache"
mkdir -p "$TF_PLUGIN_CACHE_DIR"

# Generar Password SSH Maestra (Para todo el entorno)
SSH_PASS=$(openssl rand -base64 12)
export TF_VAR_ssh_password="$SSH_PASS"

# Gestión de errores
handle_error() {
    echo "{\"percent\": 100, \"message\": \"Fallo en Plan Oro. Revisa logs.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    exit 1
}
trap 'handle_error' ERR

update_status() {
    echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    echo "🥇 [Oro $1%] $2"
}

# Funciones MySQL
MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"
ADMIN_CMD="mysqladmin -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"

wait_for_mysql() {
  local pod=$1
  echo -n "   ⏳ Esperando conexión MySQL en $pod..."
  for i in {1..60}; do
    if kubectl exec "$pod" -- $ADMIN_CMD ping --silent > /dev/null 2>&1; then
      echo " ¡Listo!"
      return 0
    fi
    echo -n "."
    sleep 2
  done
  echo " ❌ Timeout esperando MySQL."
  return 1
}

# --- INICIO ---
update_status 0 "Iniciando Plan ORO (Full Stack)..."

# Limpieza
sudo rm -f /tmp/juju-* 2>/dev/null
if minikube profile list 2>/dev/null | grep -q "$CLUSTER_NAME"; then
    sudo minikube delete -p "$CLUSTER_NAME" > /dev/null 2>&1
fi

# 15% - Minikube
update_status 15 "Levantando Clúster de Alto Rendimiento..."
(sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=3 \
    --memory=3072m \
    --addons=default-storageclass \
    --interactive=false \
    --force --no-vtx-check) > /dev/null 2>&1

# 25% - Permisos
update_status 25 "Configurando red..."
sudo rm -rf "$HOME/.minikube"
sudo cp -r /root/.minikube "$HOME/"
sudo chown -R "$USER":"$USER" "$HOME/.minikube"
mkdir -p "$HOME/.kube"
sudo cp /root/.kube/config "$HOME/.kube/config"
sudo chown "$USER":"$USER" "$HOME/.kube/config"
sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
kubectl config use-context "$CLUSTER_NAME" > /dev/null

# --- FASE 1: DB + SSH ---
update_status 35 "Fase 1: Desplegando Base de Datos y SSH..."
cd "$BASE_DIR/db-ha-automatizada"
rm -f terraform.tfstate*
tofu init -upgrade > /dev/null
# Pasamos ssh_password (esto crea el pod SSH)
tofu apply -auto-approve -var="nombre=$CLUSTER_NAME" -var="ssh_password=$SSH_PASS" > /dev/null

# GUARDAMOS EL PUERTO SSH AHORA (Vital)
SSH_PORT_FINAL=$(tofu output -raw ssh_port)

update_status 50 "Sincronizando Replicación MySQL..."
kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=180s > /dev/null
kubectl wait --for=condition=Ready pod/mysql-slave-0 --timeout=180s > /dev/null
wait_for_mysql "mysql-master-0"
wait_for_mysql "mysql-slave-0"

kubectl exec mysql-master-0 -- $MYSQL_CMD -e "CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl'; GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%'; FLUSH PRIVILEGES;"
M_STATUS=$(kubectl exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
FILE=$(echo "$M_STATUS" | grep "File:" | awk '{print $2}')
POS=$(echo "$M_STATUS" | grep "Position:" | awk '{print $2}')
kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "STOP SLAVE; CHANGE MASTER TO MASTER_HOST='mysql-master.default.svc.cluster.local', MASTER_USER='repl', MASTER_PASSWORD='repl', MASTER_LOG_FILE='$FILE', MASTER_LOG_POS=$POS; START SLAVE;"

# --- FASE 2: WEB (SIN SSH) ---
update_status 70 "Fase 2: Desplegando Servidor Web HA..."
cd "$BASE_DIR/web-ha-automatizada"
rm -f terraform.tfstate*
tofu init -upgrade > /dev/null

# NOTA: No pasamos ssh_password aquí porque el main.tf de web YA NO DEBE tener el módulo SSH
tofu apply -auto-approve -var="nombre=$CLUSTER_NAME" > /dev/null

update_status 85 "Verificando balanceo web..."
kubectl wait --for=condition=available --timeout=60s deployment/nginx-ha > /dev/null

# Obtener URL de la web
WEB_URL=$(sudo minikube service web-service -p "$CLUSTER_NAME" --url)
HOST_IP=$(sudo minikube ip -p "$CLUSTER_NAME")

# 100% - FINAL
# Usamos el puerto SSH que capturamos en la Fase 1 ($SSH_PORT_FINAL)
INFO="[WEB ACCESO]\nURL: $WEB_URL\n\n[SSH ACCESO]\nIP: $HOST_IP\nUser: cliente / Pass: $SSH_PASS\nPuerto: $SSH_PORT_FINAL\n\n[DATABASE]\nMaster: mysql-master.default.svc.cluster.local"

JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Infraestructura ORO completada!', 'status': 'completed', 'ssh_cmd': 'ssh cliente@$HOST_IP -p $SSH_PORT_FINAL', 'ssh_pass': '''$INFO'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Oro completado."