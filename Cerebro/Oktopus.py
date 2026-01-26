#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# ==============================================================================
#  🐙 OKTOPUS ENTERPRISE MANAGER - V23 (DNS SUDO ENABLED)
#  Control Central: API, Workers y DNS (Privileged)
# ==============================================================================

import sys
import os
import subprocess
import time
import signal
import json
import threading
import asyncio
from datetime import datetime

# ================= BOOTSTRAPPER (AUTO-CONFIGURACIÓN) =================
CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(CURRENT_DIR) 
VENV_PYTHON = os.path.join(BASE_DIR, "venv", "bin", "python")

# Auto-Recarga con VENV
if os.path.exists(VENV_PYTHON) and sys.executable != VENV_PYTHON:
    print(f"🔄 [BOOT] Cambiando a entorno virtual: {VENV_PYTHON}")
    os.execv(VENV_PYTHON, [VENV_PYTHON] + sys.argv)

# Auto-Instalación de Dependencias
def install_dependencies():
    required = ["customtkinter", "psutil", "requests", "pymysql", "Pillow"]
    try:
        import customtkinter
        import psutil
        import requests
    except ImportError:
        print("⚙️ [BOOT] Instalando librerías faltantes...")
        subprocess.check_call([sys.executable, "-m", "pip", "install"] + required)
        print("✅ [BOOT] Dependencias instaladas. Reiniciando Oktopus...")
        os.execv(sys.executable, [sys.executable] + sys.argv)

install_dependencies()

# ================= INICIO DE LA APLICACIÓN =================
import customtkinter as ctk
import psutil
import requests
from tkinter import messagebox
import tkinter as tk 

# --- CONFIGURACIÓN ---
WORKER_DIR = os.path.join(BASE_DIR, "worker")
BUZON_DIR = os.path.join(BASE_DIR, "buzon-pedidos")
LOGO_PATH = os.path.join(CURRENT_DIR, "Logo.png")
API_URL = "http://127.0.0.1:8001/api/clientes"

ctk.set_appearance_mode("Dark")
ctk.set_default_color_theme("dark-blue")

# --- PALETA DE COLORES "NEON GENESIS" ---
C_BG_MAIN = "#050a14"      
C_BG_SIDEBAR = "#0a1120"   
C_BG_CARD = "#131c31"      
C_BG_CARD_HOVER = "#1c2a45" 
C_ACCENT_CYAN = "#06b6d4"  
C_ACCENT_BLUE = "#3b82f6"  
C_ACCENT_PURPLE = "#a855f7" 
C_SUCCESS = "#10b981"      
C_WARNING = "#f59e0b"      
C_DANGER = "#ef4444"       
C_TEXT_WHITE = "#f8fafc"
C_TEXT_MUTED = "#94a3b8"
C_BORDER_GLOW = "#1e3a8a"  

C_CONSOLE_BG = "#000000"
C_API_TXT = "#00ffff"     
C_SYS_TXT = "#00ff00"     

# --- FUENTES ---
try:
    FONT_HEAD = ("Montserrat", 22, "bold")
    FONT_SUBHEAD = ("Roboto", 14, "bold")
    FONT_BODY = ("Roboto", 12)
    FONT_MONO = ("Fira Code", 11)
    FONT_KPI_VAL = ("Montserrat", 32, "bold")
except:
    FONT_HEAD = ("Arial", 22, "bold")
    FONT_SUBHEAD = ("Arial", 14, "bold")
    FONT_BODY = ("Arial", 12)
    FONT_MONO = ("Consolas", 11)
    FONT_KPI_VAL = ("Arial", 32, "bold")

try:
    from PIL import Image, ImageTk
    PIL_INSTALLED = True
except ImportError: PIL_INSTALLED = False

try:
    import pymysql
    PYMYSQL_INSTALLED = True
except ImportError: PYMYSQL_INSTALLED = False
DB_CONFIG = {"host": "127.0.0.1", "user": "root", "password": "root", "database": "kylo_main_db", "port": 3306}

# ================= UI HELPERS =================
class ModernCard(ctk.CTkFrame):
    def __init__(self, parent, hover_effect=False, border_color=C_BORDER_GLOW, fg_color=C_BG_CARD, **kwargs):
        super().__init__(parent, fg_color=fg_color, corner_radius=12, border_width=1, border_color=border_color, **kwargs)
        if hover_effect:
            self.bind("<Enter>", lambda e: self.configure(fg_color=C_BG_CARD_HOVER, border_color=C_ACCENT_CYAN))
            self.bind("<Leave>", lambda e: self.configure(fg_color=fg_color, border_color=border_color))

class LEDIndicator(ctk.CTkLabel):
    def __init__(self, parent, text_label):
        super().__init__(parent, text="●", font=("Arial", 24), text_color="gray")
        self.label_txt = text_label
    def set_status(self, is_online):
        color = C_SUCCESS if is_online else C_DANGER
        self.configure(text_color=color)

