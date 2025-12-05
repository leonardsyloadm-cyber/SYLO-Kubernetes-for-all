#!/bin/bash
set -e
cd "$(dirname "$0")"

# Recibimos el ID de la orden desde el Orquestador
ORDER_ID=$1

# Variables de entorno
CLUSTER_NAME="ClienteBronce-$ORDER_ID"
BUZON_STATUS="$HOME/proyecto/buzon-pedidos/status_$ORDER_ID.json"
TF_VAR_nombre="$CLUSTER_NAME"
export TF_VAR_nombre

# --- FUNCIÓN PARA GESTIONAR ERRORES ---
handle_error() {
    echo "❌ Error crítico en la línea $1"
    echo "{\"percent\": 100, \"message\": \"Error crítico en el despliegue. Revisa los logs del servidor.\", \"status\": \"error\"}" > "$BUZON_STATUS"
    # No hacemos exit aquí para permitir que el trap termine limpiamente, pero el proceso morirá
}
# Si hay un error, ejecutamos la función
trap 'handle_error $LINENO' ERR

# --- FUNCIÓN PARA ACTUALIZAR ESTADO EN LA WEB ---
update_status() {
    local percent=$1
    local msg=$2
    # Escribimos el JSON que leerá el PHP/JS
    echo "{\"percent\": $percent, \"message\": \"$msg\", \"status\": \"running\"}" > "$BUZON_STATUS"
    # Feedback en la terminal del orquestador
    echo "📊 [Progreso $percent%] $msg"
}

# ==============================================================================
# INICIO DEL PROCESO
# ==============================================================================

# 0% - Inicio
update_status 0 "Iniciando maquinaria de despliegue..."

# Limpieza de candados y clústeres previos
sudo rm -f /tmp/juju-* 2>/dev/null
if minikube profile list 2>/dev/null | grep -q "$CLUSTER_NAME"; then
    update_status 5 "Limpiando instalación anterior..."
    sudo minikube delete -p "$CLUSTER_NAME" > /dev/null 2>&1
fi

# 25% - Creando VM
update_status 20 "Provisionando máquina virtual (Minikube)..."
# Usamos sudo + force para evitar errores de permisos de Docker
(sudo minikube start -p "$CLUSTER_NAME" \
    --driver=docker \
    --cpus=2 \
    --memory=1024m \
    --addons=default-storageclass \
    --interactive=false \
    --force) > /dev/null 2>&1

# 40% - Configuración de Red y Permisos
update_status 40 "Configurando certificados y contexto..."

# --- ARREGLO DE PERMISOS (Vital para que Tofu funcione) ---
sudo rm -rf "$HOME/.minikube"
sudo cp -r /root/.minikube "$HOME/"
sudo chown -R "$USER":"$USER" "$HOME/.minikube"

mkdir -p "$HOME/.kube"
sudo cp /root/.kube/config "$HOME/.kube/config"
sudo chown "$USER":"$USER" "$HOME/.kube/config"

# Reemplazamos la ruta de root por la de usuario en el config
sed -i "s|/root/.minikube|$HOME/.minikube|g" "$HOME/.kube/config"
# ---------------------------------------------------------

kubectl config use-context "$CLUSTER_NAME" > /dev/null

# 60% - OpenTofu
update_status 60 "Inicializando motor OpenTofu..."
# Generamos contraseña segura para el cliente
SSH_PASS=$(openssl rand -base64 12)

rm -f terraform.tfstate terraform.tfstate.backup
tofu init -upgrade > /dev/null

update_status 70 "Desplegando Pod SSH (VPS Simulado)..."
tofu apply -auto-approve -var="nombre=$CLUSTER_NAME" -var="ssh_password=$SSH_PASS" > /dev/null

# 90% - Espera final
update_status 85 "Esperando IP pública y asignación de puertos..."
# Esperamos a que el deployment esté listo
kubectl wait --for=condition=available --timeout=90s deployment/ssh-server > /dev/null

# Recopilación de datos finales
update_status 95 "Finalizando configuración..."

# Obtenemos la IP (En local es la de Minikube, en prod sería tu IP pública)
# Para que el comando funcione con sudo minikube, a veces hay que especificar el perfil
HOST_IP=$(sudo minikube ip -p "$CLUSTER_NAME")
# Obtenemos el puerto NodePort asignado desde el output de Tofu
NODE_PORT=$(tofu output -raw ssh_port)

CMD_SSH="ssh cliente@$HOST_IP -p $NODE_PORT"

# 100% - FINALIZADO
# Escribimos el JSON final con los datos de conexión. La web detectará "completed" y mostrará la ventana verde.
echo "{\"percent\": 100, \"message\": \"¡Clúster Creado!\", \"status\": \"completed\", \"ssh_cmd\": \"$CMD_SSH\", \"ssh_pass\": \"$SSH_PASS\"}" > "$BUZON_STATUS"

echo "✅ Despliegue Bronce completado para Orden #$ORDER_ID."