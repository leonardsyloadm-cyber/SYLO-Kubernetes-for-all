<?php
// index.php
// Página Corporativa SYLO Consultoría Tecnológica
require_once 'php/auth.php';
include 'components/header.php';
?>

<style>
    /* Premium Corporate Aesthetics */
    body {
        background-color: #030712; /* Deep modern dark blue/black */
    }
    
    .hero-corp { 
        padding: 220px 0 160px; 
        background: #030712;
        position: relative;
    }
    
    #tsparticles {
        position: absolute; width: 100%; height: 100%; top: 0; left: 0; z-index: 0; pointer-events: auto;
    }

    .hero-corp .container {
        position: relative;
        z-index: 10;
        pointer-events: none; /* Let clicks pass to particles */
    }
    
    .hero-corp .container * {
        pointer-events: auto;
    }

    .display-3.fw-bold {
        font-weight: 900 !important;
        letter-spacing: -2px;
        color: #f8fafc;
        text-shadow: 0 10px 30px rgba(0,0,0,0.8);
    }
    
    .display-3 .text-primary {
        background: linear-gradient(135deg, #06b6d4, #8b5cf6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .hero-corp .lead {
        color: #94a3b8 !important;
        font-size: 1.25rem;
        max-width: 800px;
        margin: 0 auto;
    }

    /* Glassmorphism Services */
    #servicios {
        background-color: #030712;
        padding-top: 5rem !important;
        padding-bottom: 8rem !important;
    }
    
    .section-title h2 {
        color: #f8fafc;
        font-weight: 800;
        letter-spacing: -1px;
    }

    .service-card { 
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
        border: 1px solid rgba(255, 255, 255, 0.05); 
        border-radius: 24px; 
        background: rgba(30, 41, 59, 0.5); 
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); 
        color: #f1f5f9;
        overflow: hidden;
        position: relative;
        z-index: 1;
    }
    
    .service-card::after {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; height: 4px;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
        opacity: 0;
        transition: opacity 0.3s;
    }

    .service-card:hover { 
        transform: translateY(-15px) scale(1.02); 
        box-shadow: 0 35px 60px -15px rgba(0,0,0,0.7);
        border-color: rgba(255,255,255,0.1);
        background: rgba(30, 41, 59, 0.8);
    }
    
    .service-card:hover::after {
        opacity: 1;
    }

    .service-card.star-card {
        background: linear-gradient(180deg, rgba(30, 58, 138, 0.3) 0%, rgba(15, 23, 42, 0.6) 100%);
        border: 1px solid rgba(59, 130, 246, 0.3);
        box-shadow: 0 0 40px rgba(37, 99, 235, 0.15) inset, 0 25px 50px -12px rgba(0,0,0,0.5);
    }
    
    .service-card.star-card:hover {
        border-color: rgba(59, 130, 246, 0.6);
        box-shadow: 0 0 60px rgba(37, 99, 235, 0.3) inset, 0 35px 60px -15px rgba(0,0,0,0.8);
    }

    .icon-box { 
        width: 65px; height: 65px; 
        border-radius: 18px; 
        display: flex; align-items: center; justify-content: center; 
        margin-bottom: 25px;
        box-shadow: 0 10px 20px rgba(0,0,0,0.3);
    }
    
    .service-card h4 {
        color: #f8fafc;
        margin-bottom: 15px;
    }
    
    .service-card p.text-muted {
        color: #cbd5e1 !important;
        font-size: 1.05rem;
        line-height: 1.6;
    }

    /* Buttons Premium */
    .btn-primary.btn-lg {
        background: linear-gradient(135deg, #06b6d4, #8b5cf6);
        border: none;
        box-shadow: 0 10px 20px rgba(139, 92, 246, 0.3);
        transition: all 0.3s ease;
    }
    
    .btn-primary.btn-lg:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 30px rgba(6, 182, 212, 0.4);
        background: linear-gradient(135deg, #0891b2, #7c3aed);
    }

    .btn-outline-dark.btn-lg {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        color: white;
        backdrop-filter: blur(5px);
        transition: all 0.3s ease;
    }
    
    .btn-outline-dark.btn-lg:hover {
        background: rgba(255,255,255,0.1);
        color: white;
        border-color: rgba(255,255,255,0.2);
        transform: translateY(-3px);
    }

    /* Team Section */
    #empresa {
        background-color: #0f172a;
        color: #f8fafc;
        border-top: 1px solid rgba(255,255,255,0.05);
    }
    
    #empresa .bg-light {
        background-color: #1e293b !important;
        border-color: rgba(255,255,255,0.05) !important;
    }
    
    #empresa h2 { color: white; }
    
    .avatar {
        box-shadow: 0 10px 25px rgba(0,0,0,0.4);
        border: 3px solid rgba(255,255,255,0.1);
        transition: transform 0.3s ease;
    }
    
    .avatar:hover {
        transform: scale(1.1) rotate(5deg);
    }
