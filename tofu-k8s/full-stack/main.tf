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

# --- VARIABLES ---
variable "cluster_name" {
  description = "Nombre único del cluster Minikube."
  type        = string
}

variable "ssh_password" {
  description = "Contraseña para el acceso SSH."
  type        = string
  sensitive   = true
}

# Variable de usuario SSH (Para integración con el cliente)
variable "ssh_user" {
  description = "Usuario SSH personalizado"
  type        = string
  default     = "cliente"
}

# =================================================================
# CAPA DE BASE DE DATOS
# =================================================================

resource "kubernetes_service_v1" "mysql_master_service" {
  metadata {
    name = "mysql-master"
    labels = {
      app     = "mysql"
      role    = "master"
      cluster = var.cluster_name
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

resource "kubernetes_stateful_set_v1" "mysql_slave" {
  metadata {
    name = "mysql-slave"
    labels = {
      app     = "mysql"
      role    = "slave"
      cluster = var.cluster_name
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
# CAPA WEB Y SSH
# =================================================================

resource "kubernetes_config_map_v1" "web_content" {
  metadata {
    name = "web-content-config"
  }
  data = {
    "index.html" = <<-EOF
      <!DOCTYPE html>
      <html lang="es">
      <head><title>Cliente SYLO HA</title></head>
      <body><h1>Plan ORO Activo</h1></body>
      </html>
    EOF
  }
}

resource "kubernetes_deployment_v1" "web_ha" {
  metadata {
    name = "nginx-ha"
    labels = {
      cluster = var.cluster_name
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
          app = "web-cliente"
        }
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
          config_map {
            name = "web-content-config"
          }
        }
      }
    }
  }
}

# SERVIDOR SSH
resource "kubernetes_deployment_v1" "ssh_server" {
  metadata {
    name = "ssh-server"
    labels = {
      cluster = var.cluster_name
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
          app = "ssh-server"
        }
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
          
          # VARIABLE DE USUARIO INYECTADA
          env {
            name  = "USER_NAME"
            value = var.ssh_user
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "web_service" {
  metadata {
    name = "web-service"
  }
  spec {
    selector = {
      app = "web-cliente"
    }
    type = "NodePort"
    port {
      port        = 80
      target_port = 80
    }
  }
}

resource "kubernetes_service_v1" "ssh_server_service" {
  metadata {
    name = "ssh-server-service"
  }
  spec {
    selector = {
      app = "ssh-server"
    }
    type = "NodePort"
    port {
      port        = 22
      target_port = 22
    }
  }
}

# OUTPUTS
output "ssh_user" {
  value = var.ssh_user
}

output "ssh_port" {
  value = kubernetes_service_v1.ssh_server_service.spec[0].port[0].node_port
}

output "web_port" {
  value = kubernetes_service_v1.web_service.spec[0].port[0].node_port
}