#!/bin/bash
set -e
cd "$(dirname "$0")"

CLUSTER_NAME="ClienteWeb-$(date +%s)"
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

info() { echo -e "\n🔵 \033[1m$1\033[0m"; }
success() { echo -e "✅ \033[1;32m$1\033[0m"; }
warn() { echo -e "⚠️  \033[1;33m$1\033[0m"; }

# --- 1. INFRAESTRUCTURA ---
info "🚀 INICIANDO DESPLIEGUE WEB HA + SSH: $CLUSTER_NAME"

# Limpieza de candados
sudo sysctl fs.protected_regular=0 > /dev/null 2>&1
sudo rm -f /tmp/juju-*

# Iniciar Minikube
(sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=1024m \
    --addons=default-storageclass \
    --interactive=false \
    --force) > /dev/null 2>&1

# Permisos
sudo rm -rf "$HOME/.minikube"
sudo cp -r /root/.minikube "$HOME/"
sudo chown -R "$USER":"$USER" "$HOME/.minikube"
mkdir -p "$HOME/.kube"
sudo cp /root/.kube/config "$HOME/.kube/config"
sudo chown "$USER":"$USER" "$HOME/.kube/config"
sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"

kubectl config use-context "$CLUSTER_NAME" > /dev/null

# --- 2. DESPLIEGUE ---

info "Generando credenciales y aplicando OpenTofu..."
# Generamos la contraseña aquí
SSH_PASS=$(openssl rand -base64 12)

rm -f terraform.tfstate terraform.tfstate.backup
tofu init -upgrade > /dev/null

# ¡IMPORTANTE! Pasamos la variable ssh_password
tofu apply -auto-approve -var="nombre=$CLUSTER_NAME" -var="ssh_password=$SSH_PASS" > /dev/null

# --- 3. VERIFICACIÓN ---

info "Esperando a que los servicios estén listos..."
kubectl wait --for=condition=available --timeout=60s deployment/nginx-ha > /dev/null
kubectl wait --for=condition=available --timeout=60s deployment/ssh-server > /dev/null

# Obtener Datos
WEB_URL=$(sudo minikube service web-service -p "$CLUSTER_NAME" --url)
HOST_IP=$(sudo minikube ip -p "$CLUSTER_NAME")
# Sacamos el puerto SSH del output de Tofu
SSH_PORT=$(tofu output -raw ssh_port)

echo ""
echo "=========================================================="
success "¡SERVIDOR WEB HA + ACCESO SSH DESPLEGADO!"
echo "=========================================================="
echo "📡 Cluster: $CLUSTER_NAME"
echo ""
echo "🌍 WEB PÚBLICA:"
echo "   👉 $WEB_URL"
echo ""
echo "🔑 ACCESO SSH:"
echo "   Comando: ssh cliente@$HOST_IP -p $SSH_PORT"
echo "   Pass:    $SSH_PASS"
echo "=========================================================="