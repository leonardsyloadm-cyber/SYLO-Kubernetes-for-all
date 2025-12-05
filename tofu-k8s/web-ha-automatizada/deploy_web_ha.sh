#!/bin/bash
set -e
set -u
set -o pipefail

# --- 0. PREPARACIÓN ---
cd "$(dirname "$0")"
CLUSTER_NAME="ClienteWeb-$(date +%s)"
export TF_VAR_nombre="$CLUSTER_NAME"

info() { echo -e "\n🔵 \033[1m$1\033[0m"; }
success() { echo -e "✅ \033[1;32m$1\033[0m"; }
warn() { echo -e "⚠️  \033[1;33m$1\033[0m"; }

# --- 1. INFRAESTRUCTURA ---

info "🚀 INICIANDO DESPLIEGUE WEB HA: $CLUSTER_NAME"

# Limpieza silenciosa de candados
sudo sysctl fs.protected_regular=0 > /dev/null 2>&1
sudo rm -f /tmp/juju-*

# Iniciar Minikube (Silenciando output innecesario)
echo "   ⏳ Levantando clúster (esto tarda un poco)..."
(sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=1024m \
    --addons=default-storageclass \
    --interactive=false \
    --force) > /dev/null 2>&1

# --- ARREGLO DE PERMISOS ---
sudo rm -rf "$HOME/.minikube"
sudo cp -r /root/.minikube "$HOME/"
sudo chown -R "$USER":"$USER" "$HOME/.minikube"
mkdir -p "$HOME/.kube"
sudo cp /root/.kube/config "$HOME/.kube/config"
sudo chown "$USER":"$USER" "$HOME/.kube/config"
sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
# ---------------------------

kubectl config use-context "$CLUSTER_NAME" > /dev/null

# --- 2. DESPLIEGUE ---

info "Aplicando configuración Nginx HA..."
rm -f terraform.tfstate terraform.tfstate.backup
tofu init -upgrade > /dev/null
tofu apply -auto-approve -var="nombre=$CLUSTER_NAME" > /dev/null

# --- 3. VERIFICACIÓN ---

info "Esperando a que las 2 réplicas estén listas..."
kubectl wait --for=condition=available --timeout=60s deployment/nginx-ha > /dev/null

# Obtenemos la URL usando sudo para evitar el error de permisos
WEB_URL=$(sudo minikube service web-service -p "$CLUSTER_NAME" --url)

echo ""
echo "=========================================================="
success "¡SERVIDOR WEB REPLICADO (HA) DESPLEGADO!"
echo "=========================================================="
echo "📡 Cluster: $CLUSTER_NAME"
echo "📦 Pods Activos (Tus servidores):"
kubectl get pods -l app=web-cliente
echo ""
echo "🌍 ACCESO WEB:"
echo "   👉 $WEB_URL"
echo ""
echo "=========================================================="
