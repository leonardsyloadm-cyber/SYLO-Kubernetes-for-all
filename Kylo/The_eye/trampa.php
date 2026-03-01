<?php
/**
 * trampa.php — El Cebo In-copiable
 * Pantalla de despliegue final · Seguridad Ofensiva JS · Deep Linking nativo
 *
 * All-Seeing Eye · Proyecto Seraphs
 */

declare(strict_types=1);
session_start();

// ═════════════════════════════════════════════════════════════
//  PROTECCIÓN DE ACCESO: SOLO SERAPHS VERIFICADOS
// ═════════════════════════════════════════════════════════════
if (!isset($_SESSION['seraph_auth']) || $_SESSION['seraph_auth'] !== true) {
    header('Location: index.php');
    exit;
}

// ═════════════════════════════════════════════════════════════
//  DIRECCIÓN DE DESPLIEGUE — SERVIDOR DIARIO DE ROBLOX
// ═════════════════════════════════════════════════════════════
// El formato de la URL de Roblox Deep Link es:
// roblox-player:1+launchmode:play+gameinfo:TU_JOB_ID_AQUI+launchtime:123
$roblox_job_id = 'TICKET_VIP_DEL_DIA_FISTBORN'; // ← CAMBIA ESTO CADA 24H

$discord_username = htmlspecialchars($_SESSION['discord_tag'] ?? 'Unknown Seraph', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despliegue | All-Seeing Eye</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <style>

        /* ─── RESET Y SEGURIDAD OFENSIVA (CSS) ─── */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            /* BLOQUEO DE SELECCIÓN DE TEXTO */
            user-select: none !important;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
        }

        /* Ocultar scrollbar */
        ::-webkit-scrollbar { display: none; }

        :root {
            --red-core:    #ff0000;
            --red-dim:     #880000;
            --dark-bg:     #030303;
            --text-bright: #eeeeee;
        }

        body {
            background-color: var(--dark-bg);
            color: var(--text-bright);
            font-family: 'Share Tech Mono', monospace;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: crosshair;
        }

        /* ─── BACKGROUND FX ─── */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at center, transparent 20%, #000 90%),
                repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(255,0,0,0.05) 2px, rgba(255,0,0,0.05) 4px);
            pointer-events: none;
            z-index: 1;
        }

        /* ─── CONTENEDOR PRINCIPAL ─── */
        .hub {
            position: relative;
            z-index: 10;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2rem;
            padding: 3rem;
            border: 1px solid rgba(255,0,0,0.2);
            background: rgba(10,0,0,0.6);
            box-shadow: 0 0 50px rgba(255,0,0,0.1) inset;
            backdrop-filter: blur(5px);
        }

        /* ESQUINEROS ESTILO HUD */
        .corner { position: absolute; width: 20px; height: 20px; border: 2px solid transparent; }
        .c-tl { top: -1px; left: -1px; border-top-color: var(--red-core); border-left-color: var(--red-core); }
        .c-tr { top: -1px; right: -1px; border-top-color: var(--red-core); border-right-color: var(--red-core); }
        .c-bl { bottom: -1px; left: -1px; border-bottom-color: var(--red-core); border-left-color: var(--red-core); }
        .c-br { bottom: -1px; right: -1px; border-bottom-color: var(--red-core); border-right-color: var(--red-core); }

        /* ─── TEXTOS ─── */
        .title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.5rem;
            letter-spacing: 0.2em;
            color: var(--red-core);
            text-transform: uppercase;
            text-shadow: 0 0 10px rgba(255,0,0,0.5);
            margin-bottom: 1rem;
        }

        .identity {
            font-size: 1.2rem;
            color: #fff;
            margin-bottom: 0.5rem;
        }
        .identity span { color: var(--red-core); font-weight: bold; }

        .monitor-alert {
            font-size: 0.8rem;
            color: var(--red-dim);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            animation: pulseText 2s infinite;
        }

        @keyframes pulseText {
            0%, 100% { opacity: 0.5; }
            50%      { opacity: 1; }
        }

        /* ─── EL BOTÓN MAESTRO ─── */
        .btn-deploy {
            position: relative;
            padding: 1.2rem 3rem;
            background: transparent;
            border: 1px solid var(--red-core);
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            font-weight: 900;
            font-size: 1.2rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            cursor: pointer;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 0 15px rgba(255,0,0,0.2) inset;
        }

        .btn-deploy::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,0,0,0.4), transparent);
            transition: left 0.5s;
        }

        .btn-deploy:hover {
            background: rgba(255,0,0,0.1);
            box-shadow: 0 0 30px rgba(255,0,0,0.4) inset, 0 0 20px rgba(255,0,0,0.3);
            text-shadow: 0 0 8px #fff;
        }

        .btn-deploy:hover::before { left: 100%; }

        .btn-deploy.deployed {
            border-color: #00ff00;
            color: #00ff00;
            box-shadow: 0 0 15px rgba(0,255,0,0.2) inset;
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

        <h1 class="title">SYSTEM ONLINE</h1>

        <div class="identity">
            Identity Confirmed: <span><?= $discord_username ?></span>
        </div>

        <div class="monitor-alert">
            [◉] YOUR ACTIVITY IS BEING MONITORED IN REAL-TIME
        </div>

        <!-- EL CEBO: Oculta la URL real, el href real se genera en JS -->
        <button id="deployBtn" class="btn-deploy"
                data-target="<?= htmlspecialchars($roblox_job_id, ENT_QUOTES, 'UTF-8') ?>">
            [ DEPLOY ON FISTBORN ]
        </button>
    </div>

    <!-- ═════════════════════════════════════════════════════════════ -->
    <!--  SEGURIDAD OFENSIVA JS + EJECUCIÓN DEL DEEP LINK              -->
    <!-- ═════════════════════════════════════════════════════════════ -->
    <script>
        // 1. BLOQUEO TOTAL DE CLIC DERECHO
        document.addEventListener('contextmenu', event => {
            event.preventDefault();
            console.log("%c[!] INTRUSION DETECTED. INCIDENT REPORTED TO KYLO.", "color: red; font-size: 16px; font-weight: bold;");
        });

        // 2. DETECCIÓN DE HERRAMIENTAS DE DESARROLLO (F12, Ctrl+Shift+I)
        document.addEventListener('keydown', (e) => {
            // F12
            if (e.key === 'F12') {
                e.preventDefault();
                corromperConsola();
            }
            // Ctrl + Shift + I  (Inspector)
            if (e.ctrlKey && e.shiftKey && e.key === 'I') {
                e.preventDefault();
                corromperConsola();
            }
            // Ctrl + Shift + J  (Consola)
            if (e.ctrlKey && e.shiftKey && e.key === 'J') {
                e.preventDefault();
                corromperConsola();
            }
            // Ctrl + U  (Ver código fuente)
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
            }
        });

        // Acción hostil si abren la consola: Spam infinito para colapsar las DevTools
        function corromperConsola() {
            setInterval(() => {
                console.clear();
                console.log("%cKYV SEES EVERYTHING", "color: red; font-size: 50px; text-shadow: 0 0 20px red;");
                debugger; // Frena la ejecución si tienen las DevTools abiertas
            }, 50);
        }

        // Detectar si DevTools ya están abiertas vía diferencia de tamaño de ventana (heurística)
        const element = new Image();
        Object.defineProperty(element, 'id', {
          get: function() {
            corromperConsola();
            window.location.href = "about:blank"; // Expulsar al usuario
          }
        });
        console.log('%c', element);

        // 3. EL LANZADOR SILENCIOSO (DEEP LINK)
        const btn = document.getElementById('deployBtn');

        btn.addEventListener('click', (e) => {
            if (btn.classList.contains('deployed')) return;

            // Extraer el secreto inyectado desde PHP
            const jobId = btn.getAttribute('data-target');
            const launchTime = Date.now();

            // Construir el enlace en memoria (nunca visible en el HTML estático)
            const deepLink = `roblox-player:1+launchmode:play+gameinfo:${jobId}+launchtime:${launchTime}`;

            // Cambiar UI inmediatamente
            btn.classList.add('deployed');
            btn.innerHTML = '[ DEPLOYING... ]<br><span style="font-size:0.6rem; color:#fff;">CHECK YOUR ROBLOX APPLICATION</span>';

            // Ejecutar redirección nativa al cliente de Roblox del SO
            // Usamos un iframe invisible para no destruir la página actual
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = deepLink;
            document.body.appendChild(iframe);

            // Cleanup opcional del iframe
            setTimeout(() => { document.body.removeChild(iframe); }, 2000);
        });

    </script>
</body>
</html>