</style>

<section class="hero-corp text-center">
    <div id="tsparticles"></div>
    <div class="container" data-aos="zoom-in" data-aos-duration="1000">
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 mb-4 px-4 py-2 rounded-pill fw-bold" style="letter-spacing: 1px;" data-i18n="corp.badge">NEO-TECH INNOVATION</span>
        <h1 class="display-3 fw-bold mb-4"><span data-i18n="corp.h1">Evolucionamos tu </span><span class="text-primary" data-i18n="corp.h1_span">Infraestructura</span></h1>
        <p class="lead mb-5" data-i18n="corp.lead">Consultoría Tecnológica Integral. Diseñamos, bastionamos y orquestamos arquitecturas digitales de alto rendimiento con precisión suiza para el mercado global.</p>
        <div class="d-flex justify-content-center gap-4">
            <a href="#servicios" class="btn btn-primary btn-lg rounded-pill px-5 py-3 fw-bold" data-i18n="corp.btn_services">Nuestros Servicios</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="panel.php" class="btn btn-outline-dark btn-lg rounded-pill px-5 py-3 fw-bold"><i class="fas fa-server me-2 text-primary" style="color:#06b6d4!important;"></i><span data-i18n="corp.btn_panel">Panel de Clústeres</span></a>
            <?php else: ?>
                <button class="btn btn-outline-dark btn-lg rounded-pill px-5 py-3 fw-bold" onclick="openM('authModal')" data-i18n="corp.btn_login">Acceso Clientes</button>
            <?php endif; ?>
        </div>
    </div>
</section>

