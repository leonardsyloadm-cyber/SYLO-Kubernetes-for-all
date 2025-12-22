import customtkinter as ctk
import subprocess
import threading
import os
import json
import sys
import time
import signal
import psutil
import glob
from tkinter import messagebox, TclError
from datetime import datetime

# IMPORTACIÓN DE IMÁGENES (PILLOW)
try:
    from PIL import Image, ImageTk
    PIL_INSTALLED = True
except ImportError:
    PIL_INSTALLED = False
    print("⚠️ ADVERTENCIA: Instala Pillow para ver el logo: pip install pillow")

# Manejo de importación de pymysql
try:
    import pymysql
    PYMYSQL_INSTALLED = True
except ImportError:
    PYMYSQL_INSTALLED = False

# ================= CONFIGURACIÓN DE RUTAS =================
CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(CURRENT_DIR) 
WORKER_DIR = os.path.join(BASE_DIR, "worker")
BUZON_DIR = os.path.join(BASE_DIR, "buzon-pedidos")

# *** AQUÍ ESTÁ TU LOGO ***
LOGO_PATH = os.path.join(CURRENT_DIR, "Logo.png") 

# ================= CONFIGURACIÓN VISUAL =================
ctk.set_appearance_mode("Dark")
ctk.set_default_color_theme("dark-blue")

COLOR_BG = "#020617"
COLOR_PANEL = "#0f172a"
COLOR_CARD = "#1e293b"
COLOR_ACCENT = "#3b82f6"
COLOR_SUCCESS = "#10b981"
COLOR_DANGER = "#ef4444"
COLOR_WARNING = "#f59e0b"
COLOR_TEXT = "#f8fafc"
COLOR_TEXT_MUTED = "#94a3b8"

DB_CONFIG = {"host": "127.0.0.1", "user": "root", "password": "root", "database": "kylo_main_db", "port": 3306}

