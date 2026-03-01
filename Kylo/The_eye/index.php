<?php
// Barrera Cero — The All-Seeing Eye (Optimized 60 FPS Engine)
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// No automatic forwarding. The user will be presented with the "ENTER THE VAULT" button instead.

define('DISCORD_CLIENT_ID', '1475860717067571444');
define('DISCORD_REDIRECT_URI', 'http://localhost:8000/discord_auth.php');

$oauth_url = 'https://discord.com/oauth2/authorize'
    . '?client_id=' . DISCORD_CLIENT_ID
    . '&redirect_uri=' . urlencode(DISCORD_REDIRECT_URI)
    . '&response_type=code'
    . '&scope=identify+guilds.members.read';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KYV SEES EVERYTHING</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <style>

        /* ─── RESET Y TERROR BASE ─── */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            user-select: none;
        }

        :root {
            --red-core:    #ff1111;
            --glitch-cyan: #00ffff;
            --dark-bg:     #000;
        }

        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: var(--dark-bg);
            color: #fff;
            font-family: 'Share Tech Mono', monospace;
            cursor: none; /* Ocultar el cursor natural para desorientar */
        }

        /* ─── ENTORNO ORGÁNICO Y ESCANEO (60 FPS FIXED) ─── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,0,0,0.8) 2px, rgba(0,0,0,0.8) 4px);
            pointer-events: none;
            z-index: 2;
            will-change: transform;
            /* Usando transform en lugar de background-position para aceleración GPU */
            animation: scanTwitch 0.2s steps(2) infinite;
        }

        @keyframes scanTwitch {
            0%   { transform: translate3d(0, 0, 0); opacity: 1; }
            50%  { transform: translate3d(0, 1px, 0); opacity: 0.8; }
            100% { transform: translate3d(0, 2px, 0); opacity: 1; }
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            /* Efecto rojo orgánico real sin recortes ni cuadrados */
            background: radial-gradient(circle at center, rgba(140,0,0,0.15) 0%, rgba(20,0,0,0.7) 50%, #000 100%);
            pointer-events: none;
            z-index: 1;
        }

        /* ─── CONTENEDOR PRINCIPAL Y LAYOUT LIMPIO ─── */
        .container {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            gap: 2.5rem; /* Separación fija y limpia */
            transition: transform 0.1s; 
            padding: 2rem;
        }

        .container.idle-zoom {
            transform: scale3d(1.3, 1.3, 1);
            transition: transform 20s cubic-bezier(0.1, 0, 1, 1); /* Zoom muy lento al acecho */
        }

        /* ─── EL OJO SVG PÚRO (TAMAÑO LIMITADO) ─── */
        #eye-container {
            position: relative;
            /* Imponente pero limitado para no devorar recursos de pintado */
            width: clamp(250px, 45vh, 400px); 
            height: clamp(250px, 45vh, 400px);
            display: flex;
            align-items: center;
            justify-content: center;
            animation: starJitter 0.05s infinite; /* Jitter acelerado por hardware */
            transform-origin: center center;
            overflow: visible; /* Brillo sin recortes */
            flex-shrink: 0; /* Evita que se encoja layout */
            will-change: transform;
        }

        .svg-layer {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
            overflow: visible;
        }

        /* --- MARCO FIJO (Estrella y Decoraciones) --- */
        #layer-fixed {
            z-index: 1;
            /* Filtro brillante, estático (no se redibuja en animación) */
            filter: drop-shadow(0 0 15px var(--red-core)) brightness(1.3);
            will-change: transform;
        }

        .star-path {
            fill: none;
            stroke: var(--red-core);
            /* Más fino que antes pero dominante por el brillo y drop-shadow estático */
            stroke-width: 4; 
            stroke-dasharray: 15 30 150 20 8 40;
            /* Animación SVG interna = Barata */
            animation: electricalArc 2s linear infinite;
        }

        @keyframes electricalArc {
            0% { stroke-dashoffset: 0; }
            100% { stroke-dashoffset: 263; }
        }

        @keyframes starJitter {
            0%   { transform: translate3d(0,0,0) rotate(0deg); }
            25%  { transform: translate3d(1px,-1px,0) rotate(0.5deg); }
            50%  { transform: translate3d(-1px,1px,0) rotate(-0.5deg); }
            75%  { transform: translate3d(1px,1px,0) rotate(0deg); }
            100% { transform: translate3d(-1px,-1px,0) rotate(0deg); }
        }

        /* --- NÚCLEO MÓVIL (Anillos y Pupila) --- */
        #layer-moving-wrapper {
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 2;
            /* Ponemos el filtro estático en el padre inmóvil para no sufrir lag en el hijo móvil */
            filter: drop-shadow(0 0 12px var(--red-core)) brightness(1.2);
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #layer-moving {
            width: 50%;
            height: 50%;
            /* El JS aplicará translate aquí. GPU acceleration on. */
            will-change: transform;
        }

        .ring {
            fill: none;
            stroke: var(--red-core);
            stroke-width: 2.5;
            transform-origin: center;
        }

        .ring.inner {
            stroke-dasharray: 4 4;
            animation: spinInner 2s linear infinite reverse;
        }

        .ring.outer {
            stroke-dasharray: 10 15;
            animation: spinOuter 6s linear infinite;
        }

        @keyframes spinInner { 100% { transform: rotate(-360deg); } }
        @keyframes spinOuter { 100% { transform: rotate(360deg); } }

        /* Pupila 'X' Glitcheada - Con más 'aire' interior */
        .pupil-x {
            fill: var(--red-core);
            transform-origin: center;
            will-change: transform, opacity;
            /* Exclusivamente opacity y transform */
            animation: pupilXGlitch 0.1s steps(2, end) infinite;
        }

        @keyframes pupilXGlitch {
            0% { transform: scale3d(1, 1, 1) skew(0deg); opacity: 1; }
            20% { transform: scale3d(1.1, 0.9, 1) skew(20deg, -5deg); opacity: 0.7; }
            40% { transform: scale3d(0.9, 1.1, 1) skew(-15deg, 15deg); opacity: 1; fill: #fff; }
            60% { transform: scale3d(1.3, 0.6, 1) skew(35deg, 0deg); opacity: 0.3; }
            80% { transform: scale3d(0.9, 1.1, 1) skew(-10deg, -10deg); opacity: 1; fill: var(--red-core); }
            100% { transform: scale3d(1, 1, 1) skew(0deg); opacity: 0.9; }
        }

        /* ABERRACIÓN CROMÁTICA - Clase temporal (No genera lag constante) */
        #eye-container.rgb-split #layer-fixed,
        #eye-container.rgb-split #layer-moving-wrapper {
            filter: drop-shadow(-10px 0 rgba(255,0,0,0.9)) drop-shadow(10px 0 rgba(0,255,255,0.9));
        }

        /* ─── TEXTOS Y LAYOUT LIMPIO ─── */
        .info-panel {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            z-index: 10;
            text-align: center;
            flex-shrink: 0;
        }

        .headline {
            font-family: 'Orbitron', sans-serif;
            font-size: clamp(1.8rem, 5vw, 3.5rem);
            color: #fff;
            text-transform: uppercase;
            text-shadow:
                4px 0 var(--red-core),
                -4px 0 var(--glitch-cyan);
            animation: textPain 0.1s infinite;
            letter-spacing: -2px;
            margin: 0;
        }

        @keyframes textPain {
            0% { transform: translate3d(0,0,0) scale3d(1,1,1); text-shadow: 4px 0 red, -4px 0 cyan; }
            33% { transform: translate3d(-2px, 1px, 0) scale3d(1.01,1.01,1); text-shadow: 6px 0 red, -2px 0 cyan; }
            66% { transform: translate3d(2px, -1px, 0) scale3d(0.99,0.99,1); text-shadow: -2px 0 red, 5px 0 cyan; }
            100% { transform: translate3d(0,0,0) scale3d(1,1,1); text-shadow: 4px 0 red, -4px 0 cyan; }
        }

        .btn-discord {
            position: relative;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 3rem;
            background: #000;
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            font-size: 1.2rem;
            font-weight: 900;
            border: 2px solid var(--red-core);
            text-decoration: none;
            text-transform: uppercase;
            box-shadow: 0 0 20px rgba(255,0,0,0.5) inset, 0 0 10px rgba(255,0,0,0.5);
            transition: none; /* Sin suavidad */
        }

        .btn-discord:hover {
            background: var(--red-core);
            color: #000;
            box-shadow: 0 0 50px rgba(255,0,0,1);
            transform: scale3d(1.05, 1.05, 1) skewX(-5deg);
        }

        .btn-discord svg { width: 24px; height: 24px; flex-shrink: 0; }

        .warning {
            margin: 0;
            font-size: 0.75rem;
            color: #800;
            text-align: center;
            animation: warnFlash 3s infinite;
        }

        @keyframes warnFlash {
            0%, 90% { opacity: 0.5; }
            95% { opacity: 1; color: var(--red-core); text-shadow: 0 0 10px red; }
            100% { opacity: 0.5; }
        }

        /* ─── MENSAJES SUBLIMINALES ─── */
        #subliminal {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Orbitron', sans-serif;
            font-size: 15vw;
            font-weight: 900;
            color: var(--red-core);
            mix-blend-mode: color-dodge;
            opacity: 0;
            pointer-events: none;
            z-index: 100;
            text-transform: uppercase;
            text-align: center;
            will-change: transform, opacity;
        }

        /* ─── CURSOR HOSTIL ─── */
        .drone-cursor {
            position: fixed;
            width: 10px;
            height: 10px;
            background: var(--red-core);
            border-radius: 50%;
            pointer-events: none;
            z-index: 9999;
            box-shadow: 0 0 10px red;
            mix-blend-mode: difference;
            transform: translate(-50%, -50%);
            will-change: left, top;
        }

    </style>
