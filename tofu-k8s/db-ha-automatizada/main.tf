terraform {
  required_providers {
    kubernetes = {
      source = "hashicorp/kubernetes"
    }
  }
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.nombre
}

# ==========================================
# VARIABLES (SINTAXIS CORREGIDA)
# ==========================================

variable "nombre" {
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

variable "db_name" {
  type    = string
  default = "sylo_db"
}

variable "owner_id" {
  description = "ID del usuario propietario"
  type        = string
  default     = "admin"
}

variable "os_image" {
  description = "ubuntu o alpine"
  type        = string
  default     = "ubuntu"
}

# ==========================================
# LÓGICA DE PUERTOS Y OS
# ==========================================
locals {
  # Si es Alpine, usamos linuxserver (puerto 2222)
  # Si es Ubuntu, usamos rastasheep (puerto 22)
  is_alpine = var.os_image == "alpine"
  
  ssh_image = local.is_alpine ? "lscr.io/linuxserver/openssh-server:latest" : "rastasheep/ubuntu-sshd:18.04"
  ssh_port  = local.is_alpine ? 2222 : 22
}

# ==========================================
# 1. SERVIDOR MYSQL (SIMPLE - NO HA)
# ==========================================
resource "kubernetes_deployment_v1" "mysql" {
  metadata {
    name = "mysql-server"
    labels = {
      app   = "mysql"
      owner = var.owner_id
    }
  }

  spec {
    replicas = 1
    selector {
      match_labels = { app = "mysql" }
    }
    template {
      metadata {
        labels = { app = "mysql", owner = var.owner_id }
      }
      spec {
        container {
          name  = "mysql"
          image = "mysql:8.0"
          
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          env {
            name  = "MYSQL_DATABASE"
            value = var.db_name
          }

          port {
            container_port = 3306
          }

          readiness_probe {
            exec {
              command = ["mysqladmin", "ping", "-h", "127.0.0.1", "-u", "root", "-ppassword_root"]
            }
            initial_delay_seconds = 60
            period_seconds        = 10
            timeout_seconds       = 5
            failure_threshold     = 10
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "mysql_service" {
  metadata {
    name = "mysql-service"
    labels = { owner = var.owner_id }
  }
  spec {
    selector = { app = "mysql" }
    type = "NodePort"
    port {
      port        = 3306
      target_port = 3306
    }
  }
}

# ==========================================
# 2. SERVIDOR SSH (ADAPTATIVO)
# ==========================================
resource "kubernetes_deployment_v1" "ssh_box" {
  metadata {
    name = "ssh-server"
    labels = {
      app   = "ssh"
      owner = var.owner_id
    }
  }

  spec {
    replicas = 1
    selector {
      match_labels = { app = "ssh" }
    }
    template {
      metadata {
        labels = { app = "ssh", owner = var.owner_id }
      }
      spec {
        init_container {
          name    = "install-tools"
          image   = "alpine:3.19"
          command = ["/bin/sh", "-c", "apk add --no-cache htop procps net-tools && cp /usr/bin/htop /tools/ && cp /bin/ps /tools/ 2>/dev/null || true"]
          volume_mount {
            name       = "tools-bin"
            mount_path = "/tools"
          }
        }
        container {
          name  = "os-container"
          image = local.ssh_image

          # Variables universales (funcionan en Alpine, ignoradas en Ubuntu básico)
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

          # Usamos el puerto calculado dinámicamente
          port {
            container_port = local.ssh_port
          }

          volume_mount {
            name       = "tools-bin"
            mount_path = "/usr/local/bin"
          }
        }
        volume {
          name = "tools-bin"
          empty_dir {}
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "ssh_service" {
  metadata {
    name = "ssh-access"
    labels = { owner = var.owner_id }
  }
  spec {
    selector = { app = "ssh" }
    type = "NodePort"
    
    port {
      port        = 22
      # Aquí está la magia: apunta al puerto correcto según el OS
      target_port = local.ssh_port
    }
  }
}

# ==========================================
# 3. SEGURIDAD (ABIERTA PARA PRUEBAS)
# ==========================================
resource "kubernetes_network_policy_v1" "aislamiento_plata" {
  metadata {
    name = "aislamiento-plata"
  }
  spec {
    pod_selector {} 
    policy_types = ["Ingress"]
    
    # 1. Tráfico Interno
    ingress {
      from {
        pod_selector {
          match_labels = { owner = var.owner_id }
        }
      }
    }

    # 2. Acceso Externo (SSH y DB)
    ingress {
      from {
        ip_block { cidr = "0.0.0.0/0" }
      }
      ports {
        port     = local.ssh_port
        protocol = "TCP"
      }
      ports {
        port     = 3306
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

output "db_port" {
  value = kubernetes_service_v1.mysql_service.spec[0].port[0].node_port
}