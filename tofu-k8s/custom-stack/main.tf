terraform {
  required_providers {
    kubernetes = {
      source = "hashicorp/kubernetes"
    }
  }
}

variable "kubeconfig_path" {
  type = string
}

provider "kubernetes" {
  config_path = var.kubeconfig_path
}

# ==========================================
# ALMACENAMIENTO (PVC)
# ==========================================
resource "kubernetes_persistent_volume_claim" "custom_storage" {
  metadata { 
    name = "custom-pvc" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    access_modes = ["ReadWriteOnce"]
    resources { 
      requests = {
        storage = "${var.storage}Gi"
      } 
    }
  }
}

# ==========================================
# TOOLKIT PVC (Externally Managed by Job)
# ==========================================
# We assume "sylo-toolkit-pvc" exists because we ran toolkit-loader.yaml
# We just reference it in volumes.

# ==========================================
# BASE DE DATOS (CONDICIONAL)
# ==========================================

# --- MySQL ---
resource "kubernetes_deployment_v1" "db_mysql" {
  count = var.db_enabled && var.db_type == "mysql" ? 1 : 0
  metadata { 
    name = "custom-db" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "custom-db"
      }
    }
    template {
      metadata {
        labels = {
          app   = "custom-db"
          owner = var.owner_id
        }
      }
      spec {
        container {
          name  = "mysql"
          image = "mysql:5.7"

          resources {
            requests = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
            limits = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
          }
          
          port {
            container_port = 3306
          }
          
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "root"
          }
          env {
            name  = "MYSQL_DATABASE"
            value = var.db_name
          }
          
          volume_mount { 
            name       = "storage-vol"
            mount_path = "/var/lib/mysql" 
          }
          
          readiness_probe {
            tcp_socket {
              port = 3306
            }
            initial_delay_seconds = 15
            period_seconds        = 5
          }
        }
        volume {
          name = "storage-vol"
          persistent_volume_claim {
            claim_name = "custom-pvc"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "svc_mysql" {
  count = var.db_enabled && var.db_type == "mysql" ? 1 : 0
  metadata { 
    name = "custom-db-service" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    selector = {
      app = "custom-db"
    }
    type = "ClusterIP"
    port {
      port        = 3306
      target_port = 3306
    }
  }
}

# --- PostgreSQL ---
resource "kubernetes_deployment_v1" "db_postgres" {
  count = var.db_enabled && var.db_type == "postgresql" ? 1 : 0
  metadata { 
    name = "custom-db" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "custom-db"
      }
    }
    template {
      metadata {
        labels = {
          app   = "custom-db"
          owner = var.owner_id
        }
      }
      spec {
        container {
          name  = "postgres"
          image = "postgres:14"

          resources {
            requests = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
            limits = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
          }
          
          port {
            container_port = 5432
          }
          
          env {
            name  = "POSTGRES_PASSWORD"
            value = "root"
          }
          env {
            name  = "POSTGRES_DB"
            value = var.db_name
          }
          
          volume_mount { 
            name       = "storage-vol"
            mount_path = "/var/lib/postgresql/data" 
          }
        }
        volume {
          name = "storage-vol"
          persistent_volume_claim {
            claim_name = "custom-pvc"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "svc_postgres" {
  count = var.db_enabled && var.db_type == "postgresql" ? 1 : 0
  metadata { 
    name = "custom-db-service" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    selector = {
      app = "custom-db"
    }
    type = "ClusterIP"
    port {
      port        = 5432
      target_port = 5432
    }
  }
}

# --- MongoDB ---
resource "kubernetes_deployment_v1" "db_mongo" {
  count = var.db_enabled && var.db_type == "mongodb" ? 1 : 0
  metadata { 
    name = "custom-db" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "custom-db"
      }
    }
    template {
      metadata {
        labels = {
          app   = "custom-db"
          owner = var.owner_id
        }
      }
      spec {
        container {
          name  = "mongo"
          image = "mongo:latest"

          resources {
            requests = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
            limits = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
          }
          
          port {
            container_port = 27017
          }
          
          env {
            name  = "MONGO_INITDB_ROOT_USERNAME"
            value = "root"
          }
          env {
            name  = "MONGO_INITDB_ROOT_PASSWORD"
            value = "root"
          }
          
          volume_mount { 
            name       = "storage-vol"
            mount_path = "/data/db" 
          }
        }
        volume {
          name = "storage-vol"
          persistent_volume_claim {
            claim_name = "custom-pvc"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "svc_mongo" {
  count = var.db_enabled && var.db_type == "mongodb" ? 1 : 0
  metadata { 
    name = "custom-db-service" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    selector = {
      app = "custom-db"
    }
    type = "ClusterIP"
    port {
      port        = 27017
      target_port = 27017
    }
  }
}

# ==========================================
# WEB SERVER (CONDICIONAL)
# ==========================================

resource "kubernetes_config_map_v1" "web_content" {
  count = var.web_enabled ? 1 : 0
  metadata {
    name = "custom-web-content"
  }
  data = {
    "index.html" = <<-EOF
      <h1>${var.web_custom_name}</h1>
      <p>Subdominio: ${var.subdomain}.sylobi.org</p>
      <hr>
      <p>CPU: ${var.cpu} / RAM: ${var.ram} GB</p>
      <p>DB: ${var.db_enabled ? "${var.db_type} (${var.db_name})" : "No"}</p>
      <p><small>Owner ID: ${var.owner_id}</small></p>
    EOF
  }
}

resource "kubernetes_deployment_v1" "web_server" {
  count = var.web_enabled ? 1 : 0
  metadata { 
    name = "custom-web" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    replicas = 1
    selector {
      match_labels = {
        app = "custom-web"
      }
    }
    template {
      metadata {
        labels = {
          app   = "custom-web"
          owner = var.owner_id
        }
      }
      spec {
        container {
          name  = "web-server"
          image = var.image_web

          resources {
            requests = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
            limits = {
              memory = "${var.ram}Gi"
              cpu    = var.cpu
            }
          }
          
          port {
            container_port = var.web_port_internal
          }
          
          volume_mount { 
            name       = "html-vol"
            mount_path = var.web_mount_path 
          }
          
          dynamic "env" {
            for_each = var.db_enabled ? [1] : []
            content { 
              name  = "DB_HOST"
              value = "custom-db-service" 
            }
          }

          # --- TOOLKIT ---
          env {
            name  = "PATH"
            value = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/sylo-tools/bin"
          }
          volume_mount {
            name       = "toolkit-vol"
            mount_path = "/opt/sylo-tools"
            read_only  = true
          }
        }
        volume {
          name = "html-vol"
          config_map {
            name = "custom-web-content"
          }
        }
        volume {
          name = "toolkit-vol"
          persistent_volume_claim {
            claim_name = "sylo-toolkit-pvc"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "web_service" {
  count = var.web_enabled ? 1 : 0
  metadata { 
    name = "web-service" 
    labels = {
      owner = var.owner_id
    }
  }
  spec {
    selector = {
      app = "custom-web"
    }
    type = "NodePort"
    port { 
      port        = 80
      target_port = var.web_port_internal 
    }
  }
}

# ==========================================
# SSH SERVER (SIEMPRE ACTIVO)
# ==========================================
locals {
  is_alpine_ssh  = var.os_image == "alpine"
  ssh_image_real = local.is_alpine_ssh ? "lscr.io/linuxserver/openssh-server:latest" : "rastasheep/ubuntu-sshd:18.04"
  ssh_port_real  = local.is_alpine_ssh ? 2222 : 22
}

resource "kubernetes_deployment_v1" "ssh_server" {
  metadata {
    name = "ssh-server"
    labels = {
      owner = var.owner_id
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
          app   = "ssh-server"
          owner = var.owner_id
        }
      }
      spec {
        container {
          name  = "ssh"
          image = local.ssh_image_real
          
          port {
            container_port = local.ssh_port_real
          }
          
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
          
          # --- TOOLKIT ---
          env {
            name  = "PATH"
            value = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/sylo-tools/bin"
          }
          volume_mount {
            name       = "toolkit-vol"
            mount_path = "/opt/sylo-tools"
            read_only  = true
          }
        }
        volume {
          name = "toolkit-vol"
          persistent_volume_claim {
            claim_name = "sylo-toolkit-pvc"
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "ssh_service" {
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
      target_port = local.ssh_port_real
    }
  }
}

# ==========================================
# SEGURIDAD (NETWORK POLICY)
# ==========================================
resource "kubernetes_network_policy_v1" "aislamiento_custom" {
  metadata {
    name = "aislamiento-custom"
  }
  spec {
    pod_selector {} 
    policy_types = ["Ingress"]
    
    # 1. Tráfico Interno
    ingress {
      from {
        pod_selector {
          match_labels = {
            owner = var.owner_id
          }
        }
      }
    }
    
    # 2. Acceso Externo SSH y Web
    ingress {
      from {
        ip_block {
          cidr = "0.0.0.0/0"
        }
      }
      ports {
        port     = local.ssh_port_real
        protocol = "TCP"
      }
      ports {
        port     = var.web_port_internal
        protocol = "TCP"
      }
    }
  }
}

# ==========================================
# OUTPUTS
# ==========================================
output "web_port" { 
  value = var.web_enabled ? try(kubernetes_service_v1.web_service[0].spec.0.port.0.node_port, "N/A") : "N/A" 
}
output "ssh_port" { 
  value = kubernetes_service_v1.ssh_service.spec[0].port[0].node_port
}