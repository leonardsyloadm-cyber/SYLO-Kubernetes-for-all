# ==========================================
# 1. VARIABLES
# ==========================================

variable "nombre" {
  description = "Nombre del contexto de Minikube"
  type        = string
}

variable "ssh_password" {
  description = "Contraseña generada para el acceso SSH"
  type        = string
  sensitive   = true
}

# --- NUEVO: Variable para el usuario ---
variable "ssh_user" {
  description = "Usuario SSH personalizado basado en el cliente"
  type        = string
  default     = "cliente"
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.nombre
}

# ==========================================
# 2. EL POD SSH (VPS Simulado)
# ==========================================
resource "kubernetes_deployment" "ssh_box" {
  metadata {
    name = "ssh-server"
    labels = {
      app = "ssh"
    }
  }

  spec {
    replicas = 1

    selector {
      match_labels = {
        app = "ssh"
      }
    }

    template {
      metadata {
        labels = {
          app = "ssh"
        }
      }

      spec {
        container {
          name  = "ubuntu-ssh"
          image = "lscr.io/linuxserver/openssh-server:latest"

          # Configuración del contenedor SSH
          env {
            name  = "USER_NAME"
            value = var.ssh_user  # <--- CAMBIO AQUÍ: Usamos la variable
          }
          env {
            name  = "USER_PASSWORD"
            value = var.ssh_password
          }
          env {
            name  = "PASSWORD_ACCESS"
            value = "true"
          }
          env {
            name  = "SUDO_ACCESS"
            value = "true"
          }
           
          port {
            container_port = 2222 # Puerto interno de esta imagen
          }
        }
      }
    }
  }
}

# ==========================================
# 3. EL SERVICIO DE ACCESO
# ==========================================
resource "kubernetes_service" "ssh_service" {
  metadata {
    name = "ssh-access"
  }

  spec {
    selector = {
      app = "ssh"
    }
    
    type = "NodePort"

    port {
      port        = 22      # Puerto estándar SSH
      target_port = 2222    # Puerto del contenedor
    }
  }
}

# 4. SALIDA
output "ssh_port" {
  value = kubernetes_service.ssh_service.spec[0].port[0].node_port
}