# ================= GESTOR DB =================
class DatabaseManager(ctk.CTkToplevel):
    def __init__(self, parent):
        super().__init__(parent)
        self.title("Sylo Data Studio"); self.geometry("1400x900"); self.configure(fg_color=C_BG_MAIN)
        self.parent_app = parent; self.current_table = "orders"; self.dirty_ids = set()
        self.grid_columnconfigure(1, weight=1); self.grid_rowconfigure(0, weight=1)
        
        self.sidebar = ctk.CTkFrame(self, width=250, fg_color=C_BG_SIDEBAR, corner_radius=0); self.sidebar.grid(row=0, column=0, sticky="nsew")
        ctk.CTkLabel(self.sidebar, text="DATA STUDIO", font=FONT_HEAD, text_color=C_ACCENT_CYAN).pack(pady=30)
        for t in ["users", "orders", "order_specs", "plans"]:
            ctk.CTkButton(self.sidebar, text=t.upper(), fg_color="transparent", border_width=1, border_color=C_BORDER_GLOW, hover_color=C_ACCENT_BLUE, anchor="w", command=lambda x=t: self.switch_table(x)).pack(fill="x", padx=20, pady=5)
        
        self.main_area = ctk.CTkFrame(self, fg_color="transparent"); self.main_area.grid(row=0, column=1, sticky="nsew", padx=20, pady=20)
        self.main_area.grid_rowconfigure(2, weight=1); self.main_area.grid_columnconfigure(0, weight=1)
        
        self.tools = ModernCard(self.main_area, fg_color=C_BG_CARD); self.tools.grid(row=0, column=0, sticky="ew", pady=(0, 20))
        self.lbl_table = ctk.CTkLabel(self.tools, text="ORDERS", font=FONT_SUBHEAD, text_color=C_ACCENT_CYAN); self.lbl_table.pack(side="left", padx=20, pady=15)
        self.sync_btn = ctk.CTkButton(self.tools, text="⚡ APLICAR (0)", fg_color=C_BG_SIDEBAR, state="disabled", command=self.apply_changes); self.sync_btn.pack(side="right", padx=20, pady=10)
        ctk.CTkButton(self.tools, text="🔄 REFRESCAR", fg_color=C_ACCENT_BLUE, width=100, command=self.load_data).pack(side="right", padx=5)
        
        self.header_frame = ctk.CTkFrame(self.main_area, fg_color=C_BG_SIDEBAR, height=40); self.header_frame.grid(row=1, column=0, sticky="ew", pady=(0, 5))
        self.scroll = ctk.CTkScrollableFrame(self.main_area, fg_color="transparent"); self.scroll.grid(row=2, column=0, sticky="nsew")
        
        self.sql_frame = ModernCard(self.main_area, fg_color=C_BG_CARD); self.sql_frame.grid(row=3, column=0, sticky="ew", pady=(20, 0))
        self.sql_entry = ctk.CTkEntry(self.sql_frame, placeholder_text="SELECT * FROM...", height=40, font=FONT_MONO, border_color=C_BORDER_GLOW); self.sql_entry.pack(side="left", fill="x", expand=True, padx=15, pady=15)
        ctk.CTkButton(self.sql_frame, text="EJECUTAR", fg_color=C_WARNING, text_color="black", command=self.run_sql).pack(side="right", padx=15)
        self.load_data()

    def switch_table(self, t): self.current_table = t; self.lbl_table.configure(text=t.upper()); self.load_data()
    def load_data(self):
        if not PYMYSQL_INSTALLED: return
        for w in self.scroll.winfo_children(): w.destroy()
        for w in self.header_frame.winfo_children(): w.destroy()
        try:
            conn = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
            with conn.cursor() as c:
                c.execute(f"DESCRIBE {self.current_table}"); cols = [x['Field'] for x in c.fetchall()]
                for col in cols: ctk.CTkLabel(self.header_frame, text=col[:15].upper(), width=120, font=("Arial", 10, "bold"), text_color=C_ACCENT_CYAN).pack(side="left", padx=2)
                c.execute(f"SELECT * FROM {self.current_table} LIMIT 100"); rows = c.fetchall()
                for i, r in enumerate(rows):
                    bg = C_BG_CARD if i % 2 == 0 else C_BG_CARD_HOVER
                    f = ctk.CTkFrame(self.scroll, fg_color=bg, height=35); f.pack(fill="x", pady=1)
                    rid = r.get('id') or r.get('order_id')
                    for col in cols:
                        val = str(r[col]) if r[col] is not None else "NULL"
                        fg = C_TEXT_MUTED if val == "NULL" else C_TEXT_WHITE
                        e = ctk.CTkEntry(f, width=120, fg_color="transparent", border_width=0, font=FONT_MONO, text_color=fg)
                        e.insert(0, val); e.pack(side="left", padx=2)
                        e.bind("<Return>", lambda ev, row=rid, c=col, ent=e: self.update_cell(row, c, ent.get()))
        except: pass
        finally: 
            if 'conn' in locals(): conn.close()
    def update_cell(self, rid, col, val):
        try:
            conn = pymysql.connect(**DB_CONFIG)
            pk = "order_id" if "specs" in self.current_table else "id"
            with conn.cursor() as c: c.execute(f"UPDATE {self.current_table} SET {col}=%s WHERE {pk}=%s", (val, rid))
            conn.commit(); conn.close(); self.dirty_ids.add(rid); self.sync_btn.configure(state="normal", text=f"⚡ APLICAR ({len(self.dirty_ids)})", fg_color=C_WARNING); self.load_data()
        except: pass
    def apply_changes(self): self.dirty_ids.clear(); self.sync_btn.configure(state="disabled", text="SINCRONIZADO", fg_color=C_BG_SIDEBAR); messagebox.showinfo("Sync", "Guardado.")
    def run_sql(self):
        try:
            conn = pymysql.connect(**DB_CONFIG); c=conn.cursor(); c.execute(self.sql_entry.get()); conn.commit(); conn.close(); self.sql_entry.delete(0,'end'); self.load_data()
        except Exception as e: messagebox.showerror("Error", str(e))

