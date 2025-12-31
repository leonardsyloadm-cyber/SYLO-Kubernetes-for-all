terraform {
  required_providers {
    kubernetes = {
      source = "hashicorp/kubernetes"
    }
  }
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.cluster_name
}

# ==========================================
# VARIABLES
# ==========================================

variable "cluster_name" {
  description = "Nombre del cluster minikube"
  type        = string
}

variable "ssh_password" {
  description = "Contraseña para SSH"
  type        = string
  sensitive   = true
}

variable "ssh_user" {
  description = "Usuario SSH"
  type        = string
  default     = "cliente"
}

variable "os_image" {
  description = "Imagen del sistema operativo"
  type        = string
  default     = "alpine:latest"
}

variable "subdomain" {
  description = "Subdominio asignado"
  type        = string
  default     = "demo"
}

# --- NUEVA VARIABLE DE IDENTIDAD ---
variable "owner_id" {
  description = "ID del usuario propietario (para aislamiento)"
  type        = string
  default     = "admin"
}

# ==========================================
# RECURSOS
# ==========================================

resource "kubernetes_deployment_v1" "ssh_box" {
  metadata {
    name = "ssh-server"
    labels = {
      app   = "bronce-ssh"
      owner = var.owner_id  # <--- ETIQUETA DE IDENTIDAD
    }
  }

  spec {
    replicas = 1

    selector {
      match_labels = {
        app = "bronce-ssh"
      }
    }

    template {
      metadata {
        labels = {
          app   = "bronce-ssh"
          owner = var.owner_id  # <--- ETIQUETA EN EL POD
        }
      }

      spec {
        container {
          name  = "terminal"
          image = "lscr.io/linuxserver/openssh-server:latest"

          env {
            name  = "USER_NAME"
            value = var.ssh_user
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

          env {
            name  = "OS_TYPE"
            value = var.os_image
          }

          port {
            container_port = 2222
          }

          resources {
            limits = {
              cpu    = "500m"
              memory = "512Mi"
            }
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "ssh_service" {
  metadata {
    name = "ssh-access"
    labels = {
      owner = var.owner_id # <--- ETIQUETA EN EL SERVICIO
    }
  }

  spec {
    selector = {
      app = "bronce-ssh"
    }

    type = "NodePort"

    port {
      port        = 22
      target_port = 2222
    }
  }
}

# ==========================================
# SEGURIDAD (NETWORK POLICY)
# ==========================================
# Esto define las reglas del "Portero de Discoteca" automáticamente
resource "kubernetes_network_policy" "aislamiento" {
  metadata {
    name = "aislamiento-usuario"
  }

  spec {
    pod_selector {} # Aplica a todos los pods

    policy_types = ["Ingress"]

    ingress {
      # REGLA 1: Permitir tráfico entre pods del MISMO DUEÑO
      from {
        pod_selector {
          match_labels = {
            owner = var.owner_id
          }
        }
      }
      
      # REGLA 2: Permitir tráfico SSH desde fuera (necesario para que te conectes)
      ports {
        port     = 2222
        protocol = "TCP"
      }
    }
  }
}

# ==========================================
# OUTPUTS
# ==========================================

output "ssh_port" {
  value = kubernetes_service_v1.ssh_service.spec[0].port[0].node_port
}