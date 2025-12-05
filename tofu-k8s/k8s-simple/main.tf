variable "nombre" {
  description = "Nombre del contexto de Minikube"
  type        = string
}

variable "ssh_password" {
  description = "Contraseña generada para el acceso SSH"
  type        = string
  sensitive   = true
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.nombre
}

# 1. EL POD SSH (VPS Simulado)
# Usamos una imagen ligera de OpenSSH Server
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
            value = "cliente"
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

# 2. EL SERVICIO DE ACCESO
# Exponemos el puerto 2222 interno al exterior mediante NodePort
resource "kubernetes_service" "ssh_service" {
  metadata {
    name = "ssh-access"
  }

  spec {
    # --- CORRECCIÓN AQUÍ: Añadido el signo '=' ---
    selector = {
      app = "ssh"
    }
    
    type = "NodePort"

    port {
      port        = 22     # Puerto estándar SSH
      target_port = 2222   # Puerto del contenedor
    }
  }
}

# 3. SALIDA (Para que el script de Bash sepa el puerto)
output "ssh_port" {
  value = kubernetes_service.ssh_service.spec[0].port[0].node_port
}