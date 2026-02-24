<?php
/**
 * admin_vault.php — Overseer Command Center
 */

declare(strict_types=1);
session_start();

require_once __DIR__ . '/kylo_db.php';

// 1. Control de Acceso Absoluto (El Arquitecto)
// Verifica que la variable $_SESSION['discord_id'] exista y sea exactamente igual a '646596460439142464'.
$ADMIN_DISCORD_ID = '646596460439142464';

if (!isset($_SESSION['discord_id']) || $_SESSION['discord_id'] !== $ADMIN_DISCORD_ID) {
    session_destroy(); // Destruye sesión si no es el Arquitecto
    http_response_code(403);
    die('
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>403 - ACCESS DENIED</title><style>body{background:#0a0a0a;color:#ff0000;font-family:monospace;text-align:center;padding-top:20%;}</style></head>
    <body><h1>403</h1><p>ACCESS DENIED · UNAUTHORIZED ENTITY</p></body>
    </html>');
}

$pdo = KyloDB::getConnection();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_ropro_link'])) {
    $new_link = trim($_POST['new_ropro_link']);

    // UUID Generator matching KyloDB capabilities (simulating MySQL UUID())
    $vault_uuid = KyloDB::generarUUIDv4();

    // Fallback: Delete instead of TRUNCATE TABLE to ensure compatibility with KyloDB limited parser
    try { $pdo->exec("DELETE FROM kylo_core.safe_vault WHERE id = 1"); } catch(Exception $e) {}

    // 3. Lógica de Base de Datos (Kylo Core)
    $stmt = $pdo->prepare("INSERT INTO kylo_core.safe_vault (id, vault_uuid, ropro_link) VALUES (1, :uuid, :link)");
    $stmt->execute([':uuid' => $vault_uuid, ':link' => $new_link]);

    // Register operator event in security_logs using the central class
    KyloDB::insertarTelemetria([
        'event_uuid' => KyloDB::generarUUIDv4(),
        'discord_id' => $ADMIN_DISCORD_ID,
        'username'   => 'OPERATOR',
        'event_type' => 'VAULT_UPDATE',
        'details'    => json_encode(['action' => 'Operator manually injected new payload link'])
    ]);

    $message = '<div class="blinking-success">[ SUCCESS: VAULT PAYLOAD UPDATED ]</div>';
}

// Fetch current
$stmt = $pdo->query("SELECT ropro_link FROM kylo_core.safe_vault WHERE id = 1");
$current_data = $stmt->fetch();
$current_link = $current_data['ropro_link'] ?? 'NONE';
$updated_at = $current_data['updated_at'] ?? 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overseer Command Center</title>
    <style>
        body { 
            background: #0a0a0a; 
            color: #00ff00; 
            font-family: monospace; 
            margin: 0; 
            padding: 40px; 
            display: flex;
            justify-content: center;
        }
        .container { 
            width: 100%;
            max-width: 800px; 
            border: 1px solid #00ff00; 
            padding: 30px; 
            box-shadow: 0 0 15px rgba(0, 255, 0, 0.2); 
            background: #000;
        }
        h2 { 
            text-align: center;
            border-bottom: 2px solid #00ff00; 
            padding-bottom: 15px; 
            text-transform: uppercase; 
            letter-spacing: 2px;
            margin-top: 0;
        }
        .blinking-success {
            color: #00ff00;
            text-align: center;
            font-weight: bold;
            font-size: 1.2rem;
            margin: 20px 0;
            animation: blink 1s infinite steps(2, end);
        }
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0; }
        }
        .data-panel { 
            margin-bottom: 30px; 
            padding: 15px;
            border: 1px dashed #00ff00;
        }
        input[type="text"] { 
            width: 100%; 
            padding: 15px; 
            background: #0a0a0a; 
            color: #00ff00; 
            border: 1px solid #00ff00; 
            box-sizing: border-box; 
            margin: 15px 0; 
            font-family: monospace; 
            font-size: 1rem;
        }
        button[type="submit"] { 
            width: 100%;
            background: transparent; 
            color: #00ff00; 
            border: 2px solid #00ff00; 
            padding: 15px; 
            font-size: 1.2rem; 
            font-weight: bold; 
            cursor: pointer; 
            font-family: monospace; 
            text-transform: uppercase; 
            transition: 0.2s;
        }
        button[type="submit"]:hover { 
            background: #00ff00; 
            color: #0a0a0a; 
            box-shadow: 0 0 20px #00ff00; 
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>KYLO CORE: THE VAULT</h2>
        
        <?php echo $message; ?>

        <div class="data-panel">
            <p><strong>CURRENT SECURE LINK:</strong> <br><?php echo htmlspecialchars((string)$current_link); ?></p>
            <p><strong>LAST UPDATED:</strong> <br><?php echo htmlspecialchars((string)$updated_at); ?></p>
        </div>

        <form method="POST">
            <input type="text" name="new_ropro_link" placeholder="ENTER RAW ROPRO LINK HERE..." required>
            <button type="submit">INJECT SECURE PAYLOAD</button>
        </form>
    </div>
</body>
</html>
