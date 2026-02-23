<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SYLO | Cloud Engineering</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;800;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        :root { --sylo-bg: #030712; --sylo-text: #f8fafc; --sylo-card: #111827; --sylo-accent: #8b5cf6; --input-bg: #1f2937; }
        [data-theme="dark"] { --sylo-bg: #020617; --sylo-text: #f1f5f9; --sylo-card: #0f172a; --sylo-accent: #6d28d9; --input-bg: #1e293b; }
        
        body { font-family: 'Montserrat', sans-serif; background-color: var(--sylo-bg); color: var(--sylo-text); transition: 0.3s; }
        .navbar { background: rgba(3, 7, 18, 0.75); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); border-bottom: 1px solid rgba(139, 92, 246, 0.2); }
        .navbar-brand { font-weight: 900 !important; letter-spacing: 1px; }
        .nav-link { color: #cbd5e1 !important; transition: color 0.3s; }
        .nav-link:hover { color: #8b5cf6 !important; }
        
        .info-card, .bg-white { background-color: var(--sylo-card) !important; color: var(--sylo-text); border: 1px solid rgba(255,255,255,0.05); }
        .text-muted { color: #94a3b8 !important; }
        .bg-light { background-color: #111827 !important; border-color: rgba(255,255,255,0.05); }
        .form-control, .form-select { background-color: var(--input-bg) !important; border-color: #374151 !important; color: #f8fafc !important; }
        .form-control:focus, .form-select:focus { background-color: #111827 !important; border-color: var(--sylo-accent) !important; color: #ffffff !important; box-shadow: 0 0 0 0.25rem rgba(139, 92, 246, 0.25) !important; }
        .form-control::placeholder, .form-select::placeholder { color: #64748b !important; opacity: 1 !important; }
        .form-control:focus::placeholder, .form-select:focus::placeholder { color: #94a3b8 !important; }
        .modal-content { background-color: var(--sylo-card); color: white; border: 1px solid rgba(139, 92, 246, 0.3); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
        
        .hero { padding: 140px 0 100px; background: linear-gradient(180deg, var(--sylo-bg) 0%, var(--sylo-card) 100%); }
        
        .calc-box { background: #1e293b; border-radius: 24px; padding: 40px; color: white; border: 1px solid #334155; }
        .price-display { font-size: 3.5rem; font-weight: 800; color: var(--sylo-accent); }
        
        input[type=range] { -webkit-appearance: none; width: 100%; background: transparent; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; height: 20px; width: 20px; border-radius: 50%; background: #3b82f6; cursor: pointer; margin-top: -8px; box-shadow: 0 0 10px #3b82f6; }
        input[type=range]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #475569; border-radius: 2px; }
        input[type=range]:focus::-webkit-slider-runnable-track { background: #3b82f6; }

        .card-stack-container { perspective: 1500px; height: 600px; cursor: pointer; position: relative; margin-bottom: 30px; }
        .card-face { width: 100%; height: 100%; position: relative; transform-style: preserve-3d; transition: transform 0.8s; border-radius: 24px; }
        .card-stack-container.active .card-face { transform: rotateY(180deg); }
        .face-front, .face-back { position: absolute; width: 100%; height: 100%; top:0; left:0; backface-visibility: hidden; border-radius: 24px; padding: 30px; display: flex; flex-direction: column; justify-content: space-between; background: var(--sylo-card); border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .face-front { z-index: 2; transform: rotateY(0deg); pointer-events: auto; }
        .face-back { z-index: 1; transform: rotateY(180deg); background: var(--sylo-bg); border-color: var(--sylo-accent); pointer-events: none; }
        .card-stack-container.active .face-front { pointer-events: none; }
        .card-stack-container.active .face-back { pointer-events: auto; }
        
        .bench-bar { height: 35px; border-radius: 8px; display: flex; align-items: center; padding: 0 15px; color: white; margin-bottom: 8px; transition: width 1s; }
        .b-sylo { background: linear-gradient(90deg, #2563eb, #3b82f6); }
        .b-aws { background: #64748b; }
        .success-box { background: black; color: white; border-radius: 8px; padding: 20px; font-family: 'JetBrains Mono', monospace; text-align: left; }
        .status-dot { width: 10px; height: 10px; background-color: #22c55e; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0% {box-shadow:0 0 0 0 rgba(34,197,94,0.7);} 70% {box-shadow:0 0 0 6px rgba(34,197,94,0);} 100% {box-shadow:0 0 0 0 rgba(34,197,94,0);} }
        
        .modal-content { border:none; border-radius: 16px; }
        .avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 15px; }
        .k8s-specs li { display: flex; justify-content: space-between; border-bottom: 1px dashed #cbd5e1; padding: 8px 0; font-size: 0.9rem; }
        
        /* SYLO TOOL CHIPS */
        .tool-opt { cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid #e2e8f0; background: #fff; padding: 6px 12px; border-radius: 8px; display: inline-flex; align-items: center; font-size: 0.85rem; font-weight: 600; color: #64748b; user-select: none; }
        .tool-opt:hover { transform: translateY(-1px); border-color: var(--sylo-accent); color: var(--sylo-accent); }
        .tool-opt input { display: none; } /* Hide checkbox */
        
        /* Active State with Animation */
        .tool-opt.active { background: var(--sylo-accent); color: white; border-color: var(--sylo-accent); box-shadow: 0 4px 6px rgba(37, 99, 235, 0.2); animation: syloBounce 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        
        .tool-opt.disabled { opacity: 0.4; pointer-events: none; background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; }
        .tool-opt i { margin-right: 6px; }

        @keyframes syloBounce {
            0% { transform: scale(1); }
            40% { transform: scale(0.92); }
            70% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .pass-wrapper { position: relative; }
        .eye-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #94a3b8; z-index: 10; }
        .eye-icon:hover { color: var(--sylo-accent); }
        /* Ensure Message Modal is always on top of Auth Modal */
        #messageModal { z-index: 1090 !important; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="index.php"><i class="fas fa-cube me-2"></i>SYLO</a>
            <div class="collapse navbar-collapse justify-content-center" id="mainNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="#servicios" data-i18n="corp.btn_services">Servicios</a></li>
                    <li class="nav-item"><a class="nav-link" href="#empresa" data-i18n="nav.company">Empresa</a></li>
                </ul>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link nav-link p-0" onclick="toggleTheme()"><i class="fas fa-moon fa-lg"></i></button>
                <script src="js/lang.js"></script>
                <div class="lang-selector">
                    <button class="lang-btn lang-toggle-btn">
                        <span class="current-lang-flag">🇪🇸</span> <span class="current-lang-label fw-bold">ES</span> <i class="fas fa-chevron-down small" style="font-size:0.7em"></i>
                    </button>
                    <div class="lang-dropdown">
                        <button class="lang-opt" onclick="SyloLang.setLanguage('es')"><span class="me-2">🇪🇸</span> Español</button>
                        <button class="lang-opt" onclick="SyloLang.setLanguage('en')"><span class="me-2">🇬🇧</span> English</button>
                        <button class="lang-opt" onclick="SyloLang.setLanguage('fr')"><span class="me-2">🇫🇷</span> Français</button>
                        <button class="lang-opt" onclick="SyloLang.setLanguage('de')"><span class="me-2">🇩🇪</span> Deutsch</button>
                    </div>
                </div>

                <div class="d-none d-md-flex align-items-center cursor-pointer" onclick="new bootstrap.Modal('#statusModal').show()">
                    <div class="status-dot me-2"></div><span class="small fw-bold text-success" data-i18n="dashboard.online">Status</span>
                </div>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="../panel/dashboard.php" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" data-i18n="nav.console">CONSOLA</a>
                    <button class="btn btn-link text-danger btn-sm" onclick="logout()"><i class="fas fa-sign-out-alt"></i></button>
                <?php else: ?>
                    <button class="btn btn-outline-primary btn-sm rounded-pill px-4 fw-bold" onclick="openM('authModal')" data-i18n="nav.login">CLIENTE</button>
                <?php endif; ?>
                
            </div>
        </div>
    </nav>
