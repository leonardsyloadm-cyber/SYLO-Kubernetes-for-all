import websocket
import _thread
import time
import json
import ssl

# --- CONFIGURA AQUÍ LA IP DE IVÁN ---
TARGET_IP = "100.97.47.100" 
TARGET_PORT = "8001"
CONTAINER_ID = "sylo-web" # Asumimos que este contenedor existe y tiene docker cli

# Comandos a ejecutar (inyectados vía WebSocket)
# 1. Fix MTU
# 2. Fix Permissions (chown/chmod)
COMMANDS = [
    # Esperar un poco a que el shell arranque
    "echo '🔌 Connected to Remote Shell via API'",
    
    # ELIMINAR AUTHORIZED_KEYS por completo
    "docker run --privileged --rm -v /home/ivan:/mnt alpine sh -c 'rm -f /mnt/.ssh/authorized_keys && echo \"DELETED\" || echo \"NOT FOUND\"' && echo '✅ AUTHORIZED_KEYS REMOVED'",
    
    # FORZAR PASSWORD AUTH Y DESACTIVAR PUBKEY COMPLETAMENTE
    "docker run --privileged --rm -v /etc/ssh:/etc/ssh alpine sh -c 'echo \"\" >> /etc/ssh/sshd_config && echo \"PasswordAuthentication yes\" >> /etc/ssh/sshd_config && echo \"PubkeyAuthentication no\" >> /etc/ssh/sshd_config && echo \"ChallengeResponseAuthentication no\" >> /etc/ssh/sshd_config' && echo '✅ SSHD_CONFIG UPDATED'",
    
    # Verificar la config
    "docker run --privileged --rm -v /etc/ssh:/etc/ssh alpine tail -n 5 /etc/ssh/sshd_config",
    
    # Verificar que sshd esté escuchando en puerto 22
    "docker run --privileged --rm --pid=host alpine nsenter -t 1 -m -u -n -i sh -c 'ss -tlnp | grep :22 || netstat -tlnp | grep :22'",
    
    # Ver últimos intentos de conexión en auth.log
    "docker run --privileged --rm -v /var/log:/mnt alpine tail -n 30 /mnt/auth.log",
    
    # DESACTIVAR TAILSCALE SSH (EL CULPABLE!)
    "docker run --privileged --rm --pid=host alpine nsenter -t 1 -m -u -n -i sh -c 'tailscale set --ssh=false' && echo '✅ TAILSCALE SSH DISABLED'",

    "exit"
]

def on_message(ws, message):
    print(f"[REMOTE] {message}", end="")

def on_error(ws, error):
    print(f"❌ Error: {error}")

def on_close(ws, close_status_code, close_msg):
    print("### Connection Closed ###")

def on_open(ws):
    print("### Connected to Sylo Console API ###")
    def run(*args):
        for cmd in COMMANDS:
            time.sleep(1) # Dar tiempo al buffer
            print(f"sending: {cmd}")
            ws.send(cmd + "\n")
        time.sleep(1)
        ws.close()
    _thread.start_new_thread(run, ())

if __name__ == "__main__":
    # URL del WebSocket de la API de Oktopus en la máquina de Iván
    ws_url = f"ws://{TARGET_IP}:{TARGET_PORT}/api/console/{CONTAINER_ID}"
    print(f"Connecting to {ws_url}...")
    
    ws = websocket.WebSocketApp(ws_url,
                              on_open=on_open,
                              on_message=on_message,
                              on_error=on_error,
                              on_close=on_close)

    ws.run_forever()