# ================= CLASE PRINCIPAL OKTOPUS =================
class OktopusApp(ctk.CTk):
    def __init__(self):
        super().__init__()
        self.title("🐙 Oktopus Enterprise - Master Control (SUDO ENABLED)")
        self.geometry("1600x950")
        self.configure(fg_color=C_BG_MAIN)
        self.running = True
        self.protocol("WM_DELETE_WINDOW", self.on_close)
        self.attributes("-alpha", 0.0) 

        self.worker_processes = {}
        self.worker_widgets = {}
        self.api_process = None 
        self.db_window = None
        self.status_indicators = {}
        self.client_cards = {} 
        self.frames = {}
        self.menu_btns = {}

        self.start_api_server()
        self.build_splash_screen()

    def start_api_server(self):
        try:
            if self.api_process: return
            script = os.path.join(CURRENT_DIR, "api_sylo.py")
            self.api_log_file = open("/tmp/sylo_api.log", "w")
            self.api_process = subprocess.Popen(
                [sys.executable, "-u", script], 
                stdout=self.api_log_file, 
                stderr=subprocess.STDOUT,
                cwd=BASE_DIR, 
                preexec_fn=os.setsid
            )
            print(f"🧠 [OKTOPUS] API Arrancada (PID: {self.api_process.pid})")
        except Exception as e:
            print(f"Error crítico API: {e}")

    def stop_api_server(self):
        if self.api_process:
            try:
                os.killpg(os.getpgid(self.api_process.pid), signal.SIGTERM)
                self.api_process = None
                self.log_to_console("SISTEMA", "API Detenida manualmente.", C_DANGER)
            except: pass

    # ================= SPLASH =================
    def build_splash_screen(self):
        self.splash_frame = ctk.CTkFrame(self, fg_color=C_BG_MAIN); self.splash_frame.pack(fill="both", expand=True)
        try:
            if PIL_INSTALLED and os.path.exists(LOGO_PATH):
                pil_image = Image.open(LOGO_PATH)
                aspect = pil_image.width / pil_image.height; new_h = 350; new_w = int(new_h * aspect)
                pil_image = pil_image.resize((new_w, new_h), Image.Resampling.LANCZOS)
                self.logo_img = ctk.CTkImage(light_image=pil_image, dark_image=pil_image, size=(new_w, new_h))
                ctk.CTkLabel(self.splash_frame, image=self.logo_img, text="").pack(pady=(80, 20))
            else:
                ctk.CTkLabel(self.splash_frame, text="SYLO", font=("Montserrat", 90, "bold"), text_color=C_ACCENT_CYAN).pack(pady=(150, 10))
        except: pass

        ctk.CTkLabel(self.splash_frame, text="OKTOPUS KERNEL V23", font=FONT_HEAD, text_color=C_TEXT_WHITE).pack(pady=(0, 30))
        self.progress_bar = ctk.CTkProgressBar(self.splash_frame, width=500, height=10, corner_radius=5, progress_color=C_ACCENT_CYAN, fg_color=C_BG_CARD)
        self.progress_bar.set(0); self.progress_bar.pack(pady=10)
        self.status_lbl = ctk.CTkLabel(self.splash_frame, text="Iniciando...", font=FONT_MONO, text_color=C_TEXT_MUTED); self.status_lbl.pack()

        self.attributes("-alpha", 1.0)
        threading.Thread(target=self.loading_sequence, daemon=True).start()

    def update_splash(self, val, msg): self.progress_bar.set(val); self.status_lbl.configure(text=msg)
    def loading_sequence(self):
        steps = [(0.1, "Cargando módulos..."), (0.5, "Verificando base de datos..."), (0.9, "Sincronizando..."), (1.0, "Listo.")]
        for val, msg in steps: time.sleep(0.4); self.after(0, lambda v=val, m=msg: self.update_splash(v, m))
        time.sleep(0.5); self.after(0, self.transition_to_main)

    def transition_to_main(self):
        self.splash_frame.destroy(); self.build_main_ui()
        self.check_system_health(); self.start_log_reader(); self.refresh_clients_loop(); self.calculate_finance()
        self.log_to_console("SISTEMA", "Oktopus Online. Logs Activos.", C_SUCCESS)

    # ================= UI PRINCIPAL =================
    def build_main_ui(self):
        self.grid_columnconfigure(1, weight=1); self.grid_rowconfigure(0, weight=1)
        self.sidebar = ctk.CTkFrame(self, width=280, fg_color=C_BG_SIDEBAR, corner_radius=0); self.sidebar.grid(row=0, column=0, sticky="nsew")
        
        ctk.CTkLabel(self.sidebar, text="🐙 OKTOPUS", font=("Montserrat", 30, "bold"), text_color=C_ACCENT_CYAN).pack(pady=(30, 5))
        ctk.CTkLabel(self.sidebar, text="V23 ROOT ACCESS", font=FONT_MONO, text_color=C_ACCENT_BLUE).pack(pady=(0, 40))

        menu = {"DASHBOARD": "📊", "CEREBRO API": "🧠", "INFRAESTRUCTURA": "🏗️", "FINANZAS": "💰", "LOGS GLOBALES": "📜"}
        for s, icon in menu.items():
            btn = ctk.CTkButton(self.sidebar, text=f"  {icon}  {s}", height=50, fg_color="transparent", anchor="w", 
                                font=FONT_SUBHEAD, hover_color=C_BG_CARD_HOVER, command=lambda x=s: self.show_tab(x))
            btn.pack(fill="x", padx=10, pady=5); self.menu_btns[s] = btn

        f_side = ctk.CTkFrame(self.sidebar, fg_color="transparent"); f_side.pack(side="bottom", fill="x", pady=20, padx=10)
        ctk.CTkButton(f_side, text="DATA STUDIO", fg_color=C_ACCENT_BLUE, font=FONT_SUBHEAD, height=40, command=self.open_db_explorer).pack(fill="x", pady=5)
        ctk.CTkButton(f_side, text="HIBERNAR TODO", fg_color=C_DANGER, font=FONT_SUBHEAD, height=40, command=self.hibernate_all).pack(fill="x", pady=5)

        self.main_container = ctk.CTkFrame(self, fg_color=C_BG_MAIN); self.main_container.grid(row=0, column=1, sticky="nsew", padx=20, pady=20)
        self.main_container.grid_rowconfigure(0, weight=1); self.main_container.grid_columnconfigure(0, weight=1)

        self.create_dashboard_tab(); self.create_api_tab(); self.create_infra_tab(); self.create_finance_tab(); self.create_console_tab()
        self.show_tab("DASHBOARD")

    def show_tab(self, name):
        for n, f in self.frames.items(): f.grid_forget()
        self.frames[name].grid(row=0, column=0, sticky="nsew")
        for n, b in self.menu_btns.items(): b.configure(fg_color=C_ACCENT_CYAN if n == name else "transparent", text_color=C_BG_MAIN if n == name else C_TEXT_MUTED)

    # --- TABS ---
    def create_dashboard_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent"); self.frames["DASHBOARD"] = f
        
        kpi = ctk.CTkFrame(f, fg_color="transparent"); kpi.pack(fill="x", pady=10)
        self.kpi_income = self.create_kpi(kpi, "INGRESOS", "0.00 €", C_SUCCESS, "💵")
        self.kpi_cost = self.create_kpi(kpi, "COSTE", "0.00 €", C_WARNING, "📉")
        self.kpi_profit = self.create_kpi(kpi, "BENEFICIO", "0.00 €", C_ACCENT_CYAN, "📈")
        self.kpi_active = self.create_kpi(kpi, "ACTIVOS", "0", C_ACCENT_PURPLE, "👥")

        ctk.CTkLabel(f, text="Servicios", font=FONT_HEAD, text_color=C_TEXT_WHITE).pack(anchor="w", pady=(20, 10))
        st = ModernCard(f); st.pack(fill="x", ipady=20, padx=5)
        
        keys = ["API GATEWAY", "WEB SERVER", "DATABASE", "OPERATOR", "ORCHESTRATOR", "BRAIN", "DNS SERVER", "KERNEL GHOST"]
        for i, s in enumerate(keys):
            sf = ctk.CTkFrame(st, fg_color="transparent"); sf.pack(side="left", expand=True)
            led = LEDIndicator(sf, s); led.pack()
            ctk.CTkLabel(sf, text=s, font=("Roboto", 10, "bold"), text_color=C_TEXT_MUTED).pack()
            self.status_indicators[s] = led

        ctk.CTkLabel(f, text="Workers & Brain & DNS", font=FONT_HEAD, text_color=C_TEXT_WHITE).pack(anchor="w", pady=(30, 10))
        wf = ModernCard(f); wf.pack(fill="x", ipady=10)
        self.create_api_worker_ctrl(wf)
        ctk.CTkFrame(wf, fg_color=C_BORDER_GLOW, height=1).pack(fill="x", padx=20, pady=5)
        
        # --- WORKERS (INCLUYE DNS) ---
        # --- WORKERS (INCLUYE DNS) ---
        self.create_worker_ctrl(wf, "operator_sylo.py", "OPERATOR")
        self.create_worker_ctrl(wf, "orchestrator_sylo.py", "ORCHESTRATOR")
        self.create_worker_ctrl(wf, "sylo_brain.py", "BRAIN", teleport=True) # Teleport habilitado para BRAIN
        self.create_worker_ctrl(wf, "sylo_dns.py", "DNS SERVER")
        self.create_worker_ctrl(wf, "ghost_monitor.py", "KERNEL GHOST") # Nuevo servicio eBPF

    def create_kpi(self, p, t, v, c, i):
        fr = ModernCard(p, hover_effect=True); fr.pack(side="left", expand=True, fill="both", padx=5, ipady=5)
        top = ctk.CTkFrame(fr, fg_color="transparent"); top.pack(fill="x", padx=15, pady=(15,5))
        ctk.CTkLabel(top, text=i, font=("Arial", 20)).pack(side="left")
        ctk.CTkLabel(top, text=t, font=FONT_BODY, text_color=C_TEXT_MUTED).pack(side="left", padx=10)
        l = ctk.CTkLabel(fr, text=v, font=FONT_KPI_VAL, text_color=c); l.pack(anchor="w", padx=15, pady=(0,15))
        return l

    def create_api_worker_ctrl(self, p):
        f = ctk.CTkFrame(p, fg_color="transparent"); f.pack(fill="x", padx=15, pady=8)
        left = ctk.CTkFrame(f, fg_color="transparent"); left.pack(side="left")
        led = LEDIndicator(left, "API GATEWAY"); led.pack(side="left")
        ctk.CTkLabel(left, text="API GATEWAY", font=FONT_BODY, text_color=C_TEXT_WHITE).pack(side="left", padx=10)
        right = ctk.CTkFrame(f, fg_color="transparent"); right.pack(side="right")
        st = ctk.CTkLabel(right, text="OFFLINE", font=FONT_MONO, text_color=C_DANGER, width=70); st.pack(side="left", padx=10)
        ctk.CTkButton(right, text="▶", width=30, fg_color=C_SUCCESS, command=self.start_api_server).pack(side="left", padx=2)
        ctk.CTkButton(right, text="■", width=30, fg_color=C_DANGER, command=self.stop_api_server).pack(side="left", padx=2)
        self.worker_widgets["API_SRV"] = {"led": led, "txt": st}

    def create_worker_ctrl(self, p, s, l, teleport=False):
        f = ctk.CTkFrame(p, fg_color="transparent"); f.pack(fill="x", padx=15, pady=8)
        left = ctk.CTkFrame(f, fg_color="transparent"); left.pack(side="left")
        led = LEDIndicator(left, l); led.pack(side="left")
        ctk.CTkLabel(left, text=l, font=FONT_BODY, text_color=C_TEXT_WHITE).pack(side="left", padx=10)
        right = ctk.CTkFrame(f, fg_color="transparent"); right.pack(side="right")
        st = ctk.CTkLabel(right, text="OFFLINE", font=FONT_MONO, text_color=C_DANGER, width=70); st.pack(side="left", padx=10)
        
        # Teleport Controls (Freeze/Thaw)
        if teleport:
            ctk.CTkButton(right, text="❄️", width=30, fg_color=C_ACCENT_CYAN, command=lambda: self.teleport_action("FREEZE", s)).pack(side="left", padx=2)
            ctk.CTkButton(right, text="🔥", width=30, fg_color=C_WARNING, command=lambda: self.teleport_action("THAW", s)).pack(side="left", padx=2)

        ctk.CTkButton(right, text="▶", width=30, fg_color=C_SUCCESS, command=lambda: self.start_worker(s)).pack(side="left", padx=2)
        ctk.CTkButton(right, text="■", width=30, fg_color=C_DANGER, command=lambda: self.stop_worker(s)).pack(side="left", padx=2)
        self.worker_widgets[s] = {"led": led, "txt": st}
    
    def teleport_action(self, action, script_name):
        pid = self.find_process(script_name)
        
        if action == "FREEZE":
            if not pid:
                messagebox.showerror("Error", f"{script_name} no está corriendo, no se puede congelar.")
                return
            
            dialog = ctk.CTkInputDialog(text=f"Congelando PID {pid}. Password SUDO:", title="Teleport Freeze")
            pwd = dialog.get_input()
            if not pwd: return

            cmd = ["sudo", "-S", sys.executable, os.path.join(WORKER_DIR, "teleport", "freeze_service.py"), str(pid)]
            threading.Thread(target=self._run_teleport_cmd, args=(cmd, pwd, "Conjelado")).start()

        elif action == "THAW":
            # Para restaurar, necesitamos el PID o ID original que se usó como carpeta.
            # Asumimos que el usuario sabe qué PID busca restaurar, o podríamos buscar en la carpeta checkpoints.
            # Para simplificar UX, pediremos el ID.
            dialog = ctk.CTkInputDialog(text="ID del proceso a restaurar (PID original):", title="Teleport Thaw")
            ident = dialog.get_input()
            if not ident: return
            
            pwd_dialog = ctk.CTkInputDialog(text="Password SUDO:", title="Sudo Required")
            pwd = pwd_dialog.get_input()
            if not pwd: return

            cmd = ["sudo", "-S", sys.executable, os.path.join(WORKER_DIR, "teleport", "thaw_service.py"), ident]
            threading.Thread(target=self._run_teleport_cmd, args=(cmd, pwd, "Restaurado")).start()

    def _run_teleport_cmd(self, cmd, pwd, label):
        try:
            p = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            out, err = p.communicate(input=pwd + "\n")
            if p.returncode == 0:
                self.log_to_console("TELEPORT", f"Operación Exitosa: {label}", C_SUCCESS)
            else:
                self.log_to_console("TELEPORT", f"Fallo: {err}", C_DANGER)
        except Exception as e:
            self.log_to_console("TELEPORT", f"Error Ejecucción: {e}", C_DANGER)

    # --- API TAB ---
    def create_api_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent"); self.frames["CEREBRO API"] = f
        h = ctk.CTkFrame(f, fg_color="transparent"); h.pack(fill="x", pady=20)
        ctk.CTkLabel(h, text="🧠 Monitor de API Gateway", font=FONT_HEAD, text_color=C_ACCENT_CYAN).pack(side="left")
        self.api_status_ui = ctk.CTkLabel(h, text="● OFFLINE", text_color=C_DANGER, font=FONT_SUBHEAD); self.api_status_ui.pack(side="right")
        
        con = ModernCard(f, border_color=C_ACCENT_CYAN); con.pack(fill="both", expand=True)
        self.api_console = ctk.CTkTextbox(con, font=FONT_MONO, fg_color=C_CONSOLE_BG, text_color=C_API_TXT, wrap="word", border_width=0)
        self.api_console.pack(fill="both", expand=True, padx=2, pady=2)
        # Configurar tags para NeuroShield
        self.api_console.tag_config("SHIELD_WARN", foreground=C_WARNING)
        self.api_console.tag_config("SHIELD_CRIT", foreground=C_DANGER)
        
        self.api_console.insert("end", "--- ESPERANDO CONEXIONES AL CEREBRO ---\n"); self.api_console.configure(state="disabled")
        ctk.CTkButton(f, text="LIMPIAR LOG", fg_color=C_BG_CARD, command=lambda: self.clear_console(self.api_console)).pack(anchor="e", pady=10)

    # --- INFRA ---
    def create_infra_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent"); self.frames["INFRAESTRUCTURA"] = f
        h = ctk.CTkFrame(f, fg_color="transparent"); h.pack(fill="x", pady=20)
        ctk.CTkLabel(h, text="🏗️ Infraestructura", font=FONT_HEAD, text_color=C_ACCENT_BLUE).pack(side="left")
        ctk.CTkButton(h, text="🔄 ESCANEAR", width=120, fg_color=C_ACCENT_BLUE, command=self.force_refresh_infra).pack(side="right")
        self.infra_area = ctk.CTkScrollableFrame(f, fg_color="transparent"); self.infra_area.pack(fill="both", expand=True)

    # --- FINANZAS ---
    def create_finance_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent"); self.frames["FINANZAS"] = f
        h = ctk.CTkFrame(f, fg_color="transparent"); h.pack(fill="x", pady=20)
        ctk.CTkLabel(h, text="💰 Finanzas", font=FONT_HEAD, text_color=C_SUCCESS).pack(side="left")
        ctk.CTkButton(h, text="🔄 REFRESCAR", font=FONT_BODY, fg_color=C_SUCCESS, command=self.calculate_finance).pack(side="right")
        self.finance_scroll = ctk.CTkScrollableFrame(f, fg_color="transparent"); self.finance_scroll.pack(fill="both", expand=True)
        hh = ctk.CTkFrame(self.finance_scroll, fg_color=C_BG_SIDEBAR, height=40); hh.pack(fill="x")
        for c in ["ID/CLIENTE", "PLAN", "CPU/RAM", "PRECIO", "COSTE", "MARGEN"]: ctk.CTkLabel(hh, text=c, font=("Roboto", 11, "bold"), width=120).pack(side="left", padx=10)

    # --- LOGS GLOBALES ---
    def create_console_tab(self):
        f = ctk.CTkFrame(self.main_container, fg_color="transparent"); self.frames["LOGS GLOBALES"] = f
        h = ctk.CTkFrame(f, fg_color="transparent"); h.pack(fill="x", pady=20)
        ctk.CTkLabel(h, text="📜 Bitácora Global", font=FONT_HEAD, text_color=C_WARNING).pack(side="left")
        ctk.CTkButton(h, text="🗑️ LIMPIAR", fg_color=C_BG_CARD, command=lambda: self.clear_console(self.master_console)).pack(side="right")
        
        con = ModernCard(f, border_color=C_WARNING); con.pack(fill="both", expand=True)
        self.master_console = ctk.CTkTextbox(con, font=FONT_MONO, fg_color=C_CONSOLE_BG, text_color=C_SYS_TXT, wrap="word", border_width=0)
        self.master_console.pack(fill="both", expand=True, padx=2, pady=2)
        self.master_console.tag_config("SHIELD_WARN", foreground=C_WARNING)
        self.master_console.tag_config("SHIELD_CRIT", foreground=C_DANGER)
        self.master_console.configure(state="disabled")

    def clear_console(self, w): w.configure(state="normal"); w.delete("1.0", "end"); w.configure(state="disabled")
    def log_to_console(self, source, message, color=None):
        if not self.running: return
        try:
            self.master_console.configure(state="normal")
            ts = datetime.now().strftime('%H:%M:%S'); tag = f"[{source.upper()}]"
            
            # Detect NeuroShield tags
            tags = "normal"
            if "SYLO_NEURO_SHIELD" in source or "SECURITY BREACH" in message:
                tags = "SHIELD_CRIT"
            elif "THREAT DETECTED" in message:
                tags = "SHIELD_WARN"

            self.master_console.insert("end", f"[{ts}] {tag} {message}\n", tags); self.master_console.see("end"); self.master_console.configure(state="disabled")
        except: pass

    # ================= LOGIC LOOPS =================
    def start_log_reader(self):
        def _read():
            api_cursor = 0; worker_cursors = {}
            while self.running:
                # API
                if os.path.exists("/tmp/sylo_api.log"):
                    try:
                        with open("/tmp/sylo_api.log", "r") as f:
                            f.seek(api_cursor); new = f.read()
                            if new: 
                                api_cursor = f.tell()
                                # Highlight logic for API console
                                if "SYLO_NEURO_SHIELD" in new:
                                    if "CRITICAL" in new or "SECURITY BREACH" in new: self.append_widget(self.api_console, new, "SHIELD_CRIT")
                                    elif "WARNING" in new: self.append_widget(self.api_console, new, "SHIELD_WARN")
                                    else: self.append_widget(self.api_console, new)
                                else:
                                    self.append_widget(self.api_console, new)
                    except: pass
                # WORKERS
                for s in self.worker_widgets.keys():
                    if s == "API_SRV": continue
                    path = f"/tmp/sylo_{s}.log"
                    if not path: continue
                    if s not in worker_cursors: worker_cursors[s] = 0
                    if os.path.exists(path):
                        # FIX: Si el archivo se truncó (reinicio de worker), resetear cursor
                        if os.path.getsize(path) < worker_cursors[s]:
                            worker_cursors[s] = 0
                            
                        try:
                            with open(path, "r") as f:
                                f.seek(worker_cursors[s]); lines = f.readlines()
                                if lines:
                                    worker_cursors[s] = f.tell()
                                    for l in lines:
                                        if l.strip():
                                            src = s.replace(".py","").replace("_sylo","").upper()
                                            self.after(0, lambda src=src, msg=l.strip(): self.log_to_console(src, msg))
                        except: pass
                time.sleep(0.5)
        threading.Thread(target=_read, daemon=True).start()

    def append_widget(self, w, txt, tags=None): 
        w.configure(state="normal")
        w.insert("end", txt, tags)
        w.see("end")
        w.configure(state="disabled")

    def check_system_health(self):
        if not self.running: return
        api_ok = False
        try: api_ok = requests.get("http://127.0.0.1:8001/", timeout=0.8).status_code == 200
        except: pass
        c = C_SUCCESS if api_ok else C_DANGER
        self.status_indicators["API GATEWAY"].set_status(api_ok)
        self.api_status_ui.configure(text="● ONLINE" if api_ok else "● OFFLINE", text_color=c)
        if "API_SRV" in self.worker_widgets:
            self.worker_widgets["API_SRV"]["led"].set_status(api_ok)
            self.worker_widgets["API_SRV"]["txt"].configure(text="ONLINE" if api_ok else "OFFLINE", text_color=c)

        db_ok=False; web_ok=False
        try: conn = pymysql.connect(**DB_CONFIG); conn.close(); db_ok = True
        except: pass
        self.status_indicators["DATABASE"].set_status(db_ok)
        try: out = subprocess.check_output("docker ps --format '{{.Names}}'", shell=True, text=True); web_ok = "sylo-web" in out
        except: pass
        self.status_indicators["WEB SERVER"].set_status(web_ok)

        km = {
            "operator_sylo.py": "OPERATOR", 
            "orchestrator_sylo.py": "ORCHESTRATOR", 
            "sylo_brain.py": "BRAIN",
            "sylo_dns.py": "DNS SERVER",
            "ghost_monitor.py": "KERNEL GHOST"  # <--- INTEGRACION VISUAL
        }
        for s, w in self.worker_widgets.items():
            if s == "API_SRV": continue
            on = self.find_process(s) is not None
            wc = C_SUCCESS if on else C_DANGER
            w["led"].set_status(on); w["txt"].configure(text="ONLINE" if on else "OFFLINE", text_color=wc)
            if s in km and km[s] in self.status_indicators: self.status_indicators[km[s]].set_status(on)
        self.after(2000, self.check_system_health)

    def calculate_finance(self):
        if not PYMYSQL_INSTALLED: return
        try:
            for w in self.finance_scroll.winfo_children():
                if isinstance(w, ctk.CTkFrame) and w != self.finance_scroll.winfo_children()[0]: w.destroy()
            conn = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor)
            with conn.cursor() as c: c.execute("SELECT o.id, u.username, p.name as plan, p.price, COALESCE(os.cpu_cores, p.cpu_cores) as cpu, COALESCE(os.ram_gb, p.ram_gb) as ram FROM orders o JOIN users u ON o.user_id=u.id JOIN plans p ON o.plan_id=p.id LEFT JOIN order_specs os ON o.id=os.order_id WHERE o.status IN ('active', 'suspended')")
            rows = c.fetchall(); conn.close()
            tr, tc = 0, 0
            for r in rows:
                rev = float(r['price']); cpu = int(r['cpu'] or 1); ram = int(r['ram'] or 1)
                cost = 2.0 + (cpu * 1.5) + (ram * 0.5); m = rev - cost; tr += rev; tc += cost
                rw = ctk.CTkFrame(self.finance_scroll, fg_color=C_BG_CARD, height=40); rw.pack(fill="x", pady=2)
                vals = [f"#{r['id']} {r['username']}", r['plan'], f"{cpu}v/{ram}G"]
                for v in vals: ctk.CTkLabel(rw, text=str(v), width=120, anchor="w", font=FONT_BODY).pack(side="left", padx=5)
                ctk.CTkLabel(rw, text=f"{rev:.2f}€", width=110, text_color=C_SUCCESS, font=FONT_MONO).pack(side="left", padx=5)
                ctk.CTkLabel(rw, text=f"{cost:.2f}€", width=110, text_color=C_WARNING, font=FONT_MONO).pack(side="left", padx=5)
                ctk.CTkLabel(rw, text=f"{m:+.2f}€", width=110, text_color=C_ACCENT_CYAN if m>0 else C_DANGER, font=("Roboto", 12, "bold")).pack(side="left", padx=5)
            self.kpi_income.configure(text=f"{tr:.2f} €"); self.kpi_cost.configure(text=f"{tc:.2f} €"); self.kpi_profit.configure(text=f"{(tr-tc):.2f} €"); self.kpi_active.configure(text=str(len(rows)))
        except: pass

    # Utils
    def find_process(self, n):
        for p in psutil.process_iter(['pid','cmdline']):
            try: 
                if p.info['cmdline'] and n in ' '.join(p.info['cmdline']): return p.info['pid']
            except: pass
        return None

    def start_worker(self, s):
        # --- 🔥 LÓGICA ESPECIAL PARA DNS Y GHOST (REQUIERE SUDO) 🔥 ---
        if s in ["sylo_dns.py", "ghost_monitor.py"]:
            dialog = ctk.CTkInputDialog(text=f"El servicio {s} requiere permisos de ROOT.\nIntroduce contraseña de SUDO:", title="Autenticación Requerida")
            pwd = dialog.get_input()
            if not pwd: return
            
            try:
                l = open(f"/tmp/sylo_{s}.log", "w")
                
                # FIX: ghost_monitor necesita librerías del sistema (BCC), no del venv.
                python_exec = "/usr/bin/python3" if s == "ghost_monitor.py" else sys.executable
                
                # Ejecutamos sudo -S (lee password de stdin)
                proc = subprocess.Popen(
                    ["sudo", "-S", python_exec, "-u", os.path.join(WORKER_DIR, s)],
                    stdin=subprocess.PIPE,
                    stdout=l,
                    stderr=subprocess.STDOUT,
                    text=True # Importante para enviar string
                )
                proc.stdin.write(pwd + "\n")
                proc.stdin.flush()
                self.log_to_console("SISTEMA", f"Iniciando {s} con privilegios elevados...", C_ACCENT_PURPLE)
            except Exception as e:
                self.log_to_console("ERROR", f"Fallo al elevar {s}: {e}", C_DANGER)
            return

        # --- LÓGICA ESTÁNDAR ---
        try:
            l = open(f"/tmp/sylo_{s}.log", "w")
            # Usamos -u para logs en tiempo real
            subprocess.Popen([sys.executable, "-u", os.path.join(WORKER_DIR, s)], stdout=l, stderr=subprocess.STDOUT)
        except: pass

    def stop_worker(self, s):
        pid = self.find_process(s); 
        if pid: 
            # --- 🔥 LÓGICA ESPECIAL PARA DNS Y GHOST (REQUIERE SUDO) 🔥 ---
            if s in ["sylo_dns.py", "ghost_monitor.py"]:
                dialog = ctk.CTkInputDialog(text=f"Detener {s} requiere permisos de ROOT.\nIntroduce contraseña de SUDO:", title="Autenticación Requerida")
                pwd = dialog.get_input()
                if not pwd: return

                try:
                    # Ejecutamos sudo -S kill <pid>
                    cmd = ["sudo", "-S", "kill", str(pid)]
                    proc = subprocess.Popen(
                        cmd,
                        stdin=subprocess.PIPE,
                        stdout=subprocess.PIPE,
                        stderr=subprocess.PIPE,
                        text=True
                    )
                    out, err = proc.communicate(input=pwd + "\n")
                    
                    if proc.returncode == 0:
                        self.log_to_console("SISTEMA", f"{s} detenido correctamente.", C_WARNING)
                    else:
                        self.log_to_console("ERROR", f"Fallo al detener {s}: {err}", C_DANGER)
                except Exception as e:
                    self.log_to_console("ERROR", f"Excepción al detener {s}: {e}", C_DANGER)
            else:
                try:
                    os.kill(pid, signal.SIGTERM)
                    self.log_to_console("SISTEMA", f"{s} detenido.", C_WARNING)
                except Exception as e:
                    self.log_to_console("ERROR", f"Error deteniendo {s}: {e}", C_DANGER)

    def kill_machine_direct(self, name):
        cid = name.lower().replace("sylo-cliente-", "").replace("cliente", "")
        if not messagebox.askyesno("CONFIRM", f"Del {cid}?"): return
        self.log_to_console("ADMIN", f"Deleting #{cid}...", C_DANGER)
        threading.Thread(target=self._api_kill, args=(cid, name)).start()
    def _api_kill(self, cid, name):
        try:
            r = requests.post(f"{API_URL}/accion", json={"id_cliente": int(cid), "accion": "TERMINATE"})
            if r.status_code == 200: self.after(0, lambda: [self._remove_card(name), self.log_to_console("SYSTEM", "Done.", C_SUCCESS)])
        except: pass
    def _remove_card(self, n):
        if n in self.client_cards: self.client_cards[n]['frame'].destroy(); del self.client_cards[n]
    def open_webshell(self, n):
        self.log_to_console("SEC", f"Opening Bastion Terminal for {n}...", C_ACCENT_CYAN)
        w = SSHTerminalWindow(self, n)
        w.focus()
        # subprocess.Popen(f"gnome-terminal -- bash -c 'minikube -p {n} kubectl -- exec -it $(minikube -p {n} kubectl -- get pods -o name | head -1) -- /bin/sh'", shell=True)
    def edit_config(self, n):
        self.log_to_console("ADMIN", f"Config {n}", C_WARNING)
        subprocess.Popen(f"gnome-terminal -- bash -c 'minikube -p {n} kubectl -- edit cm web-content-config || minikube -p {n} kubectl -- edit cm custom-web-content'", shell=True)
    def hibernate_all(self): 
        self.log_to_console("EMERGENCY", "Hibernating...", C_DANGER)
        threading.Thread(target=lambda: subprocess.run("minikube stop --all", shell=True)).start()
    def open_db_explorer(self): self.db_window = DatabaseManager(self) if self.db_window is None or not self.db_window.winfo_exists() else self.db_window.focus()
    def on_close(self):
        self.running = False; self.stop_api_server()
        try: [p.terminate() for p in self.worker_processes.values()]
        except: pass
        self.destroy(); sys.exit(0)
    
    def open_native_shell(self, container_id):
        """Abre una terminal nativa (gnome-terminal) conectada al contenedor"""
        import subprocess
        import shutil
        
        # Comando para conectar
        cmd = f"docker exec -it {container_id} bash"
        
        try:
            # Detectar terminal disponible
            if shutil.which("gnome-terminal"):
                subprocess.Popen(["gnome-terminal", f"--title=Sylo Bastion - {container_id}", "--", "bash", "-c", f"{cmd}; exec bash"])
            elif shutil.which("xterm"):
                subprocess.Popen(["xterm", "-T", f"Sylo Bastion - {container_id}", "-e", f"{cmd}; exec bash"])
            elif shutil.which("konsole"):
                 subprocess.Popen(["konsole", "-e", "bash", "-c", f"{cmd}; exec bash"])
            else:
                # Fallback genérico
                subprocess.Popen(["x-terminal-emulator", "-e", f"bash -c '{cmd}; exec bash'"])
                
        except Exception as e:
            print(f"Error lanzando terminal nativa: {e}")

    # ================= SSH CLIENT WINDOW (LEGACY) =================
    # Mantengo esto por si se quiere revertir, pero ya no se usa.
    def open_webshell(self, container_id):
        SSHTerminalWindow(self, container_id)

    def force_refresh_infra(self): threading.Thread(target=self._manual_scan).start()
    def refresh_clients_loop(self): self._manual_scan(); self.after(5000, self.refresh_clients_loop)
    def _manual_scan(self):
        try:
            out = subprocess.check_output("minikube profile list -o json", shell=True, text=True)
            idx = out.find('{'); out = out[idx:] if idx!=-1 else "{}"
            data = json.loads(out)
            self.after(0, lambda: self.update_cards(data.get('valid', []) + data.get('invalid', [])))
        except: pass
    def update_cards(self, profs):
        found = set()
        for p in profs:
            n = p['Name']
            if "sylo-cliente" in n or "Cliente" in n:
                found.add(n); 
                if n not in self.client_cards: self.create_card(n)
        for n in list(self.client_cards.keys()):
            if n not in found: self._remove_card(n)
    def create_card(self, n):
        c = ModernCard(self.infra_area, hover_effect=True, border_color=C_SUCCESS)
        c.pack(fill="x", padx=5, pady=5)
        ctk.CTkLabel(c, text="🟢", font=("Arial",20)).pack(side="left", padx=15)
        ctk.CTkLabel(c, text=n.upper(), font=FONT_SUBHEAD).pack(side="left")
        ctk.CTkButton(c, text="ELIMINAR", fg_color=C_DANGER, width=80, command=lambda: self.kill_machine_direct(n)).pack(side="right", padx=10)
        ctk.CTkButton(c, text="⚙️ CONF", fg_color=C_ACCENT_BLUE, width=80, command=lambda: self.edit_config(n)).pack(side="right", padx=5)
        ctk.CTkButton(c, text=">_ SHELL", fg_color=C_ACCENT_PURPLE, width=80, command=lambda: self.open_native_shell(n)).pack(side="right", padx=5)
        self.client_cards[n] = {'frame': c}

