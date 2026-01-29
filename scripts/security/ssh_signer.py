import argparse
import subprocess
import os
import sys
import tempfile
import shutil

# Configuración
CA_KEY = "/etc/ssh/sylo_ca"

def sign_key(username, public_key_content):
    """
    Firma una clave pública SSH usando la CA local.
    """
    if not os.path.exists(CA_KEY):
        print(f"Error: La clave de CA no existe en {CA_KEY}")
        sys.exit(1)

    # Crear un directorio temporal para trabajar
    with tempfile.TemporaryDirectory() as temp_dir:
        input_key_path = os.path.join(temp_dir, "user_key.pub")
        
        # Guardar la clave pública recibida en el archivo temporal
        with open(input_key_path, "w") as f:
            f.write(public_key_content)
        
        # ID unico para trazabilidad (timestamp + usuario)
        key_id = f"sylo_{username}_{os.urandom(4).hex()}"
        
        # Construir el comando ssh-keygen
        # -s: Clave CA
        # -I: Key ID (Logging)
        # -n: Principals (Usuario del sistema al que se permite acceso)
        # -V: Validez (+5m = 5 minutos)
        cmd = [
            "ssh-keygen",
            "-s", CA_KEY,
            "-I", key_id,
            "-n", username,
            "-V", "+5m",
            input_key_path
        ]
        
        try:
            # Ejecutar el firmado
            subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            
            # El certificado se genera automáticamente como user_key-cert.pub
            cert_path = os.path.join(temp_dir, "user_key-cert.pub")
            
            if os.path.exists(cert_path):
                with open(cert_path, "r") as f:
                    cert_content = f.read()
                return cert_content
            else:
                print("Error: No se generó el archivo de certificado.")
                sys.exit(1)
                
        except subprocess.CalledProcessError as e:
            print(f"Error firmando la clave: {e.stderr.decode()}")
            sys.exit(1)

def main():
    parser = argparse.ArgumentParser(description='Sylo SSH Signer')
    parser.add_argument('--username', required=True, help='Usuario del sistema (principal)')
    parser.add_argument('--public_key', required=True, help='Contenido de la clave pública a firmar')
    
    args = parser.parse_args()
    
    certificate = sign_key(args.username, args.public_key)
    print(certificate)

if __name__ == "__main__":
    main()