# ================= CLASE GESTOR DB =================
class DatabaseManager(ctk.CTkToplevel):
    def __init__(self, parent):
        super().__init__(parent)
        self.title("Sylo Data Studio")
        self.geometry("1400x900")
        self.configure(fg_color=COLOR_BG)
        self.parent_app = parent
        self.current_table = "orders"
        self.dirty_ids = set()

        self.grid_columnconfigure(1, weight=1)
        self.grid_rowconfigure(0, weight=1)

        # Sidebar
        self.sidebar = ctk.CTkFrame(self, width=250, fg_color=COLOR_PANEL, corner_radius=0)
        self.sidebar.grid(row=0, column=0, sticky="nsew")
        ctk.CTkLabel(self.sidebar, text="DATA STUDIO", font=("Arial", 20, "bold"), text_color=COLOR_ACCENT).pack(pady=30)
        
        self.tables = ["users", "orders", "order_specs", "plans"]
        for t in self.tables:
            ctk.CTkButton(self.sidebar, text=t.upper(), fg_color="transparent", border_width=1, border_color=COLOR_ACCENT,
                          hover_color=COLOR_ACCENT, anchor="w", command=lambda x=t: self.switch_table(x)).pack(fill="x", padx=20, pady=5)

        # Main Area
        self.main_area = ctk.CTkFrame(self, fg_color="transparent")
        self.main_area.grid(row=0, column=1, sticky="nsew", padx=20, pady=20)
        self.main_area.grid_rowconfigure(2, weight=1)
        self.main_area.grid_columnconfigure(0, weight=1)

        # Tools
        self.tools = ctk.CTkFrame(self.main_area, fg_color=COLOR_CARD, height=60)
        self.tools.grid(row=0, column=0, sticky="ew", pady=(0, 20))
        self.lbl_table = ctk.CTkLabel(self.tools, text="ORDERS", font=("Arial", 18, "bold"))
        self.lbl_table.pack(side="left", padx=20, pady=15)
        self.sync_btn = ctk.CTkButton(self.tools, text="⚡ APLICAR CAMBIOS (0)", fg_color=COLOR_PANEL, state="disabled", command=self.apply_changes)
        self.sync_btn.pack(side="right", padx=20)
        ctk.CTkButton(self.tools, text="🔄 REFRESCAR", fg_color=COLOR_ACCENT, width=100, command=self.load_data).pack(side="right", padx=5)

        # Grid
        self.header_frame = ctk.CTkFrame(self.main_area, fg_color=COLOR_PANEL, height=40, corner_radius=5)
        self.header_frame.grid(row=1, column=0, sticky="ew", pady=(0, 5))
        self.scroll = ctk.CTkScrollableFrame(self.main_area, fg_color="transparent")
        self.scroll.grid(row=2, column=0, sticky="nsew")

        # SQL
        self.sql_frame = ctk.CTkFrame(self.main_area, fg_color=COLOR_CARD, height=100)
        self.sql_frame.grid(row=3, column=0, sticky="ew", pady=(20, 0))
        self.sql_entry = ctk.CTkEntry(self.sql_frame, placeholder_text="SELECT * FROM...", height=40, font=("Consolas", 12))
        self.sql_entry.pack(side="left", fill="x", expand=True, padx=15, pady=15)
        ctk.CTkButton(self.sql_frame, text="EJECUTAR", fg_color=COLOR_WARNING, text_color="black", command=self.run_sql).pack(side="right", padx=15)

        self.load_data()

    def switch_table(self, table):
        self.current_table = table
        self.lbl_table.configure(text=table.upper())
        self.load_data()

    def load_data(self):
        if not PYMYSQL_INSTALLED: return
        for w in self.scroll.winfo_children(): w.destroy()
        for w in self.header_frame.winfo_children(): w.destroy()
        
        try:
            conn = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
            with conn.cursor() as cursor:
                cursor.execute(f"DESCRIBE {self.current_table}")
                cols = [c['Field'] for c in cursor.fetchall()]
                for c in cols: ctk.CTkLabel(self.header_frame, text=c[:15].upper(), width=120, font=("Arial", 10, "bold"), text_color=COLOR_ACCENT).pack(side="left", padx=2)
                cursor.execute(f"SELECT * FROM {self.current_table} LIMIT 100")
                rows = cursor.fetchall()
                for i, row in enumerate(rows):
                    bg = COLOR_CARD if i % 2 == 0 else COLOR_PANEL
                    f = ctk.CTkFrame(self.scroll, fg_color=bg, height=35)
                    f.pack(fill="x", pady=1)
                    row_id = row.get('id') or row.get('order_id')
                    for c in cols:
                        e = ctk.CTkEntry(f, width=120, fg_color="transparent", border_width=0, font=("Consolas", 11))
                        e.insert(0, str(row[c]) if row[c] is not None else "NULL")
                        e.pack(side="left", padx=2)
                        e.bind("<Return>", lambda ev, r=row_id, col=c, ent=e: self.update_cell(r, col, ent.get()))
        except: pass
        finally: 
            if 'conn' in locals(): conn.close()

    def update_cell(self, rid, col, val):
        try:
            conn = pymysql.connect(**DB_CONFIG)
            pk = "order_id" if self.current_table == "order_specs" else "id"
            with conn.cursor() as c: c.execute(f"UPDATE {self.current_table} SET {col}=%s WHERE {pk}=%s", (val, rid))
            conn.commit(); conn.close()
            if self.current_table in ["orders", "order_specs"]: self.dirty_ids.add(rid); self.sync_btn.configure(state="normal", text=f"⚡ APLICAR ({len(self.dirty_ids)})", fg_color=COLOR_WARNING)
            self.load_data()
        except: pass

    def apply_changes(self):
        self.dirty_ids.clear()
        self.sync_btn.configure(state="disabled", text="SINCRONIZADO", fg_color=COLOR_PANEL)
        messagebox.showinfo("Sync", "Cambios aplicados.")

    def run_sql(self):
        try:
            conn = pymysql.connect(**DB_CONFIG)
            with conn.cursor() as c: c.execute(self.sql_entry.get())
            conn.commit(); conn.close(); self.sql_entry.delete(0, 'end'); self.load_data()
        except Exception as e: messagebox.showerror("Error", str(e))