# ================= SSH CLIENT WINDOW =================
class SSHTerminalWindow(ctk.CTkToplevel):
    def __init__(self, parent, container_id):
        super().__init__(parent)
        self.title(f"Sylo Bastion - {container_id}")
        self.geometry("900x600")
        self.configure(fg_color="#000000")
        self.container_id = container_id
        
        # Terminal Display
        # FIX: State normal para recibir foco, y "break" para interceptar input
        # Terminal Display
        # FIX: State normal para recibir foco, y "break" para interceptar input
        self.term = ctk.CTkTextbox(self, font=("Consolas", 12), fg_color="#000000", text_color="#cccccc", wrap="char")
        self.term.pack(fill="both", expand=True, padx=5, pady=5)
        self.term.bind("<Key>", self.on_key)
        self.after(100, lambda: self.term.focus_set())
        
        # Configurar colores ANSI
        self.ansi_colors = {
            '30': 'black', '31': '#ef4444', '32': '#10b981', '33': '#f59e0b',
            '34': '#3b82f6', '35': '#a855f7', '36': '#06b6d4', '37': '#f8fafc',
            '90': 'gray', '91': '#f87171', '92': '#34d399', '93': '#fbbf24',
            '94': '#60a5fa', '95': '#c084fc', '96': '#22d3ee', '97': 'white'
        }
        for code, color in self.ansi_colors.items():
            self.term.tag_config(f"ansi_{code}", foreground=color)
        
        self.current_ansi_tag = None # Persistir color entre chunks

        # Pre-compilar Regex para rendimiento
        import re
        self.re_osc = re.compile(r'\x1b\].*?\x07')
        self.re_ansi = re.compile(r'^\x1b\[([0-9;]*)m')
        self.re_trash = re.compile(r'\x1b\[\?2004[hl]') # Bracketed paste mode

        # Conexión
        self.websocket = None
        self.loop = asyncio.new_event_loop()
        self.running = True
        
        self.term.insert("end", " Conectando a Sylo Bastion Secure Protocol...\n")
        threading.Thread(target=self.start_ws, daemon=True).start()

    def start_ws(self):
        asyncio.set_event_loop(self.loop)
        self.loop.run_until_complete(self.connect())

    async def connect(self):
        uri = f"ws://localhost:8001/api/console/{self.container_id}"
        import websockets # Lazy import
        try:
            async with websockets.connect(uri) as ws:
                self.websocket = ws
                self.update_term(" [CONECTADO] - Acceso Root Concedido.\n")
                
                while self.running:
                    try:
                        msg = await ws.recv()
                        self.update_term(msg)
                    except: break
        except Exception as e:
            self.update_term(f" [ERROR] {e}\n")

    def update_term(self, text):
        def _u():
            self.term.configure(state="normal")
            
            # 1. Limpieza rápida
            txt = self.re_osc.sub('', text)
            txt = self.re_trash.sub('', txt)
            
            # Iterador manual
            i = 0
            n = len(txt)
            
            while i < n:
                char = txt[i]
                
                # Backspace (\x08)
                if char == '\x08':
                    self.term.delete("end-2c", "end-1c")
                    i += 1
                    continue
                
                # ANSI Escape: \x1b
                if char == '\x1b':
                    match = self.re_ansi.match(txt, i) # match desde pos i
                    if match:
                        code_seq = match.group(1)
                        i = match.end() # Saltar toda la secuencia
                        
                        if code_seq == '0' or code_seq == '':
                            self.current_ansi_tag = None
                        else:
                            coords = code_seq.split(';')
                            for c in coords:
                                if c in self.ansi_colors:
                                    self.current_ansi_tag = f"ansi_{c}"
                        continue
                        
                # Caracter normal
                if char == '\r':
                    i+=1
                    continue
                    
                self.term.insert("end", char, self.current_ansi_tag)
                i += 1
                
            self.term.see("end")
            self.term.configure(state="disabled")

        self.after(0, _u)

    def on_key(self, event):
        if not self.websocket: return "break"
        
        char = event.char
        if event.keysym == "Return": char = "\n"
        elif event.keysym == "BackSpace": char = "\x08"
        elif event.keysym == "Tab": char = "\t"
        
        # Filtrar teclas de control o vacías
        if (len(char) > 0 and ord(char) < 255) or char in ["\n", "\x08", "\t"]:
            self.loop.call_soon_threadsafe(lambda: asyncio.create_task(self.send_char(char)))
            
        return "break" # IMPEDIR escritura local (el backend hará el echo)


    async def send_char(self, char):
        if self.websocket:
            await self.websocket.send(char)



if __name__ == "__main__":
    OktopusApp().mainloop()