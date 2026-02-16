#!/bin/bash
# Script para IVÁN (ejecutar en su máquina 100.97.47.100)
# Esto soluciona que Leonard no pueda conectar por SSH.

if [ "$EUID" -ne 0 ]; then 
  echo "❌ Por favor, ejecuta como root (sudo ./fix_ivan.sh)"
  exit
fi

echo "🔧 [IVAN] Ajustando MTU de Tailscale a 1200..."
ip link set dev tailscale0 mtu 1200

echo "🔧 [IVAN] Asegurando permisos de authorized_keys..."
chmod 600 /home/ivan/.ssh/authorized_keys 2>/dev/null || true
chmod 700 /home/ivan/.ssh 2>/dev/null || true
chown -R ivan:ivan /home/ivan/.ssh 2>/dev/null || true

echo "✅ Listo. Dile a Leonard que pruebe ahora."
