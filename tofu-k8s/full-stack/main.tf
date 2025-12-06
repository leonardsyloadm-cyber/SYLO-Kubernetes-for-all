terraform {
  required_providers {
    kubernetes = {
      source = "hashicorp/kubernetes"
    }
  }
}

provider "kubernetes" {
  # Asegura que OpenTofu use el cluster Minikube que ha sido levantado
  config_path  = pathexpand("~/.kube/config")
  config_context = var.cluster_name
}

# --- VARIABLES ÚNICAS ESPERADAS ---
variable "cluster_name" {
  description = "Nombre único del cluster Minikube."
  type        = string
}

variable "ssh_password" {
  description = "Contraseña para el acceso SSH al servidor web."
  type      = string
  sensitive = true
}

# =================================================================
# CAPA DE BASE DE DATOS (MySQL Maestro/Esclavo)
# =================================================================

# Servicio ClusterIP para el Maestro (Punto de entrada de la app)
resource "kubernetes_service_v1" "mysql_master_service" {
  metadata {
    name = "mysql-master"
    labels = {
      app = "mysql"
      role = "master"
      cluster = var.cluster_name
    }
  }
  spec {
    selector = {
      app = "mysql"
      role = "master"
      cluster = var.cluster_name
    }
    port {
      port        = 3306
      target_port = 3306
    }
    type = "ClusterIP"
  }
}

# StatefulSet para la réplica Maestra
resource "kubernetes_stateful_set_v1" "mysql_master" {
  metadata {
    name = "mysql-master"
    labels = {
      app = "mysql"
      role = "master"
      cluster = var.cluster_name
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "mysql"
        role = "master"
        cluster = var.cluster_name
      }
    }
    service_name = "mysql-master"
    template {
      metadata {
        labels = {
          app = "mysql"
          role = "master"
          cluster = var.cluster_name
        }
      }
      spec {
        container {
          image = "mysql:5.7"
          name  = "mysql"
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          env {
            name  = "MYSQL_DATABASE"
            value = "pedido"
          }
          port {
            container_port = 3306
          }
          args = [
            "--server-id=1",
            "--log-bin=mysql-bin",
            "--binlog-do-db=pedido",
            "--max_allowed_packet=32M",
            "--gtid_mode=ON",
            "--enforce-gtid-consistency=ON"
          ]
        }
      }
    }
  }
}

# StatefulSet para la réplica Esclava
resource "kubernetes_stateful_set_v1" "mysql_slave" {
  metadata {
    name = "mysql-slave"
    labels = {
      app = "mysql"
      role = "slave"
      cluster = var.cluster_name
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "mysql"
        role = "slave"
        cluster = var.cluster_name
      }
    }
    service_name = "mysql-master"
    template {
      metadata {
        labels = {
          app = "mysql"
          role = "slave"
          cluster = var.cluster_name
        }
      }
      spec {
        container {
          image = "mysql:5.7"
          name  = "mysql"
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          env {
            name  = "MYSQL_DATABASE"
            value = "pedido"
          }
          port {
            container_port = 3306
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


# =================================================================
# CAPA WEB (Nginx Alta Disponibilidad + SSH)
# =================================================================

# CONTENIDO WEB (ConfigMap)
resource "kubernetes_config_map_v1" "web_content" {
  metadata {
    name = "web-content-config"
  }
  data = {
    "index.html" = <<-EOF
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8">
        <title>Cliente SYLO HA</title>
        <style>
          body { font-family: 'Segoe UI', sans-serif; text-align: center; padding: 50px; background-color: #eef2f3; }
          .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); display: inline-block; max-width: 600px; }
          h1 { color = "#0d6efd"; margin-bottom: 10px; }
          .badge { background-color: #28a745; color: white; padding: 5px 10px; border-radius: 5px; font-weight: bold; font-size: 0.9em; }
          .info { color: #666; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px; }
        </style>
      </head>
      <body>
        <div class="card">
          <h1>¡Hola desde SYLO!</h1>
          <p>Tu sitio web está servido por <strong>Nginx en Alta Disponibilidad</strong>.</p>
          <p>DB Cluster: <span class="badge">CONECTADO a mysql-master</span></p>
          <div class="info">
            <p>Este es el Plan ORO: Web HA, DB HA y Acceso SSH.</p>
          </div>
        </div>
      </body>
      </html>
    EOF
  }
}

# SERVIDOR WEB (Deployment HA)
resource "kubernetes_deployment_v1" "web_ha" {
  metadata {
    name = "nginx-ha"
    labels = { cluster = var.cluster_name }
  }

  spec {
    replicas = 2
    selector {
      match_labels = { app = "web-cliente" }
    }
    template {
      metadata {
        labels = { app = "web-cliente" }
      }
      spec {
        container {
          image = "nginx:alpine"
          name  = "nginx"
          port { 
            container_port = 80 
          }
          volume_mount {
            name       = "html-volume"
            mount_path = "/usr/share/nginx/html"
          }
        }
        volume {
          name = "html-volume"
          config_map { name = "web-content-config" }
        }
      }
    }
  }
}

# SERVIDOR SSH (Deployment)
resource "kubernetes_deployment_v1" "ssh_server" {
  metadata {
    name = "ssh-server"
    labels = { cluster = var.cluster_name }
  }
  spec {
    replicas = 1
    selector {
      match_labels = { app = "ssh-server" }
    }
    template {
      metadata {
        labels = { app = "ssh-server" }
      }
      spec {
        container {
          image = "lscr.io/linuxserver/openssh-server:latest"
          name  = "openssh"
          port { 
            container_port = 22 
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
            value = "cliente" 
          }
        }
      }
    }
  }
}

# ACCESO WEB
resource "kubernetes_service_v1" "web_service" {
  metadata { name = "web-service" }
  spec {
    selector = { app = "web-cliente" }
    type = "NodePort"
    port { 
      port = 80
      target_port = 80 
    }
  }
}

# ACCESO SSH
resource "kubernetes_service_v1" "ssh_server_service" {
  metadata { name = "ssh-server-service" }
  spec {
    selector = { app = "ssh-server" }
    type = "NodePort"
    port { 
      port = 22
      target_port = 22 
    }
  }
}

# --- OUTPUTS para el script Bash ---
output "ssh_user" { value = "cliente" }
output "ssh_port" { value = kubernetes_service_v1.ssh_server_service.spec.0.port.0.node_port }
output "web_port" { value = kubernetes_service_v1.web_service.spec.0.port.0.node_port }