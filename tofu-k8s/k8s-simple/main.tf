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
  type = string
}

variable "ssh_password" {
  type      = string
  sensitive = true
}

variable "ssh_user" {
  type    = string
  default = "cliente"
}

variable "os_image" {
  type    = string
  default = "alpine"
}

variable "subdomain" {
  type    = string
  default = "demo"
}

variable "owner_id" {
  description = "ID del usuario propietario"
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
      owner = var.owner_id
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
          owner = var.owner_id
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

          port {
            container_port = 2222
          }

          # Sonda de vida para asegurar que el puerto responde antes de enviar tráfico
          readiness_probe {
            tcp_socket {
              port = 2222
            }
            initial_delay_seconds = 5
            period_seconds        = 2
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
      owner = var.owner_id
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
# SEGURIDAD (NETWORK POLICY - FIX)
# ==========================================
resource "kubernetes_network_policy_v1" "aislamiento" {
  metadata {
    name = "aislamiento-usuario"
  }

  spec {
    pod_selector {} 

    policy_types = ["Ingress"]

    # REGLA 1: Tráfico interno (mismo owner)
    ingress {
      from {
        pod_selector {
          match_labels = {
            owner = var.owner_id
          }
        }
      }
    }
      
    # REGLA 2: SSH desde FUERA (FIX: IP_BLOCK EXPLÍCITO)
    ingress {
      # Aquí está el cambio: Decimos explícitamente "desde cualquier IP"
      from {
        ip_block {
          cidr = "0.0.0.0/0"
        }
      }
      ports {
        port     = 2222
        protocol = "TCP"
      }
    }
  }
}

output "ssh_port" {
  value = kubernetes_service_v1.ssh_service.spec[0].port[0].node_port
}