# Add this to operator_sylo.py

def handle_install_tool(oid, tool_name, config):
    """
    Instala herramientas adicionales en el clúster del cliente.
    Actualmente soporta: monitoring (Prometheus + Grafana)
    """
    profile = f"sylo-cliente-{oid}"
    log(f"🛠️ INSTALL TOOL: {tool_name} en {profile}", C_CYAN)
    
    if tool_name == "monitoring":
        install_monitoring_stack(oid, profile, config)
    else:
        log(f"⚠️ Herramienta desconocida: {tool_name}", C_WARNING)

def install_monitoring_stack(oid, profile, config):
    """
    Instala Prometheus + Grafana usando Helm en el namespace del cliente.
    """
    namespace = profile  # El namespace es el mismo que el profile
    grafana_password = config.get('grafana_password', 'admin')
    
    try:
        log(f"   📦 Instalando Monitoring Stack (Prometheus + Grafana)...", C_CYAN)
        
        # 1. Add Helm repo (idempotent)
        log("   ➕ Añadiendo repositorio Helm...", C_CYAN)
        run_command("helm repo add prometheus-community https://prometheus-community.github.io/helm-charts", silent=True)
        run_command("helm repo update", silent=True)
        
        # 2. Install kube-prometheus-stack with minimal resources
        log(f"   🚀 Instalando stack en namespace {namespace}...", C_CYAN)
        
        # Minimal values for resource-constrained environments
        helm_cmd = f"""helm upgrade --install sylo-monitor prometheus-community/kube-prometheus-stack \
            --namespace {namespace} \
            --create-namespace \
            --set prometheus.prometheusSpec.resources.requests.memory=200Mi \
            --set prometheus.prometheusSpec.resources.limits.memory=400Mi \
            --set prometheus.prometheusSpec.resources.requests.cpu=100m \
            --set prometheus.prometheusSpec.resources.limits.cpu=200m \
            --set prometheus.prometheusSpec.retention=7d \
            --set grafana.adminPassword={grafana_password} \
            --set grafana.resources.requests.memory=100Mi \
            --set grafana.resources.limits.memory=200Mi \
            --set grafana.resources.requests.cpu=50m \
            --set grafana.resources.limits.cpu=100m \
            --set grafana.persistence.enabled=false \
            --set alertmanager.enabled=false \
            --set nodeExporter.enabled=false \
            --set kubeStateMetrics.enabled=true \
            --set grafana.service.type=ClusterIP \
            --timeout 10m \
            --wait"""
        
        result = run_command(helm_cmd, silent=False)
        
        if "deployed" in result.lower() or "upgraded" in result.lower():
            log("   ✅ Helm installation successful", C_SUCCESS)
        else:
            raise Exception(f"Helm install failed: {result}")
        
        # 3. Wait for Grafana pod to be ready
        log("   ⏳ Esperando a que Grafana esté listo...", C_CYAN)
        time.sleep(10)  # Give it a moment
        
        wait_cmd = f"minikube -p {profile} kubectl -- wait --for=condition=ready pod -l app.kubernetes.io/name=grafana --namespace {namespace} --timeout=300s"
        run_command(wait_cmd, silent=True)
        
        # 4. Create Ingress for Grafana
        log("   🌐 Creando Ingress para Grafana...", C_CYAN)
        create_grafana_ingress(oid, profile, namespace)
        
        # 5. Update database status
        log("   💾 Actualizando estado en base de datos...", C_CYAN)
        update_tool_status(oid, "monitoring", "active")
        
        log(f"   ✅ MONITORING STACK INSTALADO CORRECTAMENTE", C_SUCCESS)
        log(f"   🔗 Grafana URL: http://grafana-{oid}.sylocloud.com", C_ACCENT_CYAN)
        log(f"   👤 Usuario: admin", C_ACCENT_CYAN)
        log(f"   🔑 Password: {grafana_password}", C_ACCENT_CYAN)
        
    except Exception as e:
        log(f"   ❌ Error instalando monitoring: {e}", C_DANGER)
        update_tool_status(oid, "monitoring", "error")