<section id="servicios" class="py-5">
    <div class="container py-5">
        <div class="text-center mb-5 section-title" data-aos="fade-up">
            <h2 class="display-5 fw-bold mb-3" data-i18n="corp.sec_title">Ingeniería Real</h2>
            <p class="text-muted lead mx-auto" style="max-width: 600px;" data-i18n="corp.sec_desc">Desplegamos soluciones tecnológicas avanzadas, sin fricciones y altamente escalables para empresas exigentes.</p>
        </div>
        <div class="row g-4 mt-4">
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                <div class="card service-card h-100 p-5">
                    <div class="icon-box text-white" style="background: linear-gradient(135deg, #1e3a8a, #3b82f6);"><i class="fas fa-shield-alt fa-2x"></i></div>
                    <h4 class="fw-bold fs-3" data-i18n="corp.cyber_title">Ciberseguridad</h4>
                    <p class="text-muted" data-i18n="corp.cyber_desc">Realizamos auditorías de penetración profundas, mitigación de vulnerabilidades Zero-Day y bastionado de servidores en producción bajo normativas ISO.</p>
                </div>
            </div>
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                <div class="card service-card h-100 p-5">
                    <div class="icon-box text-white" style="background: linear-gradient(135deg, #047857, #10b981);"><i class="fas fa-code fa-2x"></i></div>
                    <h4 class="fw-bold fs-3" data-i18n="corp.dev_title">Desarrollo a Medida</h4>
                    <p class="text-muted" data-i18n="corp.dev_desc">Ingeniería de software pura. Diseñamos soluciones nativas y SPA en React, Vue y ecosistemas Python/PHP pensadas para escalar con alto tráfico.</p>
                </div>
            </div>
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="300">
                <!-- Portal al Panel -->
                <div class="card service-card star-card h-100 p-5" style="cursor: pointer;" onclick="location.href='panel.php'">
                    <div class="icon-box text-white shadow-lg" style="background: linear-gradient(135deg, #06b6d4, #8b5cf6);"><i class="fas fa-network-wired fa-2x"></i></div>
                    <h4 class="fw-bold text-primary fs-3" data-i18n="corp.cloud_title">Plataforma Cloud</h4>
                    <span class="badge mb-3 px-3 py-1 bg-opacity-25 rounded-pill" style="width:fit-content; font-weight:800; background:rgba(139, 92, 246, 0.15); color:#c4b5fd; border: 1px solid rgba(139, 92, 246, 0.4);" data-i18n="corp.cloud_badge">SERVICIO ESTRELLA</span>
                    <p class="text-muted" data-i18n="corp.cloud_desc">Accede a nuestra infraestructura propietaria con AMD Ryzen™ Threadripper™ y almacenamiento NVMe Gen5. Orquesta tu clúster de Kubernetes en segundos.</p>
                    <div class="mt-auto pt-4 text-end">
                        <span class="text-primary fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.9rem;"><span data-i18n="corp.cloud_btn">Desplegar ahora</span> <i class="fas fa-arrow-right ms-2 transition-icon"></i></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="empresa" class="py-5 overflow-hidden">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="position-relative rounded-4 overflow-hidden shadow-lg border border-secondary border-opacity-25" style="height: 500px;">
                    <img src="https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&q=80&w=1000" class="w-100 h-100 object-fit-cover" alt="Datacenter Server Rack" style="filter: brightness(0.8) contrast(1.1);">
                    <div class="position-absolute bottom-0 start-0 w-100 p-4" style="background: linear-gradient(0deg, #030712 0%, transparent 100%);">
                        <span class="badge border border-primary border-opacity-50 px-3 py-2 rounded-pill fs-6" style="background: rgba(3, 7, 18, 0.8); backdrop-filter: blur(5px);"><i class="fas fa-microchip me-2 text-primary"></i>AMD Ryzen™ Threadripper™</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <h6 class="text-primary fw-bold mb-3" data-i18n="corp.team_mission" style="letter-spacing:2px;">NUESTRA MISIÓN</h6>
                <h2 class="display-5 fw-bold mb-4" data-i18n="corp.team_title">Detrás de la máquina</h2>
                <p class="text-muted lead mb-5" data-i18n="corp.team_desc">Sylo nació en Alicante fruto de la frustración con los proveedores de nube tradicionales limitados y caros. Proveemos los recursos brutos y puros que tus aplicaciones demandan con estética Cyberpunk y rendimiento Hypervisor.</p>
                <div class="row g-4 mt-2 mb-5">
                     <div class="col-sm-6">
                        <div class="p-3 rounded-4 shadow-sm border border-secondary border-opacity-25 d-flex align-items-center gap-3" style="background: rgba(30, 41, 59, 0.3); backdrop-filter: blur(10px);">
                            <img src="https://ui-avatars.com/api/?name=Ivan+Arlanzon&background=0f172a&color=fff&size=50" class="rounded-circle border border-2 border-primary">
                            <div><h5 class="fw-bold mb-0 text-white fs-6">Ivan A.</h5><span class="text-primary small fw-bold">CEO & Founder</span></div>
                        </div>
                     </div>
                     <div class="col-sm-6">
                        <div class="p-3 rounded-4 shadow-sm border border-secondary border-opacity-25 d-flex align-items-center gap-3" style="background: rgba(30, 41, 59, 0.3); backdrop-filter: blur(10px);">
                            <img src="https://ui-avatars.com/api/?name=Leonard+Baicu&background=6d28d9&color=fff&size=50" class="rounded-circle border border-2 border-success">
                            <div><h5 class="fw-bold mb-0 text-white fs-6">Leonard B.</h5><span class="text-success small fw-bold">CTO & Lead Arch</span></div>
                        </div>
                     </div>
                </div>
                <a href="https://github.com/leonardsyloadm-cyber" target="_blank" class="btn btn-outline-light rounded-pill px-5 py-3 fw-bold"><i class="fab fa-github me-2 fa-lg"></i><span data-i18n="corp.team_btn">Repositorio Abierto</span></a>
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/tsparticles-preset-network@2/tsparticles.preset.network.bundle.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        if(typeof tsParticles !== 'undefined') {
            tsParticles.load("tsparticles", {
                preset: "network",
                background: { color: { value: "transparent" } },
                particles: {
                    color: { value: ["#06b6d4", "#8b5cf6"] },
                    links: { color: "#3b82f6", opacity: 0.15, distance: 150 },
                    move: { speed: 0.8 },
                    number: { value: 60 }
                }
            });
        }
    });
</script>

<?php include 'components/footer.php'; ?>
<!-- Fin del Archivo -->