</head>
<body>

    <div class="drone-cursor" id="dCursor"></div>

    <div class="container" id="mainContainer">

        <!-- EL SÁNDWICH SVG (TAMAÑO CONTROLADO Y ACELERADO POR HARDWARE) -->
        <div id="eye-container">
            
            <!-- CAPA 1: MARCO FIJO (Estrella y Guías) -->
            <svg class="svg-layer" id="layer-fixed" viewBox="0 0 300 300" xmlns="http://www.w3.org/2000/svg">
                <!-- Estrella de 4 puntas -->
                <path class="star-path" d="M150,10 L165,120 L290,150 L165,180 L150,290 L135,180 L10,150 L135,120 Z" />
                <!-- Líneas conectoras robustas -->
                <line x1="150" y1="50" x2="150" y2="250" stroke="rgba(255,0,0,0.5)" stroke-width="2" />
                <line x1="50" y1="150" x2="250" y2="150" stroke="rgba(255,0,0,0.5)" stroke-width="2" />
            </svg>

            <!-- CAPA 2: NÚCLEO MÓVIL (Anillos y Pupila) SEPARADO DEL DROP-SHADOW -->
            <div id="layer-moving-wrapper">
                <div id="layer-moving">
                    <svg viewBox="0 0 150 150" xmlns="http://www.w3.org/2000/svg" style="width: 100%; height: 100%;">
                        <!-- Anillos giratorios -->
                        <circle class="ring outer" cx="75" cy="75" r="50" />
                        <circle class="ring inner" cx="75" cy="75" r="35" />
                        <circle cx="75" cy="75" r="15" fill="none" stroke="red" stroke-width="2" opacity="0.4" />
                        
                        <!-- Pupila X - Aún más "aire" = 10x45 (antes 15x60) para un núcleo concentrado -->
                        <g class="pupil-x">
                            <rect x="70" y="52.5" width="10" height="45" rx="3" transform="rotate(45 75 75)" />
                            <rect x="70" y="52.5" width="10" height="45" rx="3" transform="rotate(-45 75 75)" />
                        </g>
                    </svg>
                </div>
            </div>

        </div>

        <!-- INFO PANEL: DISEÑO LIMPIO Y BLOQUEADO -->
        <div class="info-panel">
            <h1 class="headline">KYV SEES EVERYTHING</h1>

            <?php if (isset($_SESSION['seraph_verified']) && $_SESSION['seraph_verified'] === true && !empty($_SESSION['event_uuid'])): ?>
                <a href="get_link.php" class="btn-discord" id="loginBtn" style="border-color: #0f0; color: #0f0; box-shadow: 0 0 20px rgba(0,255,0,0.5) inset, 0 0 10px rgba(0,255,0,0.5);">
                    ENTER THE VAULT
                </a>
            <?php else: ?>
                <a href="https://discord.com/oauth2/authorize?client_id=1475860717067571444&response_type=code&redirect_uri=http%3A%2F%2Flocalhost%3A8000%2Fdiscord_auth.php&scope=identify+guilds.members.read" class="btn-discord" id="loginBtn">
                    <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.03.056a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                    </svg>
                    SUBMISSION PROTOCOL
                </a>
            <?php endif; ?>

            <p class="warning">
                [ ANY ATTEMPT TO FLEE WILL BE MET WITH FORCE ]<br>
                IP CAPTURED. SYSTEM PROFILED.
            </p>
        </div>

    </div>

    <!-- Contenedor Subliminal -->
    <div id="subliminal"></div>

    <script>
        document.addEventListener('contextmenu', e => e.preventDefault());

        // CUSTOM CURSOR
        const dCursor = document.getElementById('dCursor');
        document.addEventListener('mousemove', e => {
            dCursor.style.left = e.clientX + 'px';
            dCursor.style.top = e.clientY + 'px';
        });

        const layerMoving = document.getElementById('layer-moving');
        const eyeContainer = document.getElementById('eye-container');
        const mainContainer = document.getElementById('mainContainer');
        const subliminal = document.getElementById('subliminal');

        let targetX = 0, targetY = 0;
        let currentX = 0, currentY = 0;
        let isParanoiaBreak = false;
        let idleTimer = null;

        // HOSTILE MOUSE TRACKING (SNAP & LAG)
        document.addEventListener('mousemove', (e) => {
            resetIdle();
            if (isParanoiaBreak) return;

            // Calcular el centro de la pantalla
            const centerX = window.innerWidth / 2;
            const centerY = window.innerHeight / 2;

            // Distancia del ratón al centro
            const deltaX = e.clientX - centerX;
            const deltaY = e.clientY - centerY;
            
            // Límite de movimiento de la pupila central: MAX_RADIUS ajustable
            const MAX_RADIUS = window.innerWidth > 800 ? 50 : 35; 
            
            const distance = Math.hypot(deltaX, deltaY);
            let moveRatio = distance > 0 ? MAX_RADIUS / distance : 0;
            
            // Si el ratón está cerca, acercar la pupila; si lejos, se atasca en el borde
            moveRatio = Math.min(moveRatio, distance / 15); 

            targetX = deltaX * moveRatio;
            targetY = deltaY * moveRatio;
        });

        // Loop principal - Frecuencia asegurada para la GPU (usando translate3d)
        setInterval(() => {
            if (isParanoiaBreak) return;

            const diffX = targetX - currentX;
            const diffY = targetY - currentY;

            // Snap brusco con aberración pseudo-eléctrica
            if (Math.abs(diffX) > 4 || Math.abs(diffY) > 4) {
                currentX = targetX;
                currentY = targetY;
                triggerRGBParallax();
            } else {
                // Jitter latente
                currentX = targetX + (Math.random() * 5 - 2.5);
                currentY = targetY + (Math.random() * 5 - 2.5);
            }

            // Aplicamos translate3d a "#layer-moving" que ya tiene will-change (Fluido total)
            layerMoving.style.transform = `translate3d(${currentX}px, ${currentY}px, 0)`;
        }, 50);

        // RGB SPLIT TEMPORAL (Evita cálculos de filter constantes)
        function triggerRGBParallax() {
            eyeContainer.classList.add('rgb-split');
            setTimeout(() => {
                eyeContainer.classList.remove('rgb-split');
            }, 60);
        }

        // DISTRACCIÓN PARANOICA
        setInterval(() => {
            isParanoiaBreak = true;
            const rx = (Math.random() > 0.5 ? 50 : -50);
            const ry = (Math.random() > 0.5 ? 50 : -50);
            layerMoving.style.transform = `translate3d(${rx}px, ${ry}px, 0)`;
            triggerRGBParallax();

            setTimeout(() => {
                isParanoiaBreak = false;
            }, 250); 
        }, 3000 + Math.random() * 2000);

        // IDLE THREAT ZOOM
        function resetIdle() {
            mainContainer.classList.remove('idle-zoom');
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                // Amenaza lenta
                mainContainer.classList.add('idle-zoom');
            }, 3000);
        }
        resetIdle();

        // MENSAJES SUBLIMINALES
        const subliminalMessages = ["TRAITOR", "I SEE YOU", "CORRUPT", "LIAR", "DON'T RUN", "ERROR"];
        setInterval(() => {
            subliminal.textContent = subliminalMessages[Math.floor(Math.random() * subliminalMessages.length)];
            subliminal.style.opacity = '1';
            subliminal.style.transform = `scale3d(${Math.random() * 0.5 + 0.8}, ${Math.random() * 0.5 + 0.8}, 1) rotate(${Math.random() * 10 - 5}deg)`;

            setTimeout(() => {
                subliminal.style.opacity = '0';
            }, 30); 
        }, 7000 + Math.random() * 8000);
    </script>
</body>
</html>
