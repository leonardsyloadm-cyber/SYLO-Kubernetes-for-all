import os
import json
import pymysql
import re
from cryptography.fernet import Fernet

# 1. Generar e Imprimir Master Key
MASTER_KEY = Fernet.generate_key()
cipher_suite = Fernet(MASTER_KEY)

print(f"⚠️  SYLO_MASTER_KEY={MASTER_KEY.decode('utf-8')}")
print("-" * 50)

# 2. Conexión a la Nueva BD (Usando root para tener permisos garantizados)
try:
    db = pymysql.connect(
        host="127.0.0.1",
        user="root",
        password="root",
        database="sylo_admin_db",
        cursorclass=pymysql.cursors.Cursor
    )
    cursor = db.cursor()
except Exception as e:
    print(f"❌ Error conectando a BD: {e}")
    exit(1)

# 3. Leer Deployments
cursor.execute("SELECT id FROM k8s_deployments")
deployments = cursor.fetchall()
print(f"🔍 Encontrados {len(deployments)} deployments en la base de datos.")

procesados = 0
BUZON_DIR = "sylo-web/buzon-pedidos"

for (dep_id,) in deployments:
    file_path = os.path.join(BUZON_DIR, f"status_{dep_id}.json")
    
    if os.path.exists(file_path):
        try:
            with open(file_path, 'r') as f:
                data = json.load(f)
            
            # Extract raw values (handling potential mess in deploy_custom.sh)
            # Prioritize 'ssh_pass' (used in current env) over 'ssh_password' (user snippet)
            raw_ssh = data.get("ssh_pass") or data.get("ssh_password") or ""
            raw_db = data.get("db_root_password") or "root" # Default logic from deploy_custom.sh
            
            # --- Logic to Clean/Extract Secrets ---
            # Si raw_ssh contiene el bloque de texto grande (INFO_TEXT), tratamos de extraer la pass real
            ssh_real = raw_ssh
            if "[SSH]" in raw_ssh or "Pass:" in raw_ssh:
                # Intento de extracción por Regex: "Pass: (valor)" al final del bloque SSH
                # Buscamos la última ocurrencia de "Pass:"
                matches = re.findall(r'Pass:\s*([^\s\n]+)', raw_ssh)
                if matches:
                    ssh_real = matches[-1] # Tomamos la última que suele ser la de SSH
            
            if ssh_real:
                # Encriptar
                ssh_enc = cipher_suite.encrypt(ssh_real.encode('utf-8')).decode('utf-8')
                db_enc = cipher_suite.encrypt(raw_db.encode('utf-8')).decode('utf-8')
                
                # Upsert en cluster_secrets
                sql = """
                INSERT INTO cluster_secrets (deployment_id, ssh_password_enc, db_root_password_enc) 
                VALUES (%s, %s, %s)
                ON DUPLICATE KEY UPDATE ssh_password_enc=%s, db_root_password_enc=%s
                """
                cursor.execute(sql, (dep_id, ssh_enc, db_enc, ssh_enc, db_enc))
                db.commit()
                procesados += 1
                print(f"✅ ID {dep_id}: Migrado. (SSH Pass Len: {len(ssh_real)})")
            else:
                print(f"⚠️ ID {dep_id}: Sin contraseña SSH en JSON.")

        except Exception as e:
            print(f"❌ Error procesando ID {dep_id}: {e}")
    else:
        # print(f"ℹ️ ID {dep_id}: Archivo {file_path} no encontrado.")
        pass

print("-" * 50)
print(f"🚀 Procesados {procesados} archivos correctamente.")
db.close()
