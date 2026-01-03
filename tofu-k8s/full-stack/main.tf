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
  description = "Nombre único del cluster Minikube"
  type        = string
}

variable "ssh_password" {
  description = "Contraseña para el acceso SSH"
  type        = string
  sensitive   = true
}

variable "ssh_user" {
  description = "Usuario SSH personalizado"
  type        = string
  default     = "cliente"
}

variable "db_name" {
  description = "Nombre de la base de datos"
  type        = string
  default     = "sylo_db"
}

variable "image_web" {
  description = "Imagen Docker para el servidor web"
  type        = string
  default     = "nginx:latest"
}

variable "web_custom_name" {
  description = "Nombre personalizado para el servicio web"
  type        = string
  default     = "Sylo Web Cluster"
}

variable "subdomain" {
  description = "Subdominio del cliente"
  type        = string
  default     = "demo"
}

variable "owner_id" {
  description = "ID del usuario propietario"
  type        = string
  default     = "admin"
}

# --- LÓGICA REDHAT ---
locals {
  # Las imágenes RedHat UBI suelen exponer el puerto 8080 en lugar del 80
  is_redhat = can(regex("(ubi|rhel|redhat)", var.image_web))
  web_port  = local.is_redhat ? 8080 : 80
}

# ==========================================
# 1. BASE DE DATOS (MYSQL HA)
# ==========================================

resource "kubernetes_service_v1" "mysql_master_service" {
  metadata {
    name = "mysql-master"
    labels = {
      app     = "mysql"
      role    = "master"
      cluster = var.cluster_name
      owner   = var.owner_id
    }
  }
  spec {
    selector = {
      app     = "mysql"
      role    = "master"
      cluster = var.cluster_name
    }
    port {
      port        = 3306
      target_port = 3306
    }
    type = "ClusterIP"
  }
}

resource "kubernetes_stateful_set_v1" "mysql_master" {
  metadata {
    name = "mysql-master"
    labels = {
      app     = "mysql"
      role    = "master"
      cluster = var.cluster_name
      owner   = var.owner_id
    }
  }
  spec {
    replicas     = 1
    service_name = "mysql-master"
    
    selector {
      match_labels = {
        app     = "mysql"
        role    = "master"
        cluster = var.cluster_name
      }
    }

    template {
      metadata {
        labels = {
          app     = "mysql"
          role    = "master"
          cluster = var.cluster_name
          owner   = var.owner_id
        }
      }
      spec {
        container {
          image = "mysql:5.7"
          name  = "mysql"
          
          image_pull_policy = "IfNotPresent"
          
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
            exec { command = ["mysqladmin", "ping", "-h", "localhost", "-u", "root", "-ppassword_root"] }
            initial_delay_seconds = 20 
            period_seconds        = 5
          }

          args = [
            "--server-id=1", 
            "--log-bin=mysql-bin", 
            "--binlog-do-db=${var.db_name}", 
            "--max_allowed_packet=32M", 
            "--gtid_mode=ON", 
            "--enforce-gtid-consistency=ON"
          ]
        }
      }
    }
  }
}

resource "kubernetes_stateful_set_v1" "mysql_slave" {
  metadata {
    name = "mysql-slave"
    labels = {
      app     = "mysql"
      role    = "slave"
      cluster = var.cluster_name
      owner   = var.owner_id
    }
  }
  spec {
    replicas     = 1
    service_name = "mysql-master"
    
    selector {
      match_labels = {
        app     = "mysql"
        role    = "slave"
        cluster = var.cluster_name
      }
    }
    
    template {
      metadata {
        labels = {
          app     = "mysql"
          role    = "slave"
          cluster = var.cluster_name
          owner   = var.owner_id
        }
      }
      spec {
        container {
          image = "mysql:5.7"
          name  = "mysql"
          
          image_pull_policy = "IfNotPresent"
          
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          
          port {
            container_port = 3306
          }
          
          readiness_probe {
            exec { command = ["mysqladmin", "ping", "-h", "localhost", "-u", "root", "-ppassword_root"] }
            initial_delay_seconds = 20 
            period_seconds        = 5
          }
          
          args = [
            "--server-id=2", 
            "--gtid_mode=ON", 
            "--enforce-gtid-consistency=ON", 
            "--read_only=ON"
          ]
        }
      }
    }
  }
}

# ==========================================
# 2. WEB HA (NGINX / REDHAT)
# ==========================================

