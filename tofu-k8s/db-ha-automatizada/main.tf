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

# --- VARIABLES ---
variable "nombre" { type = string }
variable "ssh_password" { type = string; sensitive = true }
variable "ssh_user" { type = string; default = "cliente" }
variable "db_name" { type = string; default = "sylo_db" }

# NUEVA VARIABLE DE SEGURIDAD
variable "owner_id" {
  description = "ID del usuario propietario (para aislamiento)"
  type        = string
  default     = "admin"
}

# ==========================================
# 1. MAESTRO MYSQL
# ==========================================
resource "kubernetes_config_map_v1" "mysql_master_config" {
  metadata { name = "mysql-master-config" }
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
    labels = { owner = var.owner_id } # <--- ETIQUETA
  }
  spec {
    selector = { app = "mysql-master" }
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
    labels = { owner = var.owner_id } # <--- ETIQUETA
  }
  spec {
    service_name = "mysql-master"
    replicas     = 1
    selector { match_labels = { app = "mysql-master" } }
    template {
      metadata { 
        labels = { 
          app = "mysql-master" 
          owner = var.owner_id # <--- ETIQUETA POD
        } 
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
          env {
            name  = "MYSQL_USER"
            value = "kylo_user"
          }
          env {
            name  = "MYSQL_PASSWORD"
            value = "kylo_password"
          }
          port { container_port = 3306 }
          readiness_probe {
            exec { command = ["mysqladmin", "ping", "-h", "localhost", "-u", "root", "-ppassword_root"] }
            initial_delay_seconds = 5
            period_seconds        = 2
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
          config_map { name = "mysql-master-config" }
        }
      }
    }
    volume_claim_template {
      metadata { name = "mysql-data" }
      spec {
        access_modes = ["ReadWriteOnce"]
        resources { requests = { storage = "1Gi" } }
      }
    }
  }
}

# ==========================================
# 2. ESCLAVO MYSQL
# ==========================================
resource "kubernetes_config_map_v1" "mysql_slave_config" {
  metadata { name = "mysql-slave-config" }
  data = {
    "my.cnf" = <<-EOF
      [mysqld]
      server-id = 2
      relay-log = /var/lib/mysql/mysql-relay-bin.log
      default_authentication_plugin=mysql_native_password
    EOF
  }
}

resource "kubernetes_service_v1" "mysql_slave" {
  metadata { 
    name = "mysql-slave"
    labels = { owner = var.owner_id } # <--- ETIQUETA
  }
  spec {
    selector = { app = "mysql-slave" }
    port {
      port        = 3306
      target_port = 3306
    }
    type = "ClusterIP"
  }
}

resource "kubernetes_stateful_set_v1" "mysql_slave" {
  metadata { 
    name = "mysql-slave"
    labels = { owner = var.owner_id } # <--- ETIQUETA
  }
  spec {
    service_name = "mysql-slave"
    replicas     = 1
    selector { match_labels = { app = "mysql-slave" } }
    template {
      metadata { 
        labels = { 
          app = "mysql-slave" 
          owner = var.owner_id # <--- ETIQUETA POD
        } 
      }
      spec {
        container {
          name  = "mysql"
          image = "mysql:8.0"
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          port { container_port = 3306 }
          readiness_probe {
            exec { command = ["mysqladmin", "ping", "-h", "localhost", "-u", "root", "-ppassword_root"] }
            initial_delay_seconds = 5
            period_seconds        = 2
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
          config_map { name = "mysql-slave-config" }
        }
      }
    }
    volume_claim_template {
      metadata { name = "mysql-data" }
      spec {
        access_modes = ["ReadWriteOnce"]
        resources { requests = { storage = "1Gi" } }
      }
    }
  }
}

# ==========================================
# 3. SERVIDOR SSH (BASTIÓN)
# ==========================================
resource "kubernetes_deployment_v1" "ssh_box" {
  metadata { 
    name = "ssh-server" 
    labels = { owner = var.owner_id } # <--- ETIQUETA
  }
  spec {
    replicas = 1
    selector { match_labels = { app = "ssh" } }
    template {
      metadata { 
        labels = { 
          app = "ssh" 
          owner = var.owner_id # <--- ETIQUETA POD
        } 
      }
      spec {
        container {
          name  = "ubuntu"
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
          port { container_port = 2222 }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "ssh_service" {
  metadata { 
    name = "ssh-access"
    labels = { owner = var.owner_id } # <--- ETIQUETA
  }
  spec {
    selector = { app = "ssh" }
    type = "NodePort"
    port {
      port        = 22
      target_port = 2222
    }
  }
}

# ==========================================
# 4. SEGURIDAD (NETWORK POLICY)
# ==========================================
resource "kubernetes_network_policy" "aislamiento_plata" {
  metadata {
    name = "aislamiento-plata"
  }

  spec {
    pod_selector {} # Aplica a todos

    policy_types = ["Ingress"]

    ingress {
      # REGLA: Tráfico interno permitido solo si tienes el mismo owner
      # (Esto permite que el Maestro y el Esclavo se hablen)
      from {
        pod_selector {
          match_labels = {
            owner = var.owner_id
          }
        }
      }
      
      # REGLA: Permitir SSH desde fuera
      ports {
        port     = 2222
        protocol = "TCP"
      }
      
      # REGLA: Permitir MySQL (3306) SOLO si viene del mismo owner
      # (Ya cubierto arriba, pero para claridad)
      ports {
        port     = 3306
        protocol = "TCP"
      }
    }
  }
}

output "ssh_port" {
  value = kubernetes_service_v1.ssh_service.spec[0].port[0].node_port
}