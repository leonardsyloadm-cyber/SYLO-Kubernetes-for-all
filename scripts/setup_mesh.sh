#!/bin/bash

# setup_mesh.sh
# Configura un nodo para la red overlay de Sylo usando Tailscale.
# Requisitos: sudo/root privileges.

set -e

# Colores para output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Capturar Ctrl+C
trap "echo -e '\n${RED}[ABORTED]${NC} Setup interrupted by user.'; exit 1" SIGINT

log() {
    echo -e "${BLUE}[SYLO-MESH]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

# 1. Detección de OS y Gestor de Paquetes
log "Detecting OS and package manager..."
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    error "Cannot detect OS: /etc/os-release not found."
fi

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

if command_exists apt-get; then
    PKG_MANAGER="apt"
elif command_exists apk; then
    PKG_MANAGER="apk"
    # Asegurar repositorio community para Alpine si es necesario, 
    # aunque el script oficial maneja esto, es bueno saber el entorno.
else
    log "Warning: Neither apt nor apk found. Proceeding with generic install..."
    PKG_MANAGER="unknown"
fi

log "OS detected: $OS, Package Manager: $PKG_MANAGER"

# 2. Instalación de Tailscale
log "Installing Tailscale..."

if command_exists tailscale; then
    log "Tailscale is already installed. Checking for updates..."
    # En producción real podriamos actualizar aquí, por ahora asumimos OK.
else
    # Usar el script de instalación oficial que soporta apt, apk, yum, dnf, pacman...
    curl -fsSL https://tailscale.com/install.sh | sh
fi

# Habilitar servicio
log "Enabling Tailscale service..."
if command_exists systemctl; then
    # Intentar habilitar y arrancar. Si falla (timeout), verificamos si el proceso ya corre.
    if ! systemctl enable --now tailscaled; then
        log "${RED}Warning${NC}: 'systemctl enable --now' failed (likely D-Bus timeout)."
        if pgrep tailscaled > /dev/null; then
            log "Tailscaled is actively running (PID $(pgrep tailscaled)). Proceeding..."
        else
            # Intentar arranque manual como fallback si no corre
            log "Attempting manual start..."
            tailscaled > /dev/null 2>&1 &
            sleep 2
            if pgrep tailscaled > /dev/null; then
                 log "Tailscaled started manually."
            else
                 error "Could not start tailscaled service."
            fi
        fi
    fi
elif command_exists rc-update; then
    rc-update add tailscale default
    rc-service tailscale start
else
    log "Warning: Could not detect systemd or openrc. Ensure 'tailscaled' is running manually."
fi

# 3. Configuración de IP Forwarding
log "Configuring IP forwarding..."

# Habilitar forwarding IPv4 e IPv6
echo 'net.ipv4.ip_forward = 1' | tee /etc/sysctl.d/99-tailscale.conf > /dev/null
echo 'net.ipv6.conf.all.forwarding = 1' | tee -a /etc/sysctl.d/99-tailscale.conf > /dev/null

# Aplicar cambios
sysctl -p /etc/sysctl.d/99-tailscale.conf

# 4. Inicio del Agente
log "Starting Tailscale agent..."

log "Restarting Tailscale service to ensure clean state..."
if command_exists systemctl; then
    systemctl restart tailscaled
    sleep 3
elif command_exists rc-service; then
    rc-service tailscale restart
    sleep 3
fi

# --advertise-tags=tag:sylo-node: REMOVED to avoid auth errors (Requires ACL config in Admin Panel)
# --accept-routes: Acepta rutas anunciadas por otros nodos (Subnet router)
TS_ARGS="--accept-routes"

# Permitir configurar hostname personalizado si se pasa como argumento
if [ -n "$1" ]; then
    log "Custom hostname requested: $1"
    TS_ARGS="$TS_ARGS --hostname=$1"
fi

if [ -n "$TAILSCALE_AUTH_KEY" ]; then
    TS_ARGS="$TS_ARGS --authkey=$TAILSCALE_AUTH_KEY"
else
    # Si no hay key, forzamos re-auth para asegurar que salga la URL si estaba en estado zombie
    # Nota: Si cambiamos de hostname, es buena practica forzar reautenticacion para registrar el nuevo nombre
    TS_ARGS="$TS_ARGS --force-reauth"
fi

log "Running: tailscale up $TS_ARGS"
log "NOTE: If this is the first run, you will see a URL below. Please visit it to authenticate."
log "The script will pause here until the node is authenticated."

# Ejecutar tailscale up
tailscale up $TS_ARGS

# Verificar estado de autenticación
if tailscale status | grep -q "Logged out"; then
    log "${RED}Node is still Logged Out.${NC}"
    log "It seems 'tailscale up' finished without authentication."
    log "Running 'tailscale login' to generate an Auth URL..."
    tailscale login
fi

# 5. Salida Esperada
log "Retrieving Node Information..."

TS_IP=$(tailscale ip -4)
echo -e "Tailscale IP: ${GREEN}${TS_IP}${NC}"

log "Running Connectivity Test..."
# Ping al servidor DNS magico de Tailscale 100.100.100.100
if ping -c 3 100.100.100.100 > /dev/null 2>&1; then
    echo -e "Connectivity Test: ${GREEN}SUCCESS${NC} (100.100.100.100 reachable)"
else
    echo -e "Connectivity Test: ${RED}FAILED${NC} (Is the node authenticated?)"
fi

log "Setup Complete."
