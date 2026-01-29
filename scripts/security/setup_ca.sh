#!/bin/bash
set -e

# Configuración
CA_DIR="/etc/ssh"
CA_KEY="sylo_ca"
CA_PUB="sylo_ca.pub"

echo "=== Configurando Sylo SSH CA ==="

# 1. Generar pares de claves si no existen
if [ -f "$CA_DIR/$CA_KEY" ]; then
    echo "[!] La CA ya existe en $CA_DIR/$CA_KEY"
else
    echo "[+] Generando nuevas claves para la CA..."
    sudo ssh-keygen -t rsa -b 4096 -f "$CA_DIR/$CA_KEY" -N "" -C "Sylo Internal CA"
fi

# 2. Proteger la clave privada
echo "[+] Asegurando permisos de la clave privada..."
sudo chmod 400 "$CA_DIR/$CA_KEY"
sudo chown root:root "$CA_DIR/$CA_KEY"

# 3. Configurar sshd para confiar en esta CA
CA_CONFIG_LINE="TrustedUserCAKeys $CA_DIR/$CA_PUB"
SSHD_CONFIG="/etc/ssh/sshd_config"

if grep -q "TrustedUserCAKeys" "$SSHD_CONFIG"; then
    echo "[!] Configuración TrustedUserCAKeys ya detectada en $SSHD_CONFIG"
    echo "    Verifica que apunte a $CA_DIR/$CA_PUB"
else
    echo "[+] Añadiendo TrustedUserCAKeys a $SSHD_CONFIG..."
    echo "$CA_CONFIG_LINE" | sudo tee -a "$SSHD_CONFIG"
fi

# 4. Reiniciar SSH
echo "[+] Reiniciando servicio SSH..."
sudo systemctl restart ssh

echo "=== Setup CA Completado exitosamente ==="
