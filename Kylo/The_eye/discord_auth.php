<?php
/**
 * discord_auth.php — El Cerebro Interceptor
 * Callback OAuth2 de Discord · Verificación de Rol · Enriquecimiento Bloxlink · Ingestión Kylo
 *
 * All-Seeing Eye · Proyecto Seraphs
 */

declare(strict_types=1);
session_start();
require_once __DIR__ . '/kylo_db.php';

// ═════════════════════════════════════════════════════════════
//  ZONA DE CONFIGURACIÓN — RELLENA ESTOS VALORES
// ═════════════════════════════════════════════════════════════

// ── Discord App ──
define('DISCORD_CLIENT_ID',     '1475860717067571444');
define('DISCORD_CLIENT_SECRET', 'ZtjZ9iv6OsKp84ynYW1m_FRoMtmPPPov');
define('DISCORD_REDIRECT_URI',  'http://localhost:8000/discord_auth.php');

// ── Servidor Seraphs ──
// USAMOS EL TARGET_GUILD_ID REQUERIDO PARA EL DATA DUMP ACTUAL
define('TARGET_GUILD_ID',       '1374707752877690910');      // ID inyectado para escaneo de roles
define('GUILD_ID',              TARGET_GUILD_ID);            // Alias global
define('SERAPH_ROLE_ID',        '1374713472121700383');      // ID verificado del rol Angel

// ── Bloxlink ──
define('BLOXLINK_API_KEY',      'TU_BLOXLINK_API_KEY_AQUI'); // Déjalo vacío si no tienes key pública
define('BLOXLINK_GUILD_ID',     GUILD_ID);                   // Generalmente el mismo Guild ID

// ── Destino Roblox (Job ID del día — cámbialo cada sesión) ──
define('SERVIDOR_DESTINO',      'Fistborn-JOB_ID_AQUI');

// ── Timeouts cURL (segundos) ──
define('CURL_TIMEOUT',          10);

// ═════════════════════════════════════════════════════════════
//  HELPER: cURL genérico
// ═════════════════════════════════════════════════════════════
function curlGet(string $url, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false, // BYPASS PARA DESARROLLO LOCAL
        CURLOPT_USERAGENT      => 'AllSeeingEye/1.0 Seraphs',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    // curl_close() REMOVIDO PARA EVITAR DEPRECATED WARNINGS

    if ($error) {
        throw new RuntimeException("cURL GET Error [{$url}]: {$error}");
    }

    return ['code' => $httpCode, 'body' => $response];
}

function curlPost(string $url, array $fields, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false, // BYPASS PARA DESARROLLO LOCAL
        CURLOPT_USERAGENT      => 'AllSeeingEye/1.0 Seraphs',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    // curl_close() REMOVIDO PARA EVITAR DEPRECATED WARNINGS

    if ($error) {
        throw new RuntimeException("cURL POST Error [{$url}]: {$error}");
    }

    return ['code' => $httpCode, 'body' => $response];
}