def create_grafana_ingress(oid, profile, namespace):
    """
    Crea un Ingress para exponer Grafana vía subdominio.
    """
    ingress_yaml = f"""apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: grafana-ingress
  namespace: {namespace}
  annotations:
    nginx.ingress.kubernetes.io/rewrite-target: /
spec:
  rules:
  - host: grafana-{oid}.sylocloud.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: sylo-monitor-grafana
            port:
              number: 80
"""
    
    # Write to temp file
    import tempfile
    with tempfile.NamedTemporaryFile(mode='w', suffix='.yaml', delete=False) as f:
        f.write(ingress_yaml)
        temp_file = f.name
    
    try:
        # Apply ingress
        apply_cmd = f"minikube -p {profile} kubectl -- apply -f {temp_file}"
        run_command(apply_cmd, silent=True)
        log(f"   ✅ Ingress creado: grafana-{oid}.sylocloud.com", C_SUCCESS)
    finally:
        # Cleanup temp file
        os.remove(temp_file)

def uninstall_monitoring_stack(oid, profile):
    """
    Desinstala el stack de monitoring usando Helm.
    """
    namespace = profile
    
    try:
        log(f"   🗑️ Desinstalando Monitoring Stack...", C_WARNING)
        
        # 1. Delete Helm release
        uninstall_cmd = f"helm uninstall sylo-monitor --namespace {namespace}"
        run_command(uninstall_cmd, silent=True)
        
        # 2. Delete Ingress
        delete_ingress_cmd = f"minikube -p {profile} kubectl -- delete ingress grafana-ingress --namespace {namespace} --ignore-not-found"
        run_command(delete_ingress_cmd, silent=True)
        
        # 3. Update database
        delete_tool_record(oid, "monitoring")
        
        log(f"   ✅ Monitoring desinstalado correctamente", C_SUCCESS)
        
    except Exception as e:
        log(f"   ❌ Error desinstalando monitoring: {e}", C_DANGER)

def update_tool_status(oid, tool_name, status):
    """
    Actualiza el estado de una herramienta en la base de datos.
    """
    cmd = f"""docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "UPDATE k8s_tools SET status='{status}', updated_at=NOW() WHERE deployment_id={oid} AND tool_name='{tool_name}'" """
    run_command(cmd, silent=True)

def delete_tool_record(oid, tool_name):
    """
    Elimina el registro de una herramienta de la base de datos.
    """
    cmd = f"""docker exec -i kylo-main-db mysql -usylo_app -psylo_app_pass -D sylo_admin_db -e "DELETE FROM k8s_tools WHERE deployment_id={oid} AND tool_name='{tool_name}'" """
    run_command(cmd, silent=True)

# ============= TASK QUEUE INTEGRATION =============
# Add this to the process_task_queue function in operator_sylo.py

def process_task_queue():
    """
    Main task processing loop - ADD TOOL INSTALLATION HANDLING
    """
    while True:
        try:
            # ... existing code ...
            
            # NEW: Handle tool installation requests
            for f in glob.glob(os.path.join(BUZON, "accion_install_tool_*.json")):
                try:
                    with open(f, 'r') as file:
                        data = json.load(file)
                    
                    oid = data.get('deployment_id')
                    tool = data.get('tool')
                    
                    if not oid or not tool:
                        log(f"⚠️ Invalid tool install request: {f}", C_WARNING)
                        os.remove(f)
                        continue
                    
                    log(f"📥 Nueva solicitud de instalación: {tool} para deployment {oid}", C_ACCENT_BLUE)
                    
                    # Execute installation
                    handle_install_tool(oid, tool, data)
                    
                    # Mark as processed
                    os.rename(f, f + ".procesado")
                    
                except Exception as e:
                    log(f"❌ Error procesando {f}: {e}", C_DANGER)
                    try:
                        os.rename(f, f + ".error")
                    except:
                        pass
            
            # NEW: Handle tool uninstallation requests
            for f in glob.glob(os.path.join(BUZON, "accion_uninstall_tool_*.json")):
                try:
                    with open(f, 'r') as file:
                        data = json.load(file)
                    
                    oid = data.get('deployment_id')
                    tool = data.get('tool')
                    
                    if not oid or not tool:
                        os.remove(f)
                        continue
                    
                    log(f"📥 Solicitud de desinstalación: {tool} para deployment {oid}", C_WARNING)
                    
                    profile = f"sylo-cliente-{oid}"
                    
                    if tool == "monitoring":
                        uninstall_monitoring_stack(oid, profile)
                    
                    os.rename(f, f + ".procesado")
                    
                except Exception as e:
                    log(f"❌ Error desinstalando: {e}", C_DANGER)
            
            time.sleep(2)
            
        except Exception as e:
            log(f"❌ Error en task queue: {e}", C_DANGER)
            time.sleep(5)
