from typing import Dict, Optional, Tuple, List
import time
import threading

class NoResourcesAvailableError(Exception):
    """Excepción lanzada cuando no hay nodos con suficientes recursos disponibles."""
    pass

class NodeInventory:
    def __init__(self):
        # Diccionario para almacenar el estado de los nodos
        # Clave: node_id, Valor: dict con datos del nodo
        self._nodes: Dict[str, dict] = {}
        self._lock = threading.Lock() # Thread-safety simple

    def update_node(self, data: dict):
        """
        Recibe el heartbeat y actualiza los datos.
        Estructura esperada de data:
        {
            "node_id": "leonard-pc",
            "ip": "10.100.0.2",
            "total_ram_mb": 32000,
            "used_ram_mb": 4000,
            "cpu_load_percent": 15.5
        }
        """
        node_id = data.get("node_id")
        if not node_id:
            return

        with self._lock:
            # Actualizamos o creamos la entrada
            self._nodes[node_id] = {
                "node_id": node_id,
                "ip": data.get("ip"),
                "total_ram_mb": data.get("total_ram_mb", 0),
                "used_ram_mb": data.get("used_ram_mb", 0),
                "cpu_load_percent": data.get("cpu_load_percent", 0.0),
                "last_heartbeat": time.time() # Timestamp actual
            }

    def get_alive_nodes(self) -> List[dict]:
        """Devuelve nodos que han dado señal en los últimos 30 segundos."""
        cutoff_time = time.time() - 30
        with self._lock:
            return [
                node for node in self._nodes.values()
                if node["last_heartbeat"] > cutoff_time
            ]

    def find_best_node(self, required_ram_mb: int) -> Tuple[str, str]:
        """
        Encuentra el mejor nodo para desplegar una carga de trabajo.
        Retorna: (ip_nodo, id_nodo)
        Lanza: NoResourcesAvailableError si no hay candidatos.
        """
        alive_nodes = self.get_alive_nodes()
        candidates = []

        # 1. Filtrado (Hard Constraint)
        for node in alive_nodes:
            available_ram = node["total_ram_mb"] - node["used_ram_mb"]
            if available_ram >= required_ram_mb:
                candidates.append(node)

        if not candidates:
            raise NoResourcesAvailableError(f"No hay nodos con {required_ram_mb}MB RAM disponibles.")

        # 2. Clasificación (Soft Constraint): Ordenar por MENOR carga de CPU
        # Python sort es estable y eficiente.
        candidates.sort(key=lambda x: x["cpu_load_percent"])

        # 3. Selección: Ganador es el primero (menor CPU load)
        winner = candidates[0]
        
        return winner["ip"], winner["node_id"]

# Instancia global para ser importada por la API
inventory = NodeInventory()

# --- CHAT SYSTEM (V2.1) ---
class ChatManager:
    def __init__(self):
        self._messages: List[dict] = [] # Lista de dicts: {timestamp, sender, text}
        self._lock = threading.Lock()
    
    def add_message(self, sender: str, text: str):
        with self._lock:
            self._messages.append({
                "timestamp": time.time(),
                "sender": sender,
                "text": text
            })
            # Mantener solo los últimos 100 mensajes
            if len(self._messages) > 100:
                self._messages.pop(0)

    def get_messages(self, since_ts: float = 0.0) -> List[dict]:
        with self._lock:
            return [m for m in self._messages if m["timestamp"] > since_ts]

chat_db = ChatManager()
