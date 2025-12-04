#!/bin/bash
set -e

CLUSTER_NAME="ClienteBronce-$(date +%s)"

echo "🥉 Iniciando despliegue Plan BRONCE: $CLUSTER_NAME"
echo "ℹ️  Solo Kubernetes base (Sin replicación, sin DB)"

# Minikube ligero (1 CPU)
minikube start -p "$CLUSTER_NAME" --driver=docker --cpus=1 --memory=1024m --force

kubectl config use-context "$CLUSTER_NAME"

echo "✅ Cluster Bronce listo. Acceso SSH disponible."