resource "kubernetes_config_map_v1" "web_content" {
  metadata {
    name = "web-content-config"
  }
  data = {
    "index.html" = <<-EOF
      <!DOCTYPE html>
      <html lang="es">
      <head><title>${var.subdomain} - Sylo Oro</title></head>
      <body style="text-align:center; padding:50px; font-family: sans-serif; background: #f0f9ff;">
        <h1 style="color:#d4af37;">🥇 Plan ORO Activo</h1>
        <h2>${var.web_custom_name}</h2>
        <p>Dominio: <b>${var.subdomain}.sylobi.org</b></p>
        <hr>
        <p>Base de Datos: <b>${var.db_name}</b> (HA Cluster)</p>
        <p>Imagen Base: ${var.image_web}</p>
        <p>Puerto Interno Container: ${local.web_port}</p>
        <p><small>Owner ID: ${var.owner_id}</small></p>
      </body>
      </html>
    EOF
  }
}

resource "kubernetes_deployment_v1" "web_ha" {
  metadata {
    name = "nginx-ha"
    labels = {
      cluster = var.cluster_name
      owner   = var.owner_id
    }
  }
  spec {
    replicas = 2
    selector {
      match_labels = {
        app = "web-cliente"
      }
    }
    template {
      metadata {
        labels = {
          app     = "web-cliente"
          owner   = var.owner_id
        }
      }
      spec {
        container {
          image = var.image_web
          name  = "nginx"
          
          image_pull_policy = "IfNotPresent"
          
          # Si es RedHat, usamos el comando específico de S2I
          command = local.is_redhat ? ["/usr/libexec/s2i/run"] : null
          
          port {
            container_port = local.web_port
          }

          liveness_probe {
            http_get {
              path = "/"
              port = local.web_port 
            }
            initial_delay_seconds = 15
            period_seconds        = 20
          }

          readiness_probe {
            http_get {
              path = "/"
              port = local.web_port
            }
            initial_delay_seconds = 5
            period_seconds        = 10
          }

          volume_mount {
            name       = "html-volume"
            # Ruta cambia según la imagen (RedHat usa /opt/app-root/src)
            mount_path = local.is_redhat ? "/opt/app-root/src" : "/usr/share/nginx/html"
          }
        }
        volume {
          name = "html-volume"
          config_map {
            name = "web-content-config"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "web_service" {
  metadata {
    name = "web-service"
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    selector = {
      app = "web-cliente"
    }
    type = "NodePort"
    port {
      port        = 80
      target_port = local.web_port
    }
  }
}

# ==========================================
# 3. SSH SERVER
# ==========================================

resource "kubernetes_deployment_v1" "ssh_server" {
  metadata {
    name = "ssh-server"
    labels = {
      cluster = var.cluster_name
      owner   = var.owner_id
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "ssh-server"
      }
    }
    template {
      metadata {
        labels = {
          app     = "ssh-server"
          owner   = var.owner_id
        }
      }
      spec {
        container {
          image = "lscr.io/linuxserver/openssh-server:latest"
          name  = "openssh"
          
          image_pull_policy = "IfNotPresent"
          
          port {
            container_port = 2222
          }
          
          env {
            name  = "PASSWORD_ACCESS"
            value = "true"
          }
          env {
            name  = "USER_PASSWORD"
            value = var.ssh_password
          }
          env {
            name  = "USER_NAME"
            value = var.ssh_user
          }
          env {
            name  = "SUDO_ACCESS"
            value = "true"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "ssh_server_service" {
  metadata {
    name = "ssh-server-service"
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    selector = {
      app = "ssh-server"
    }
    type = "NodePort"
    port {
      port        = 22
      target_port = 2222
    }
  }
}

# ==========================================
# 4. SEGURIDAD (CORREGIDA)
# ==========================================
resource "kubernetes_network_policy_v1" "aislamiento_oro" {
  metadata {
    name = "aislamiento-oro"
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
      
    # REGLA 2: Acceso Externo (SSH y Web)
    # ⚠️ FIX: Bloque limpio, sin anidar ingress dentro de ingress
    ingress {
      from {
        ip_block {
          cidr = "0.0.0.0/0"
        }
      }
      ports {
        port     = 2222
        protocol = "TCP"
      }
      ports {
        port     = local.web_port # 80 o 8080
        protocol = "TCP"
      }
    }
  }
}

# ==========================================
# OUTPUTS
# ==========================================

output "ssh_user" {
  value = var.ssh_user
}

output "ssh_port" {
  value = kubernetes_service_v1.ssh_server_service.spec[0].port[0].node_port
}

output "web_port" {
  value = kubernetes_service_v1.web_service.spec[0].port[0].node_port
}