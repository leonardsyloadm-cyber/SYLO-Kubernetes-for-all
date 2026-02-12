<?php
// Add this to data.php after existing action handlers

// ============= MONITORING INSTALLATION =============
if ($action === 'install_monitoring') {
    header('Content-Type: application/json');
    
    $deployment_id = filter_var($_POST['deployment_id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$deployment_id) {
        echo json_encode(['success' => false, 'error' => 'ID de deployment inválido']);
        exit;
    }
    
    try {
        // 1. Verify deployment exists and belongs to user
        $stmt = $pdo->prepare("
            SELECT d.id, d.subdomain, d.status 
            FROM k8s_deployments d 
            WHERE d.id = ? AND d.user_id = ?
        ");
        $stmt->execute([$deployment_id, $_SESSION['user_id']]);
        $deployment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deployment) {
            echo json_encode(['success' => false, 'error' => 'Deployment no encontrado']);
            exit;
        }
        
        if ($deployment['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => 'El deployment debe estar activo']);
            exit;
        }
        
        // 2. Check if monitoring already installed
        $stmt = $pdo->prepare("
            SELECT id FROM k8s_tools 
            WHERE deployment_id = ? AND tool_name = 'monitoring'
        ");
        $stmt->execute([$deployment_id]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Monitoring ya está instalado']);
            exit;
        }
        
        // 3. Generate Grafana password
        $grafana_password = bin2hex(random_bytes(8)); // 16 char password
        
        // 4. Insert into k8s_tools
        $stmt = $pdo->prepare("
            INSERT INTO k8s_tools (deployment_id, tool_name, config_json, created_at) 
            VALUES (?, 'monitoring', ?, NOW())
        ");
        $config = json_encode([
            'grafana_password' => $grafana_password,
            'grafana_url' => "http://grafana-{$deployment_id}.sylocloud.com",
            'retention_days' => 7
        ]);
        $stmt->execute([$deployment_id, $config]);
        
        // 5. Create buzón action file
        $buzon_path = __DIR__ . '/../../buzon-pedidos';
        if (!is_dir($buzon_path)) {
            mkdir($buzon_path, 0755, true);
        }
        
        $action_file = $buzon_path . "/accion_install_tool_{$deployment_id}.json";
        $action_data = [
            'action' => 'INSTALL_TOOL',
            'deployment_id' => $deployment_id,
            'tool' => 'monitoring',
            'subdomain' => $deployment['subdomain'],
            'grafana_password' => $grafana_password,
            'timestamp' => time()
        ];
        
        file_put_contents($action_file, json_encode($action_data, JSON_PRETTY_PRINT));
        
        echo json_encode([
            'success' => true,
            'message' => 'Instalación iniciada',
            'grafana_url' => "http://grafana-{$deployment_id}.sylocloud.com",
            'grafana_user' => 'admin',
            'grafana_password' => $grafana_password
        ]);
        
    } catch (Exception $e) {
        error_log("Error installing monitoring: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error del servidor']);
    }
    exit;
}

// ============= MONITORING UNINSTALLATION =============
if ($action === 'uninstall_monitoring') {
    header('Content-Type: application/json');
    
    $deployment_id = filter_var($_POST['deployment_id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$deployment_id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    try {
        // Verify ownership
        $stmt = $pdo->prepare("
            SELECT d.id FROM k8s_deployments d 
            WHERE d.id = ? AND d.user_id = ?
        ");
        $stmt->execute([$deployment_id, $_SESSION['user_id']]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'No autorizado']);
            exit;
        }
        
        // Delete from k8s_tools
        $stmt = $pdo->prepare("
            DELETE FROM k8s_tools 
            WHERE deployment_id = ? AND tool_name = 'monitoring'
        ");
        $stmt->execute([$deployment_id]);
        
        // Create uninstall action
        $buzon_path = __DIR__ . '/../../buzon-pedidos';
        $action_file = $buzon_path . "/accion_uninstall_tool_{$deployment_id}.json";
        $action_data = [
            'action' => 'UNINSTALL_TOOL',
            'deployment_id' => $deployment_id,
            'tool' => 'monitoring',
            'timestamp' => time()
        ];
        
        file_put_contents($action_file, json_encode($action_data, JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true, 'message' => 'Desinstalación completada']);
        
    } catch (Exception $e) {
        error_log("Error uninstalling monitoring: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error del servidor']);
    }
    exit;
}
?>
