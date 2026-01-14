import docker
import asyncio
import os
import struct
import logging

# Configurar logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("DockerTerminal")

class DockerTerminal:
    def __init__(self, container_id):
        self.container_id = container_id
        self.client = docker.from_env()
        self.socket = None
        self.exec_id = None

    async def start(self):
        """Inicia la sesión PTY en el contenedor"""
        try:
            # 1. Crear instancia de ejecución (bash)
            # Usamos API de bajo nivel para tener control del socket PTY
            api_client = self.client.api
            self.exec_id = api_client.exec_create(
                self.container_id,
                cmd="/bin/bash",
                stdin=True,
                stdout=True,
                stderr=True,
                tty=True
            )['Id']

            # 2. Iniciar y obtener el socket crudo (stream)
            # socket=True devuelve un generador o socket objeto dependiendo de la lib
            # En docker-py moderno, exec_start con socket=True devuelve un socket raw
            self.socket = api_client.exec_start(
                self.exec_id,
                detach=False,
                tty=True,
                socket=True
            )
            
            # El socket puede necesitar ser puesto en modo no bloqueante para asyncio loop
            # Pero docker-py a veces devuelve un generador. Verificaremos.
            logger.info(f"Terminal iniciada para {self.container_id}")
            return True

        except Exception as e:
            logger.error(f"Error iniciando terminal: {e}")
            return False

    async def write(self, data):
        """Escribe datos (keystrokes) al contenedor"""
        if self.socket:
            try:
                # Optimización: Usar os.write directamente si es un fdesc válido
                # Esto evita la excepción "Not Writable" de los wrappers de Python
                if hasattr(self.socket, 'fileno'):
                    os.write(self.socket.fileno(), data.encode('utf-8'))
                elif hasattr(self.socket, 'send'):
                    self.socket.send(data.encode('utf-8'))
                elif hasattr(self.socket, 'write'):
                    self.socket.write(data.encode('utf-8'))
                    self.socket.flush()
            except Exception as e:
                logger.error(f"Error escribiendo: {e}")

    async def read_loop(self, ws_send_func):
        """Lee stdout del contenedor y lo envía al WebSocket"""
        try:
            # Docker socket stream reader
            # Esto es bloqueante, idealmente debería correr en un executor
            loop = asyncio.get_event_loop()
            
            while True:
                # Hacemos la lectura en un thread aparte para no bloquear el loop de FastAPI
                data = await loop.run_in_executor(None, self._read_socket)
                if not data:
                    break
                await ws_send_func(data.decode('utf-8', errors='ignore'))
                
        except Exception as e:
            logger.error(f"Error en read_loop: {e}")
        finally:
            self.close()

    def _read_socket(self):
        """Función auxiliar bloqueante para leer del socket"""
        # Leemos chunks de 1024 bytes
        if hasattr(self.socket, 'recv'):
            return self.socket.recv(1024)
        elif hasattr(self.socket, 'read'):
            return self.socket.read(1024)
        return None

    def close(self):
        """Cierra la conexión"""
        if self.socket:
            try:
                if hasattr(self.socket, 'close'):
                    self.socket.close()
            except:
                pass
        logger.info(f"Terminal cerrada para {self.container_id}")
