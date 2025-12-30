terraform {
  required_providers {
    kubernetes = {
      source = "hashicorp/kubernetes"
    }
  }
}

# --- VARIABLES ---
variable "nombre" {
  description = "Nombre del cluster Minikube"
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
  description = "Nombre de la Base de Datos inicial"
  type        = string
  default     = "sylo_db"
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.nombre
}

# ==========================================
# 1. MAESTRO MYSQL
# ==========================================
resource "kubernetes_config_map_v1" "mysql_master_config" {
  metadata {
    name = "mysql-master-config"
  }
  data = {
    "my.cnf" = <<-EOF
      [mysqld]
      server-id = 1
      log_bin = /var/lib/mysql/mysql-bin.log
      binlog_format = ROW
      default_authentication_plugin=mysql_native_password
    EOF
  }
}

resource "kubernetes_service_v1" "mysql_master" {
  metadata {
    name = "mysql-master"
  }
  spec {
    # CORREGIDO: selector lleva =
    selector = {
      app = "mysql-master"
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
  }
  spec {
    service_name = "mysql-master"
    replicas     = 1
    
    selector {
      match_labels = {
        app = "mysql-master"
      }
    }

    template {
      metadata {
        labels = {
          app = "mysql-master"
        }
      }
      spec {
        container {
          name              = "mysql"
          image             = "mysql:8.0"
          image_pull_policy = "IfNotPresent"

          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          
          env {
            name  = "MYSQL_DATABASE"
            value = var.db_name
          }
          
          env {
            name  = "MYSQL_USER"
            value = "kylo_user"
          }
          env {
            name  = "MYSQL_PASSWORD"
            value = "kylo_password"
          }

          port {
            container_port = 3306
          }

          readiness_probe {
            exec {
              command = ["mysqladmin", "ping", "-h", "localhost", "-u", "root", "-ppassword_root"]
            }
            initial_delay_seconds = 5
            period_seconds        = 2
          }

          resources {
            limits = {
              cpu    = "1000m"
              memory = "1Gi"
            }
            requests = {
              cpu    = "500m"
              memory = "512Mi"
            }
          }

          volume_mount {
            name       = "mysql-data"
            mount_path = "/var/lib/mysql"
          }
          volume_mount {
            name       = "config"
            mount_path = "/etc/mysql/conf.d"
          }
        }
        
        volume {
          name = "config"
          config_map {
            name = "mysql-master-config"
          }
        }
      }
    }

    volume_claim_template {
      metadata {
        name = "mysql-data"
      }
      spec {
        access_modes = ["ReadWriteOnce"]
        resources {
          # CORREGIDO: requests lleva =
          requests = {
            storage = "1Gi"
          }
        }
      }
    }
  }
}

# ==========================================
# 2. SERVIDOR SSH (BASTIÓN)
# ==========================================
resource "kubernetes_deployment_v1" "ssh_box" {
  metadata {
    name = "ssh-server"
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
          name              = "ubuntu"
          image             = "lscr.io/linuxserver/openssh-server:latest"
          image_pull_policy = "IfNotPresent"
          
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
    # CORREGIDO: selector lleva =
    selector = {
      app = "ssh"
    }
    type = "NodePort"
    
    port {
      port        = 22
      target_port = 2222
    }
  }
}

output "ssh_port" {
  value = kubernetes_service_v1.ssh_service.spec[0].port[0].node_port
}