import time
import os
import sys
import mysql.connector

sys.stdout.reconfigure(line_buffering=True)

# CONFIGURACIÓN DB (Igual que el Orchestrator)
DB_CONFIG = {
    "host": "127.0.0.1",
    "user": "sylo_app",
    "password": "sylo_app_pass",
    "database": "kylo_main_db",
    "connection_timeout": 10
}

# CONFIGURACIÓN DNS
DOMAIN_SUFFIX = ".sylobi.org"
HOSTS_PATH = "/etc/hosts"
START_MARKER = "### SYLO-AUTO-DNS START ###"
END_MARKER = "### SYLO-AUTO-DNS END ###"

def get_db_connection():
    return mysql.connector.connect(**DB_CONFIG)

def get_active_hosts():
    """Recupera (IP, Subdominio) de los pedidos activos"""
    hosts = []
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        
        # JOIN entre orders y order_specs para sacar IP y Subdominio
        query = """
            SELECT o.ip_address, s.subdomain 
            FROM orders o
            JOIN order_specs s ON o.id = s.order_id
            WHERE o.status = 'active' 
            AND o.ip_address IS NOT NULL 
            AND o.ip_address != ''
        """
        cursor.execute(query)
        results = cursor.fetchall()
        
        for row in results:
            full_domain = row['subdomain'] + DOMAIN_SUFFIX
            hosts.append((row['ip_address'], full_domain))
            
        conn.close()
    except Exception as e:
        print(f"❌ Error DB: {e}")
    return hosts

def update_hosts_file(new_entries):
    """Reescribe /etc/hosts preservando lo que no es de Sylo"""
    try:
        with open(HOSTS_PATH, 'r') as f:
            lines = f.readlines()

        # Filtrar el bloque antiguo de Sylo
        clean_lines = []
        skip = False
        for line in lines:
            if START_MARKER in line:
                skip = True
                continue
            if END_MARKER in line:
                skip = False
                continue
            if not skip:
                clean_lines.append(line)

        # Preparar el nuevo bloque
        sylo_block = [f"\n{START_MARKER}\n"]
        for ip, domain in new_entries:
            sylo_block.append(f"{ip}\t{domain}\n")
        sylo_block.append(f"{END_MARKER}\n")

        # Escribir todo de vuelta (REQUIERE SUDO)
        with open(HOSTS_PATH, 'w') as f:
            f.writelines(clean_lines + sylo_block)
            
        return True
    except PermissionError:
        print("⛔ ERROR: Necesito permisos de administrador (SUDO) para editar /etc/hosts")
        return False
    except Exception as e:
        print(f"❌ Error escribiendo hosts: {e}")
        return False

def main():
    if os.geteuid() != 0:
        print("⚠️  ATENCIÓN: Este script debe ejecutarse con SUDO.")
        print("   Uso: sudo ./venv/bin/python3 worker/sylo_dns.py")
        sys.exit(1)

    print("📡 [SYLO DNS] Iniciando servicio de resolución local...")
    print(f"Target: {HOSTS_PATH}")

    last_entries = []

    while True:
        current_entries = get_active_hosts()
        
        # Solo actualizamos si hay cambios para no machacar el disco
        if current_entries != last_entries:
            if update_hosts_file(current_entries):
                print(f"✅ DNS Actualizado: {len(current_entries)} dominios activos.")
                for ip, dom in current_entries:
                    print(f"   -> {dom} apuntando a {ip}")
                last_entries = current_entries
        
        time.sleep(5)

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n👋 DNS Service detenido.")