#!/bin/bash
set -e

# Configuración de Red
TAILSCALE_IFACE="tailscale0"
INTERNAL_NET="10.100.0.0/16"

echo "=== Aplicando Hardening de Firewall (UFW) ==="
echo "[!] ADVERTENCIA: Asegúrate de estar conectado por Tailscale o Consola."
echo "    Se bloqueará el puerto 22 público."

# 1. Resetear reglas
echo "[+] Reseteando reglas UFW..."
sudo ufw --force reset

# 2. Denegar todo el tráfico entrante por defecto
sudo ufw default deny incoming
sudo ufw default allow outgoing

# 2.5 Permitir tráfico local (Loopback) - CRÍTICO PARA MYSQL/APIS LOCALES
echo "[+] Permitiendo tráfico en Loopback (localhost)..."
sudo ufw allow in on lo to any
sudo ufw allow out on lo to any

# 3. Permitir tráfico en interfaz Tailscale
echo "[+] Permitiendo tráfico en $TAILSCALE_IFACE..."
sudo ufw allow in on $TAILSCALE_IFACE to any
# Allow Docker Container -> Host
sudo ufw allow from 172.16.0.0/12 to any

# 4. Permitir SSH SOLO desde la red interna (Mesh/VPN)
echo "[+] Permitiendo SSH solo desde $INTERNAL_NET..."
sudo ufw allow from $INTERNAL_NET to any port 22 proto tcp

# 5. Habilitar Firewall
echo "[+] Habilitando UFW..."
sudo ufw enable

echo "=== Hardening Completado ==="
sudo ufw status verbose