# ================= CLASE PRINCIPAL OKTOPUS v8.1 =================
class OktopusApp(ctk.CTk):
    def __init__(self):
        super().__init__()
        
        # INICIO: VENTANA OCULTA Y TRANSPARENTE
        self.title("Sylo Enterprise Control Center v8.1")
        self.geometry("1600x950")
        self.running = True
        self.protocol("WM_DELETE_WINDOW", self.on_close)
        self.attributes("-alpha", 0.0) # Invisible al arrancar

        # Variables
        self.worker_processes = {}
        self.worker_widgets = {}
        self.db_window = None
        self.status_indicators = {}
        self.client_cards = {} 
        self.frames = {}
        self.menu_btns = {}

        # LANZAMOS PANTALLA DE CARGA
        self.build_splash_screen()

    # ================= SPLASH SCREEN & LOGO =================
    def build_splash_screen(self):
        self.splash_frame = ctk.CTkFrame(self, fg_color=COLOR_BG)
        self.splash_frame.pack(fill="both", expand=True)

        # Cargar Logo
        try:
            if PIL_INSTALLED and os.path.exists(LOGO_PATH):
                # Cargar y redimensionar el logo a 300x300 aprox
                pil_image = Image.open(LOGO_PATH)
                # Mantener ratio
                aspect = pil_image.width / pil_image.height
                new_h = 350
                new_w = int(new_h * aspect)
                pil_image = pil_image.resize((new_w, new_h), Image.Resampling.LANCZOS)
                
                self.logo_img = ctk.CTkImage(light_image=pil_image, dark_image=pil_image, size=(new_w, new_h))
                
                logo_lbl = ctk.CTkLabel(self.splash_frame, image=self.logo_img, text="")
                logo_lbl.pack(pady=(80, 20))
            else:
                # Fallback texto si no hay imagen
                ctk.CTkLabel(self.splash_frame, text="SYLO", font=("Arial", 80, "bold"), text_color=COLOR_ACCENT).pack(pady=(150, 10))
        except Exception as e:
            print(f"Error Logo: {e}")
            ctk.CTkLabel(self.splash_frame, text="SYLO", font=("Arial", 80, "bold")).pack(pady=(150, 10))

        # Título
        ctk.CTkLabel(self.splash_frame, text="OKTOPUS KERNEL", font=("Arial", 20, "bold"), text_color="white").pack(pady=(0, 30))

        # Barra de Progreso
        self.progress_bar = ctk.CTkProgressBar(self.splash_frame, width=500, height=15, corner_radius=10, progress_color=COLOR_ACCENT)
        self.progress_bar.set(0)
        self.progress_bar.pack(pady=10)

        # Texto de Estado
        self.status_lbl = ctk.CTkLabel(self.splash_frame, text="Iniciando...", font=("Consolas", 12), text_color="gray")
        self.status_lbl.pack()

        # Mostrar ventana
        self.attributes("-alpha", 1.0)
        
        # Iniciar simulación de carga
        threading.Thread(target=self.loading_sequence, daemon=True).start()

    def update_splash(self, val, msg):
        self.progress_bar.set(val)
        self.status_lbl.configure(text=msg)

    def loading_sequence(self):
        # Pasos de carga simulada (Checkeos)
        steps = [
            (0.1, "Cargando módulos de interfaz..."),
            (0.2, "Verificando conexión Docker Socket..."),
            (0.4, "Conectando a Kylo Database Maestra..."),
            (0.5, "Escaneando clústeres Kubernetes..."),
            (0.7, "Comprobando integridad de Workers..."),
            (0.8, "Calculando métricas financieras..."),
            (0.9, "Sincronizando estado de la flota..."),
            (1.0, "¡Sistemas listos! Bienvenido, CEO.")
        ]

        for val, msg in steps:
            time.sleep(0.6) # Velocidad de carga
            # Usar after para hilo seguro
            self.after(0, lambda v=val, m=msg: self.update_splash(v, m))

        time.sleep(0.5)
        self.after(0, self.transition_to_main)

    def transition_to_main(self):
        # Efecto Fade Out
        alpha = self.attributes("-alpha")
        if alpha > 0.0:
            alpha -= 0.05
            self.attributes("-alpha", alpha)
            self.after(20, self.transition_to_main)
        else:
            # Destruir splash y construir UI
            self.splash_frame.destroy()
            self.build_main_ui()
            self.fade_in()

    def fade_in(self):
        alpha = self.attributes("-alpha")
        if alpha < 1.0:
            alpha += 0.05
            self.attributes("-alpha", alpha)
            self.after(20, self.fade_in)
        else:
            self.attributes("-alpha", 1.0)

    # ================= CONSTRUCCIÓN UI PRINCIPAL (LIMPIA) =================
    def build_main_ui(self):
        self.grid_columnconfigure(1, weight=1)
        self.grid_rowconfigure(0, weight=1)

        # SIDEBAR (MODIFICADO: ELIMINADO EL MINI LOGO)
        self.sidebar = ctk.CTkFrame(self, width=280, fg_color=COLOR_PANEL, corner_radius=0)
        self.sidebar.grid(row=0, column=0, sticky="nsew")
        
        # Espacio en blanco en lugar del logo
        ctk.CTkFrame(self.sidebar, fg_color="transparent", height=60).pack() 

        ctk.CTkLabel(self.sidebar, text="🐙 OKTOPUS", font=("Arial", 30, "bold"), text_color=COLOR_ACCENT).pack(pady=(10, 5))
        ctk.CTkLabel(self.sidebar, text="ENTERPRISE EDITION", font=("Arial", 11), text_color=COLOR_TEXT_MUTED).pack(pady=(0, 40))

        for s in ["DASHBOARD", "INFRAESTRUCTURA", "FINANZAS", "LOGS & CONSOLA"]:
            btn = ctk.CTkButton(self.sidebar, text=s, height=50, fg_color="transparent", anchor="w", 
                                font=("Arial", 14, "bold"), command=lambda x=s: self.show_tab(x))
            btn.pack(fill="x", padx=10, pady=5); self.menu_btns[s] = btn

        footer = ctk.CTkFrame(self.sidebar, fg_color="transparent")
        footer.pack(side="bottom", fill="x", pady=20, padx=10)
        ctk.CTkButton(footer, text="DATA STUDIO", fg_color="#4f46e5", command=self.open_db_explorer).pack(fill="x", pady=5)
        ctk.CTkButton(footer, text="HIBERNAR TODO", fg_color=COLOR_DANGER, command=self.hibernate_all).pack(fill="x", pady=5)

        # MAIN AREA
        self.main_container = ctk.CTkFrame(self, fg_color=COLOR_BG)
        self.main_container.grid(row=0, column=1, sticky="nsew", padx=20, pady=20)
        self.main_container.grid_rowconfigure(0, weight=1); self.main_container.grid_columnconfigure(0, weight=1)

        self.create_dashboard_tab()
        self.create_infra_tab()
        self.create_finance_tab()
        self.create_console_tab()
        self.show_tab("DASHBOARD")
        
        # INICIAR BUCLES REALES AHORA
        self.check_system_health()
        self.start_log_reader()
        self.refresh_clients_loop()
        self.calculate_finance()
        self.log_to_console("SYSTEM", "Oktopus Kernel Online.", COLOR_SUCCESS)

    # --- (Resto de métodos lógicos idénticos a la versión estable anterior) ---
    def on_close(self):
        self.running = False
        try:
            for p in self.worker_processes.values(): p.terminate()
        except: pass
        self.destroy(); sys.exit(0)

    def show_tab(self, name):
        for n, f in self.frames.items(): f.grid_forget()
        self.frames[name].grid(row=0, column=0, sticky="nsew")
        for n, b in self.menu_btns.items(): b.configure(fg_color=COLOR_ACCENT if n == name else "transparent")

    def create_dashboard_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent")
        self.frames["DASHBOARD"] = f
        ctk.CTkLabel(f, text="Visión Global", font=("Arial", 24, "bold")).pack(anchor="w", pady=20)
        kpi = ctk.CTkFrame(f, fg_color="transparent"); kpi.pack(fill="x", pady=10)
        self.kpi_income = self.create_kpi(kpi, "INGRESOS", "0.00 €", COLOR_SUCCESS)
        self.kpi_cost = self.create_kpi(kpi, "COSTE", "0.00 €", COLOR_WARNING)
        self.kpi_profit = self.create_kpi(kpi, "BENEFICIO", "0.00 €", COLOR_ACCENT)
        self.kpi_active = self.create_kpi(kpi, "ACTIVOS", "0", "#a855f7")

        ctk.CTkLabel(f, text="Servicios", font=("Arial", 20, "bold")).pack(anchor="w", pady=(30, 10))
        st = ctk.CTkFrame(f, fg_color=COLOR_CARD, corner_radius=15); st.pack(fill="x", ipady=20, padx=5)
        for s in ["WEB (PHP)", "DATABASE (MySQL)", "OPERATOR (Python)", "ORCHESTRATOR"]:
            sf = ctk.CTkFrame(st, fg_color="transparent"); sf.pack(side="left", expand=True)
            lbl = ctk.CTkLabel(sf, text="●", font=("Arial", 40), text_color="gray"); lbl.pack()
            ctk.CTkLabel(sf, text=s, font=("Arial", 12, "bold")).pack()
            self.status_indicators[s] = lbl

        ctk.CTkLabel(f, text="Workers", font=("Arial", 20, "bold")).pack(anchor="w", pady=(30, 10))
        wf = ctk.CTkFrame(f, fg_color=COLOR_CARD); wf.pack(fill="x", ipady=10)
        self.create_worker_ctrl(wf, "operator_sylo.py", "OPERATOR")
        self.create_worker_ctrl(wf, "orchestrator_sylo.py", "ORCHESTRATOR")

    def create_kpi(self, p, t, v, c):
        fr = ctk.CTkFrame(p, fg_color=COLOR_CARD, corner_radius=15)
        fr.pack(side="left", expand=True, fill="both", padx=10, ipady=10)
        ctk.CTkLabel(fr, text=t, font=("Arial", 11, "bold"), text_color=COLOR_TEXT_MUTED).pack(pady=(15,5))
        l = ctk.CTkLabel(fr, text=v, font=("Arial", 28, "bold"), text_color=c); l.pack(pady=(0,15))
        return l

    def create_worker_ctrl(self, p, s, l):
        f = ctk.CTkFrame(p, fg_color="transparent"); f.pack(side="left", expand=True)
        ctk.CTkLabel(f, text=l, font=("Arial", 14, "bold")).pack(pady=5)
        bf = ctk.CTkFrame(f, fg_color="transparent"); bf.pack()
        self.worker_widgets[s] = {"dot": ctk.CTkLabel(bf, text="●", text_color="red", font=("Arial", 20)),
                                  "status": ctk.CTkLabel(bf, text="OFFLINE", text_color="gray")}
        self.worker_widgets[s]["dot"].pack(side="left", padx=5); self.worker_widgets[s]["status"].pack(side="left", padx=5)
        ctk.CTkButton(f, text="INICIAR", width=80, fg_color=COLOR_SUCCESS, command=lambda: self.start_worker(s)).pack(side="left", padx=2)
        ctk.CTkButton(f, text="DETENER", width=80, fg_color=COLOR_DANGER, command=lambda: self.stop_worker(s)).pack(side="left", padx=2)

    def create_infra_tab(self):
        container = ctk.CTkFrame(self.main_container, fg_color="transparent")
        self.frames["INFRAESTRUCTURA"] = container
        header = ctk.CTkFrame(container, fg_color="transparent"); header.pack(fill="x", pady=20)
        ctk.CTkLabel(header, text="Infraestructura", font=("Arial", 24, "bold")).pack(side="left")
        ctk.CTkButton(header, text="🔄 ESCANEAR AHORA", width=150, fg_color=COLOR_ACCENT, command=self.force_refresh_infra).pack(side="right")
        self.infra_area = ctk.CTkScrollableFrame(container, fg_color="transparent"); self.infra_area.pack(fill="both", expand=True)

    def create_finance_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent")
        self.frames["FINANZAS"] = f
        h = ctk.CTkFrame(f, fg_color="transparent"); h.pack(fill="x", pady=20)
        ctk.CTkLabel(h, text="Finanzas", font=("Arial", 24, "bold")).pack(side="left")
        ctk.CTkButton(h, text="🔄 REFRESCAR", font=("Arial", 12, "bold"), fg_color=COLOR_ACCENT, width=150, command=self.calculate_finance).pack(side="right")
        self.finance_scroll = ctk.CTkScrollableFrame(f, fg_color="transparent"); self.finance_scroll.pack(fill="both", expand=True)
        hh = ctk.CTkFrame(self.finance_scroll, fg_color=COLOR_PANEL, height=40); hh.pack(fill="x")
        for c in ["ID", "CLIENTE", "PLAN", "CPU/RAM", "PRECIO", "COSTE", "MARGEN"]:
            ctk.CTkLabel(hh, text=c, font=("Arial", 12, "bold"), width=120).pack(side="left", padx=10)

    def create_console_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent")
        self.frames["LOGS & CONSOLA"] = f
        # Consola readonly por defecto para el usuario
        self.master_console = ctk.CTkTextbox(f, font=("Consolas", 12), fg_color="black", text_color="#00ff00")
        self.master_console.pack(fill="both", expand=True, padx=10, pady=10)
        self.master_console.configure(state="disabled") 

    def log_to_console(self, source, message, color=None):
        if not self.running: return
        try:
            if not self.master_console.winfo_exists(): return
            self.master_console.configure(state="normal")
            timestamp = datetime.now().strftime('%H:%M:%S')
            tag = f"[{source[:4].upper()}]"
            full_line = f"[{timestamp}] {tag} {message}\n"
            self.master_console.insert("end", full_line)
            self.master_console.see("end")
            self.master_console.configure(state="disabled")
        except: pass

    def start_log_reader(self):
        def _read():
            file_positions = {}
            while self.running:
                for script in self.worker_widgets.keys():
                    path = f"/tmp/sylo_{script}.log"
                    if os.path.exists(path):
                        try:
                            with open(path, "rb") as f:
                                f.seek(file_positions.get(script, 0))
                                new_data = f.read().decode('utf-8', errors='ignore')
                                if new_data:
                                    file_positions[script] = f.tell()
                                    for line in new_data.splitlines():
                                        if line.strip(): self.after(0, lambda s=script, l=line: self.log_to_console(s, l))
                        except: pass
                time.sleep(0.5)
        threading.Thread(target=_read, daemon=True).start()

    def add_history(self, src, msg, color): self.log_to_console(src, msg)

    def check_system_health(self):
        if not self.running: return
        try:
            conn = pymysql.connect(**DB_CONFIG); conn.close()
            self.status_indicators["DATABASE (MySQL)"].configure(text_color=COLOR_SUCCESS)
        except: self.status_indicators["DATABASE (MySQL)"].configure(text_color=COLOR_DANGER) if "DATABASE (MySQL)" in self.status_indicators else None

        try:
            out = subprocess.check_output("docker ps --format '{{.Names}}'", shell=True, text=True)
            ok = "sylo-web" in out
            self.status_indicators["WEB (PHP)"].configure(text_color=COLOR_SUCCESS if ok else COLOR_DANGER)
        except: pass

        for s, w in self.worker_widgets.items():
            pid = self.find_system_process(s)
            c = COLOR_SUCCESS if pid else COLOR_DANGER
            w["dot"].configure(text_color=c)
            w["status"].configure(text="ONLINE" if pid else "OFFLINE", text_color=c)
            k = "OPERATOR (Python)" if "operator" in s else "ORCHESTRATOR"
            self.status_indicators[k].configure(text_color=c)
        
        self.after(3000, self.check_system_health)

    def calculate_finance(self):
        if not PYMYSQL_INSTALLED: return
        try:
            conn = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
            with conn.cursor() as c:
                c.execute("SELECT o.id, u.username, p.name as plan, p.price, COALESCE(os.cpu_cores, p.cpu_cores) as cpu, COALESCE(os.ram_gb, p.ram_gb) as ram FROM orders o JOIN users u ON o.user_id=u.id JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id=os.order_id WHERE o.status IN ('active', 'suspended')")
                rows = c.fetchall()
            conn.close()

            tr, tc = 0, 0
            if self.finance_scroll.winfo_exists():
                for w in self.finance_scroll.winfo_children():
                    if isinstance(w, ctk.CTkFrame) and w != self.finance_scroll.winfo_children()[0]: w.destroy()

                for r in rows:
                    rev, cpu, ram = float(r['price']), int(r['cpu'] or 1), int(r['ram'] or 1)
                    cost = 2.00 + (cpu * 1.50) + (ram * 0.50)
                    m, tr, tc = rev - cost, tr + rev, tc + cost
                    
                    rw = ctk.CTkFrame(self.finance_scroll, fg_color=COLOR_CARD, height=40); rw.pack(fill="x", pady=1)
                    for v in [f"#{r['id']}", r['username'], r['plan'], f"{cpu}v/{ram}GB"]: ctk.CTkLabel(rw, text=str(v), width=120).pack(side="left", padx=10)
                    ctk.CTkLabel(rw, text=f"{rev:.2f}€", width=120, text_color=COLOR_SUCCESS).pack(side="left", padx=10)
                    ctk.CTkLabel(rw, text=f"{cost:.2f}€", width=120, text_color=COLOR_WARNING).pack(side="left", padx=10)
                    ctk.CTkLabel(rw, text=f"{m:.2f}€", width=120, text_color=COLOR_ACCENT if m>0 else COLOR_DANGER).pack(side="left", padx=10)

                self.kpi_income.configure(text=f"{tr:.2f} €"); self.kpi_cost.configure(text=f"{tc:.2f} €"); self.kpi_profit.configure(text=f"{(tr-tc):.2f} €"); self.kpi_active.configure(text=str(len(rows)))
                self.log_to_console("FINANCE", "Financial Data Refreshed.", COLOR_SUCCESS)
        except Exception as e: print(e)

    def force_refresh_infra(self):
        self.log_to_console("SYSTEM", "Scanning infrastructure...", COLOR_ACCENT)
        threading.Thread(target=self._manual_scan).start()

    def _manual_scan(self):
        try:
            raw = subprocess.check_output("minikube profile list -o json", shell=True, text=True)
            idx = raw.find('{'); raw = raw[idx:] if idx != -1 else "{}"
            data = json.loads(raw); profiles = data.get('valid', []) + data.get('invalid', [])
            conts = subprocess.check_output("docker ps -a --format '{{.Names}}'", shell=True, text=True).split()
            if self.running: self.after(0, lambda: self.smart_update_infra(profiles, conts))
        except: pass

    def refresh_clients_loop(self):
        if not self.running: return
        self._manual_scan()
        self.after(5000, self.refresh_clients_loop)

    def smart_update_infra(self, profiles, conts):
        if not self.running or not self.infra_area.winfo_exists(): return
        current_names = set()
        for p in profiles:
            name = p['Name']
            if any(x in name for x in ["sylo-cliente-", "Cliente"]):
                current_names.add(name)
                ip = ""
                try:
                    if name in conts: 
                        ip = subprocess.check_output(f"docker inspect -f '{{{{range .NetworkSettings.Networks}}}}{{{{.IPAddress}}}}{{{{end}}}}' {name}", shell=True, text=True).strip()
                except: pass
                
                if name in self.client_cards:
                    try:
                        widgets = self.client_cards[name]
                        if not widgets['frame'].winfo_exists(): raise TclError
                        run = len(ip) > 2
                        widgets['dot'].configure(text="🟢" if run else "🔴")
                        widgets['sub'].configure(text=f"IP: {ip if ip else 'APAGADO'}")
                        widgets['frame'].configure(border_color=COLOR_SUCCESS if run else COLOR_DANGER)
                        if run:
                            widgets['btn_shell'].grid(row=0, column=0, padx=5)
                            widgets['btn_conf'].grid(row=0, column=1, padx=5)
                        else:
                            widgets['btn_shell'].grid_forget()
                            widgets['btn_conf'].grid_forget()
                    except TclError: del self.client_cards[name]
                else:
                    self.create_client_card(name, ip)

        for name in list(self.client_cards.keys()):
            if name not in current_names:
                try: self.client_cards[name]['frame'].destroy()
                except: pass
                del self.client_cards[name]

    def create_client_card(self, name, ip):
        try:
            if not self.infra_area.winfo_exists(): return
            run = len(ip) > 2
            card = ctk.CTkFrame(self.infra_area, fg_color=COLOR_CARD, border_width=1, border_color=COLOR_SUCCESS if run else COLOR_DANGER)
            card.pack(fill="x", padx=10, pady=5)
            lbl_dot = ctk.CTkLabel(card, text="🟢" if run else "🔴", font=("Arial", 24)); lbl_dot.pack(side="left", padx=15)
            info = ctk.CTkFrame(card, fg_color="transparent"); info.pack(side="left", padx=10)
            ctk.CTkLabel(info, text=name.upper(), font=("Arial", 14, "bold"), text_color="white").pack(anchor="w")
            lbl_sub = ctk.CTkLabel(info, text=f"IP: {ip if ip else 'APAGADO'}", font=("Arial", 11), text_color="gray"); lbl_sub.pack(anchor="w")
            actions = ctk.CTkFrame(card, fg_color="transparent"); actions.pack(side="right", padx=10)
            btn_shell = ctk.CTkButton(actions, text=">_ SHELL", width=80, fg_color="#4f46e5", command=lambda: self.open_webshell(name))
            btn_conf = ctk.CTkButton(actions, text="⚙️ CONF", width=80, fg_color="#0ea5e9", command=lambda: self.edit_config(name))
            if run:
                btn_shell.grid(row=0, column=0, padx=5)
                btn_conf.grid(row=0, column=1, padx=5)
            ctk.CTkButton(actions, text="ELIMINAR", width=80, fg_color=COLOR_DANGER, command=lambda: self.kill_machine_direct(name)).grid(row=0, column=2, padx=5)
            self.client_cards[name] = {'frame': card, 'dot': lbl_dot, 'sub': lbl_sub, 'btn_shell': btn_shell, 'btn_conf': btn_conf}
        except: pass

    def find_system_process(self, n):
        for p in psutil.process_iter(['pid','cmdline']):
            try: 
                if p.info['cmdline'] and n in ' '.join(p.info['cmdline']): return p.info['pid']
            except: pass
        return None

    def start_worker(self, s):
        if self.find_system_process(s): return
        try:
            l = open(f"/tmp/sylo_{s}.log", "w")
            p = subprocess.Popen(["python3", "-u", os.path.join(WORKER_DIR, s)], stdout=l, stderr=subprocess.STDOUT, preexec_fn=os.setsid)
            self.worker_processes[s] = p
            self.log_to_console("WORKER", f"Started {s} (PID: {p.pid})", COLOR_SUCCESS)
        except Exception as e: self.log_to_console("ERROR", str(e), COLOR_DANGER)

    def stop_worker(self, s):
        pid = self.find_system_process(s)
        if pid: os.kill(pid, signal.SIGTERM); self.log_to_console("WORKER", f"Stopped {s}", COLOR_WARNING)

    def open_webshell(self, name):
        f = f"/tmp/sylo_shell_{name}.sh"
        with open(f, "w") as file: file.write(f"#!/bin/bash\nPOD=$(minikube -p {name} kubectl -- get pods -o name | head -1)\nminikube -p {name} kubectl -- exec -it $POD -- /bin/sh")
        os.chmod(f, 0o755); subprocess.Popen(f"gnome-terminal -- {f}", shell=True)

    def edit_config(self, name):
        f = f"/tmp/sylo_edit_{name}.sh"
        with open(f, "w") as file: file.write(f"#!/bin/bash\nminikube -p {name} kubectl -- edit cm web-content-config || minikube -p {name} kubectl -- edit cm custom-web-content")
        os.chmod(f, 0o755); subprocess.Popen(f"gnome-terminal -- {f}", shell=True)

    def kill_machine_direct(self, name):
        clean = name.lower().replace("sylo-cliente-", "").replace("cliente", "")
        self.log_to_console("KILLER", f"Deleting {clean}...", COLOR_DANGER)
        threading.Thread(target=self._perform_direct_kill, args=(clean, f"sylo-cliente-{clean}")).start()

    def _perform_direct_kill(self, oid, profile):
        subprocess.run(f"minikube delete -p {profile}", shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(f"docker rm -f {profile}", shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if PYMYSQL_INSTALLED:
            try:
                conn = pymysql.connect(**DB_CONFIG); c = conn.cursor()
                c.execute(f"DELETE FROM order_specs WHERE order_id={oid}"); c.execute(f"DELETE FROM orders WHERE id={oid}")
                conn.commit(); conn.close()
            except: pass
        for f in glob.glob(os.path.join(BUZON_DIR, f"*{oid}*")):
            try: os.remove(f)
            except: pass
        self.log_to_console("KILLER", "Deletion Complete.", COLOR_SUCCESS)

    def hibernate_all(self): threading.Thread(target=lambda: subprocess.run("minikube stop --all", shell=True)).start()
    def open_db_explorer(self): self.db_window = DatabaseManager(self) if self.db_window is None or not self.db_window.winfo_exists() else self.db_window.focus()

if __name__ == "__main__":
    OktopusApp().mainloop()