#!/bin/bash
echo "🔧 Fixing MTU for Tailscale..."
sudo ip link set dev tailscale0 mtu 1200
echo "✅ MTU set to 1200 on tailscale0"
ip addr show dev tailscale0 | grep mtu
echo "🚀 Try connecting now: ssh ivan@100.97.47.100"