// ═════════════════════════════════════════════════════════════
//  HELPER: 403 Error Page
// ═════════════════════════════════════════════════════════════
function denyAccess(string $reason = '', $d_id = 0, $d_user = 'UNKNOWN'): never
{
    session_destroy();

    // Captura de telemetría LOGIN_DENIED
    $event_uuid = KyloDB::generarUUIDv4();
    $datos_rechazo = [
        'event_uuid' => $event_uuid,
        'discord_id' => $d_id,
        'username'   => $d_user,
        'event_type' => 'LOGIN_DENIED',
        'details'    => json_encode([
            'reason'     => $reason,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ])
    ];
    try {
        KyloDB::insertarTelemetria($datos_rechazo);
    } catch (PDOException $e) {
        error_log('[Kylo] Fallo al insertar telemetría de rechazo: ' . $e->getMessage());
    }

    http_response_code(403);
    $log = '[Auth] Access denied' . ($reason ? " — {$reason}" : '');
    error_log($log);
    die('
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>403 — Access Denied</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box;}
  body{background:#050505;color:#e00;font-family:monospace;display:flex;
       align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:1rem;}
  h1{font-size:3rem;text-shadow:0 0 20px #ff0000;}
  p{color:#555;font-size:.85rem;letter-spacing:.3em;}
  .debug { margin-top: 2rem; color: #0f0; font-size: 0.8rem; background: #000; padding: 10px; border: 1px solid #0f0; }
</style>
</head>
<body>
<h1>◉ 403</h1>
<p>ACCESS DENIED · KYV IS WATCHING YOU</p>
<div class="debug">SECURITY SYSTEM LOG: ' . htmlspecialchars($reason) . '</div>
</body>
</html>');
}

// ═════════════════════════════════════════════════════════════
//  VALIDACIÓN INICIAL DEL CALLBACK
// ═════════════════════════════════════════════════════════════
// Captura de telemetría pasiva (antes de cualquier decisión)
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

// Discord nos debe enviar un 'code', si no hay, es un acceso directo — rechazar
if (empty($_GET['code'])) {
    denyAccess('Missing OAuth2 code');
}

$oauth_code = htmlspecialchars(strip_tags($_GET['code']), ENT_QUOTES, 'UTF-8');

// ═════════════════════════════════════════════════════════════
//  FASE 1: INTERCAMBIO DE CODE → ACCESS TOKEN
// ═════════════════════════════════════════════════════════════
try {
    $tokenResponse = curlPost(
        'https://discord.com/api/oauth2/token',
        [
            'client_id'     => DISCORD_CLIENT_ID,
            'client_secret' => DISCORD_CLIENT_SECRET,
            'grant_type'    => 'authorization_code',
            'code'          => $oauth_code,
            'redirect_uri'  => DISCORD_REDIRECT_URI,
        ],
        ['Content-Type: application/x-www-form-urlencoded']
    );
} catch (RuntimeException $e) {
    error_log('[Auth] Failed to obtain token: ' . $e->getMessage());
    denyAccess($e->getMessage());
}

$tokenData = json_decode($tokenResponse['body'], true);

if ($tokenResponse['code'] !== 200 || empty($tokenData['access_token'])) {
    // ------------------------------------------------------------------------------------------------
    // [!] VOLCADO DE DATOS (DATA DUMP) ESTRICTO - ERROR DE TOKEN
    // Frena la ejecución del código exponiendo por qué Discord rechazó el código OAuth
    // ------------------------------------------------------------------------------------------------
    echo '<pre style="color: #00ff00; background: black; padding: 20px; font-family: monospace; font-size: 14px;">';
    echo "=== DISCORD API OAUTH2 TOKEN ERROR ===\n\n";
    echo "HTTP CODE: " . $tokenResponse['code'] . "\n\n";
    print_r($tokenData);
    echo "\n\n=== DATOS ENVIADOS ===\n";
    print_r([
        'client_id'     => DISCORD_CLIENT_ID,
        'client_secret' => substr(DISCORD_CLIENT_SECRET, 0, 5) . '...', // Solo mostrar parte del secreto
        'grant_type'    => 'authorization_code',
        'code'          => $oauth_code,
        'redirect_uri'  => DISCORD_REDIRECT_URI,
    ]);
    echo '</pre>';
    exit;
}

$accessToken = $tokenData['access_token'];
$authHeader  = ['Authorization: Bearer ' . $accessToken];

// ═════════════════════════════════════════════════════════════
//  FASE 2: OBTENER PERFIL DE DISCORD (/users/@me)
// ═════════════════════════════════════════════════════════════
try {
    $meResponse = curlGet('https://discord.com/api/users/@me', $authHeader);
} catch (RuntimeException $e) {
    error_log('[Auth] Failed to obtain profile: ' . $e->getMessage());
    denyAccess('cURL failed on /users/@me');
}

$meData = json_decode($meResponse['body'], true);

if ($meResponse['code'] !== 200 || empty($meData['id'])) {
    denyAccess('Discord profile not obtained');
}

$discord_id  = $meData['id'];                                            // BIGINT (string numérico)
$discord_tag = $meData['username'] . '#' . ($meData['discriminator'] ?? '0'); // "Usuario#1234"

// ═════════════════════════════════════════════════════════════
//  FASE 3: VERIFICAR ROL SERAPH EN EL GUILD
// ═════════════════════════════════════════════════════════════
// endpoint de member del guild (requiere scope guilds.members.read)
try {
    $memberResponse = curlGet(
        'https://discord.com/api/users/@me/guilds/' . TARGET_GUILD_ID . '/member',
        $authHeader
    );
} catch (RuntimeException $e) {
    error_log('[Auth] Failed to obtain member data: ' . $e->getMessage());
    denyAccess('cURL failed on guild member', $discord_id, $discord_tag);
}

$memberData = json_decode($memberResponse['body'], true);

// El usuario debe ser miembro del servidor y tener roles listados
if ($memberResponse['code'] !== 200 || empty($memberData['roles'])) {
    denyAccess("User {$discord_id} is not a Guild member or has no roles", $discord_id, $discord_tag);
}

$esSeraph = in_array(SERAPH_ROLE_ID, $memberData['roles'], true);

if ($esSeraph) {
    // ═════════════════════════════════════════════════════════════
    //  FASE 5: INGESTIÓN DE TELEMETRÍA EN KYLO (LOGIN_GRANTED)
    // ═════════════════════════════════════════════════════════════
    $event_uuid = KyloDB::generarUUIDv4();

    $datos_telemetria = [
        'event_uuid' => $event_uuid,
        'discord_id' => $discord_id,
        'username'   => $discord_tag,
        'event_type' => 'LOGIN_GRANTED',
        'details'    => json_encode([
            'user_agent' => $user_agent,
            'roles'      => $memberData['roles'] ?? []
        ])
    ];

    try {
        KyloDB::insertarTelemetria($datos_telemetria);
    } catch (PDOException $e) {
        error_log('[Kylo] Fallo al insertar telemetría: ' . $e->getMessage());
    }

    // ═════════════════════════════════════════════════════════════
    //  FASE 6: AUTORIZACIÓN DE SESIÓN → VAULT
    // ═════════════════════════════════════════════════════════════
    session_regenerate_id(true);
    
    $_SESSION['seraph_auth']       = true;
    $_SESSION['seraph_verified']   = true;
    $_SESSION['discord_id']        = $discord_id;
    $_SESSION['discord_tag']       = $discord_tag;
    $_SESSION['event_uuid']        = $event_uuid;

    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>ACCESS GRANTED</title>
        <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
        <style>
            body { 
                background: #000; 
                color: #0f0; 
                font-family: "Share Tech Mono", monospace; 
                display: flex; 
                flex-direction: column;
                justify-content: center; 
                align-items: center; 
                height: 100vh; 
                margin: 0; 
                text-align: center;
            }
            h1 { font-size: 4rem; text-shadow: 0 0 15px #0f0; margin-bottom: 2rem; }
            .btn-link {
                background: #0f0;
                color: #000;
                padding: 15px 30px;
                text-decoration: none;
                font-size: 1.5rem;
                font-weight: bold;
                border: 2px solid #0f0;
                transition: 0.3s;
                text-transform: uppercase;
                letter-spacing: 2px;
            }
            .btn-link:hover {
                background: #000;
                color: #0f0;
                box-shadow: 0 0 20px #0f0;
            }
            .operator { font-size: 1.2rem; opacity: 0.8; margin-bottom: 3rem; }
        </style>
    </head>
    <body>
        <h1>ACCESS GRANTED</h1>
        <div class="operator">WELCOME OPERATOR: ' . htmlspecialchars($discord_tag) . '</div>
        <a href="get_link.php" class="btn-link">GET SAFE SERVER LINK</a>
    </body>
    </html>';
    exit;
} else {
    denyAccess("User {$discord_id} does not have the Seraph role");
}

// ═════════════════════════════════════════════════════════════
//  FIN DEL SCRIPT
// ═════════════════════════════════════════════════════════════
// Todo el flujo de autorización y telemetría de éxito corre ahora antes del volcado de pantalla "ACCESS GRANTED".
