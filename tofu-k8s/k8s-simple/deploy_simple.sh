#!/bin/bash
set -eE -o pipefail

# --- CONFIGURACIÓN DE LOGS ---
LOG_FILE="/tmp/deploy_simple_debug.log"

# --- TRAP DE ERRORES ---
handle_error() {
    local exit_code=$?
    echo -e "\n\033[0;31m❌ ERROR CRÍTICO (Exit Code: $exit_code) en línea $LINENO.\033[0m"
    echo -e "\033[0;33m--- ÚLTIMAS LÍNEAS DEL LOG ($LOG_FILE) ---\033[0m"
    tail -n 15 "$LOG_FILE"
    echo "{\"percent\": 100, \"message\": \"Fallo en despliegue. Revisa logs.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    exit $exit_code
}
trap 'handle_error' ERR

# --- 1. RECEPCIÓN DE ARGUMENTOS ---
cd "$(dirname "$0")"

ORDER_ID=$1
RAW_CLIENT_NAME=$2 # <--- ARGUMENTO CLIENTE

# Validación
[ -z "$ORDER_ID" ] && ORDER_ID="manual"

# --- SANITIZACIÓN DE USUARIO SSH ---
if [ -z "$RAW_CLIENT_NAME" ]; then
    SSH_USER="cliente"
else
    SSH_USER=$(echo "$RAW_CLIENT_NAME" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')
fi
[ -z "$SSH_USER" ] && SSH_USER="cliente"

# Variables de entorno
CLUSTER_NAME="sylo-cliente-$ORDER_ID"
BUZON_STATUS="$HOME/proyecto/buzon-pedidos/status_$ORDER_ID.json"
SSH_PASS=$(openssl rand -base64 12)

# --- FUNCIÓN STATUS ---
update_status() {
    echo "{\"percent\": $1, \"message\": \"$2\", \"status\": \"running\"}" > "$BUZON_STATUS"
    echo "📊 [Progreso $1%] $2"
}

# --- INICIO ---
echo "--- INICIO LOG BRONCE ($ORDER_ID) ---" > "$LOG_FILE"
update_status 0 "Iniciando Plan Bronce (Simple)..."

# ==========================================
# 🛑 BLOQUE ANTI-ZOMBIES (FIXED)
# ==========================================
update_status 5 "Limpiando residuos..."
echo "🧹 Buscando zombies del ID $ORDER_ID..." >> "$LOG_FILE"

# AÑADIDO "|| true" PARA EVITAR EL ERROR FANTASMA
ZOMBIES=$(minikube profile list 2>/dev/null | grep "\-$ORDER_ID" | awk '{print $2}' || true)

for ZOMBIE in $ZOMBIES; do
    if [ ! -z "$ZOMBIE" ]; then
        echo "💀 Eliminando zombie: $ZOMBIE" >> "$LOG_FILE"
        sudo minikube delete -p "$ZOMBIE" >> "$LOG_FILE" 2>&1 || true
    fi
done

# Limpieza sistema
sudo rm -f /tmp/juju-* 2>/dev/null
# ==========================================


# 20% - Creando VM
update_status 20 "Provisionando Minikube..."
(sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=1200m \
    --addons=default-storageclass \
    --interactive=false \
    --force \
    --no-vtx-check) >> "$LOG_FILE" 2>&1

# 40% - Configuración de Red y Permisos
update_status 40 "Configurando entorno..."

# --- ARREGLO DE PERMISOS ---
{
    sudo rm -rf "$HOME/.minikube"
    sudo cp -r /root/.minikube "$HOME/"
    sudo chown -R "$USER":"$USER" "$HOME/.minikube"
    mkdir -p "$HOME/.kube"
    sudo cp /root/.kube/config "$HOME/.kube/config"
    sudo chown "$USER":"$USER" "$HOME/.kube/config"
    sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
    kubectl config use-context "$CLUSTER_NAME"
} >> "$LOG_FILE" 2>&1


# 60% - OpenTofu
update_status 60 "Desplegando infraestructura..."

rm -f terraform.tfstate*
tofu init -upgrade >> "$LOG_FILE" 2>&1

# APLICAMOS CON USUARIO Y PASSWORD
tofu apply -auto-approve \
    -var="nombre=$CLUSTER_NAME" \
    -var="ssh_password=$SSH_PASS" \
    -var="ssh_user=$SSH_USER" >> "$LOG_FILE" 2>&1

# 85% - Espera final
update_status 85 "Verificando servicio SSH..."
kubectl wait --for=condition=available --timeout=120s deployment/ssh-server >> "$LOG_FILE" 2>&1

# 100% - FINALIZADO
update_status 95 "Generando accesos..."

HOST_IP=$(sudo minikube ip -p "$CLUSTER_NAME")
NODE_PORT=$(tofu output -raw ssh_port)

# Usamos el usuario sanitizado
CMD_SSH="ssh $SSH_USER@$HOST_IP -p $NODE_PORT"
INFO_FINAL="[SSH ACCESO]\nUser: $SSH_USER\nPass: $SSH_PASS"

# JSON FINAL
JSON_STRING=$(python3 -c "import json; print(json.dumps({'percent': 100, 'message': '¡Clúster Bronce Listo!', 'status': 'completed', 'ssh_cmd': '$CMD_SSH', 'ssh_pass': '''$INFO_FINAL'''}))")
echo "$JSON_STRING" > "$BUZON_STATUS"

echo "✅ Despliegue Bronce completado."