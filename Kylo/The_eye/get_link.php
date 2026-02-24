<?php
/**
 * get_link.php — The Master Launcher
 * Secure transition point. Verifies Seraph identity, logs the request,
 * and provides the interface to deploy the server payload.
 */

declare(strict_types=1);
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/kylo_db.php';

// 1. Strict Session Verification
if (empty($_SESSION['discord_id']) || empty($_SESSION['seraph_auth']) || empty($_SESSION['event_uuid'])) {
    http_response_code(403);
    die('
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>403 - FORBIDDEN</title><style>body{background:#000;color:#f00;font-family:monospace;text-align:center;padding-top:20%;}</style></head>
    <body><h1>403</h1><p>ACCESS DENIED · INVALID SESSION TOKEN</p></body>
    </html>');
}

$discord_id = $_SESSION['discord_id'];
$event_uuid = $_SESSION['event_uuid'];
$discord_user = htmlspecialchars($_SESSION['discord_tag'] ?? 'Unknown Operator', ENT_QUOTES, 'UTF-8');

try {
    // 2. Traceability logging using KyloDB class
    KyloDB::insertarTelemetria([
        'event_uuid' => $event_uuid,
        'discord_id' => $discord_id,
        'username'   => $_SESSION['discord_tag'] ?? 'UNKNOWN',
        'event_type' => 'LINK_REQUEST',
        'details'    => json_encode(['action' => 'User accessed the Launcher UI'])
    ]);

    // 3. Extract the target link
    $target_url = KyloDB::getSafeVaultLink();

    if (empty($target_url)) {
        die('
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>503 - VAULT EMPTY</title><style>body{background:#000;color:#fa0;font-family:monospace;text-align:center;padding-top:20%;}</style></head>
        <body><h1>503</h1><p>VAULT IS CURRENTLY EMPTY. CONTACT SYSTEM ADMINISTRATOR.</p></body>
        </html>');
    }

} catch (Exception $e) {
    die('500 - SYSTEM ERROR: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Launcher | All-Seeing Eye</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; user-select: none !important; }
        ::-webkit-scrollbar { display: none; }
        :root { --red-core: #ff0000; --red-dim: #880000; --dark-bg: #030303; --text-bright: #eeeeee; }
        body {
            background-color: var(--dark-bg);
            color: var(--text-bright);
            font-family: 'Share Tech Mono', monospace;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: crosshair;
        }
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at center, transparent 20%, #000 90%),
                        repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(255,0,0,0.05) 2px, rgba(255,0,0,0.05) 4px);
            pointer-events: none;
            z-index: 1;
        }
        .hub {
            position: relative;
            z-index: 10;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            padding: 4rem;
            border: 1px solid rgba(255,0,0,0.2);
            background: rgba(10,0,0,0.8);
            box-shadow: 0 0 50px rgba(255,0,0,0.2) inset;
            backdrop-filter: blur(10px);
            max-width: 600px;
            width: 90%;
        }
        .corner { position: absolute; width: 20px; height: 20px; border: 2px solid transparent; }
        .c-tl { top: -1px; left: -1px; border-top-color: var(--red-core); border-left-color: var(--red-core); }
        .c-tr { top: -1px; right: -1px; border-top-color: var(--red-core); border-right-color: var(--red-core); }
        .c-bl { bottom: -1px; left: -1px; border-bottom-color: var(--red-core); border-left-color: var(--red-core); }
        .c-br { bottom: -1px; right: -1px; border-bottom-color: var(--red-core); border-right-color: var(--red-core); }

        .title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.8rem;
            letter-spacing: 0.3em;
            color: var(--red-core);
            text-transform: uppercase;
            text-shadow: 0 0 15px rgba(255,0,0,0.7);
        }
        .identity { font-size: 1.2rem; color: #fff; }
        .identity span { color: var(--red-core); font-weight: bold; text-shadow: 0 0 5px red; }
        .monitor-alert { font-size: 0.8rem; color: var(--red-dim); text-transform: uppercase; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 1; } }

        .btn-deploy {
            position: relative;
            padding: 1.5rem 4rem;
            background: transparent;
            border: 2px solid var(--red-core);
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            font-weight: 900;
            font-size: 1.2rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 0 15px rgba(255,0,0,0.3) inset;
        }
        .btn-deploy:hover {
            background: rgba(255,0,0,0.2);
            box-shadow: 0 0 30px rgba(255,0,0,0.5) inset, 0 0 20px rgba(255,0,0,0.4);
            text-shadow: 0 0 10px #fff;
        }
        .btn-deploy.active {
            border-color: #0f0;
            color: #0f0;
            box-shadow: 0 0 15px rgba(0,255,0,0.3) inset;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="hub">
        <div class="corner c-tl"></div>
        <div class="corner c-tr"></div>
        <div class="corner c-bl"></div>
        <div class="corner c-br"></div>

        <h1 class="title">SYSTEM READY</h1>

        <div class="identity">
            Operator Identified: <span><?= $discord_user ?></span>
        </div>

        <div class="monitor-alert">
            [◉] SECURE TUNNEL ESTABLISHED · MONITORING ACTIVE
        </div>

        <button id="deployBtn" class="btn-deploy">
            [ DEPLOY PAYLOAD ]
        </button>
    </div>

    <script>
        const btn = document.getElementById('deployBtn');
        const target = '<?= $target_url ?>';

        btn.addEventListener('click', () => {
            btn.classList.add('active');
            btn.innerHTML = '[ INJECTING... ]';
            
            // Redirect to the RoPro link. 
            // This happens on click, so it's not automatic.
            // The user stays on this page until they choose to deploy.
            setTimeout(() => {
                // Use a silent iframe to trigger the RoPro redirect/protocol handler
                // without exposing the target URL in the main address bar.
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = target;
                document.body.appendChild(iframe);
                
                // Keep the button in a loading state for a bit longer
                setTimeout(() => {
                    btn.innerHTML = '[ PAYLOAD DEPLOYED ]';
                }, 2000);
            }, 800);
        });

        // Anti-inspect defense
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('keydown', e => {
            if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J')) || (e.ctrlKey && e.key === 'u')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
