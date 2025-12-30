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
# VARIABLES (FORMATO EXTENDIDO)
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

# ==========================================
# RECURSOS
# ==========================================

resource "kubernetes_deployment_v1" "ssh_box" {
  metadata {
    name = "ssh-server"
    labels = {
      app = "bronce-ssh"
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
          app = "bronce-ssh"
        }
      }

      spec {
        container {
          name  = "terminal"
          image = "lscr.io/linuxserver/openssh-server:latest"

          # --- VARIABLES DE ENTORNO ---
          
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

          # Variable informativa del OS
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
# OUTPUTS
# ==========================================

output "ssh_port" {
  value = kubernetes_service_v1.ssh_service.spec[0].port[0].node_port
}