#!/bin/bash
set -e
set -u
set -o pipefail

# --- 0. PREPARACIÓN DE DIRECTORIO ---
cd "$(dirname "$0")"

# --- VARIABLES ---
CLUSTER_NAME="ClienteDB-$(date +%s)"
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

# Recursos
MINIKUBE_CPUS=2
MINIKUBE_MEMORY=2048m

# Funciones visuales
info() { echo -e "\n🔵 \033[1m$1\033[0m"; }
success() { echo -e "✅ \033[1;32m$1\033[0m"; }
warn() { echo -e "⚠️  \033[1;33m$1\033[0m"; }
error() { echo -e "❌ \033[1;31m$1\033[0m" >&2; exit 1; }

# Comandos MySQL
MYSQL_CMD="mysql -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"
ADMIN_CMD="mysqladmin -h 127.0.0.1 -P 3306 --protocol=tcp -u root -ppassword_root"

wait_for_mysql() {
  local pod=$1
  echo -n "   ⏳ Esperando conexión MySQL en $pod..."
  for i in {1..30}; do
    if kubectl exec "$pod" -- $ADMIN_CMD ping --silent > /dev/null 2>&1; then
      echo " ¡Listo!"
      return 0
    fi
    echo -n "."
    sleep 2
  done
  echo " ❌ Timeout."
  return 1
}

# ---------------------------------------------------------
# 1. INFRAESTRUCTURA
# ---------------------------------------------------------

info "🚀 INICIANDO DESPLIEGUE AUTOMATIZADO: $CLUSTER_NAME"

# --- CORRECCIÓN DE LOCKS / PERMISOS (JUJU ERROR) ---
info "Limpiando candados de sistema..."
sudo sysctl fs.protected_regular=0 > /dev/null 2>&1
sudo rm -f /tmp/juju-*
sudo rm -f /tmp/minikube*
# ---------------------------------------------------

# Limpieza previa
if minikube profile list 2>/dev/null | grep -q "$CLUSTER_NAME"; then
    sudo minikube delete -p "$CLUSTER_NAME"
fi

info "Levantando Servidores (Minikube)..."

# Usamos sudo y --force
sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus="$MINIKUBE_CPUS" \
    --memory="$MINIKUBE_MEMORY" \
    --addons=default-storageclass \
    --force 

# ---------------------------------------------------------
# 🔥 ARREGLO DE PERMISOS: COPIAR CERTIFICADOS (ESTO FALTABA) 🔥
# ---------------------------------------------------------
info "Sincronizando certificados de Root a $USER..."

# 1. Limpiamos la carpeta local antigua para evitar conflictos
sudo rm -rf "$HOME/.minikube"

# 2. Copiamos la carpeta .minikube entera de root a tu usuario
sudo cp -r /root/.minikube "$HOME/"

# 3. Te damos la propiedad de los archivos
sudo chown -R "$USER":"$USER" "$HOME/.minikube"

# 4. Copiamos y arreglamos el archivo de configuración (kubeconfig)
mkdir -p "$HOME/.kube"
sudo cp /root/.kube/config "$HOME/.kube/config"
sudo chown "$USER":"$USER" "$HOME/.kube/config"

# 5. Reemplazamos la ruta '/root' por '/home/ivan' dentro del archivo
sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
# ---------------------------------------------------------

# Aseguramos contexto
kubectl config use-context "$CLUSTER_NAME"

info "Desplegando Arquitectura (OpenTofu)..."
# Inicializamos Tofu
rm -f terraform.tfstate terraform.tfstate.backup
tofu init -upgrade

# Aplicamos
tofu apply -auto-approve -var="nombre=$CLUSTER_NAME"

# ---------------------------------------------------------
# 2. ESPERA Y SINCRONIZACIÓN
# ---------------------------------------------------------

info "Esperando arranque de Pods..."
kubectl wait --for=condition=Ready pod/mysql-master-0 --timeout=180s
kubectl wait --for=condition=Ready pod/mysql-slave-0 --timeout=180s

info "Verificando servicios..."
wait_for_mysql "mysql-master-0" || error "Fallo en Maestro"
wait_for_mysql "mysql-slave-0" || error "Fallo en Esclavo"

info "🤖 Configurando REPLICACIÓN SQL..."

# A. Maestro
echo "   -> Creando usuario replicador..."
kubectl exec mysql-master-0 -- $MYSQL_CMD -e "
  CREATE USER IF NOT EXISTS 'replicator'@'%' IDENTIFIED WITH mysql_native_password BY 'repl_password';
  GRANT REPLICATION SLAVE ON *.* TO 'replicator'@'%';
  FLUSH PRIVILEGES;"

# B. Coordenadas
echo "   -> Obteniendo coordenadas..."
MASTER_STATUS=$(kubectl exec mysql-master-0 -- $MYSQL_CMD -e "SHOW MASTER STATUS\G")
LOG_FILE=$(echo "$MASTER_STATUS" | grep "File:" | awk '{print $2}')
LOG_POS=$(echo "$MASTER_STATUS" | grep "Position:" | awk '{print $2}')

if [ -z "$LOG_FILE" ]; then error "No se obtuvieron coordenadas."; fi
success "Coordenadas: $LOG_FILE / $LOG_POS"

# C. Esclavo
echo "   -> Inyectando en Esclavo..."
kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "
  STOP SLAVE;
  CHANGE MASTER TO
    MASTER_HOST='mysql-master.default.svc.cluster.local',
    MASTER_USER='replicator',
    MASTER_PASSWORD='repl_password',
    MASTER_LOG_FILE='$LOG_FILE',
    MASTER_LOG_POS=$LOG_POS;
  START SLAVE;"

# ---------------------------------------------------------
# 3. VERIFICACIÓN FINAL
# ---------------------------------------------------------
sleep 3
SLAVE_STATUS=$(kubectl exec mysql-slave-0 -- $MYSQL_CMD -e "SHOW SLAVE STATUS\G")
IO_RUNNING=$(echo "$SLAVE_STATUS" | grep "Slave_IO_Running:" | awk '{print $2}')
SQL_RUNNING=$(echo "$SLAVE_STATUS" | grep "Slave_SQL_Running:" | awk '{print $2}')

if [ "$IO_RUNNING" == "Yes" ] && [ "$SQL_RUNNING" == "Yes" ]; then
  echo ""
  echo "=========================================================="
  success "¡DESPLIEGUE COMPLETADO CON ÉXITO!"
  echo "=========================================================="
  echo "📡 Cluster: $CLUSTER_NAME"
  echo "📂 Estado Replicación: SINC (Yes/Yes)"
  echo "----------------------------------------------------------"
else
  error "Fallo en replicación ($IO_RUNNING / $SQL_RUNNING)"
fi