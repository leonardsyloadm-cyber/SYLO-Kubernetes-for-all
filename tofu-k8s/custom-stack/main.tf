terraform {
  required_providers {
    kubernetes = { source = "hashicorp/kubernetes" }
  }
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.cluster_name
}

# ==========================================================
# 1. ALMACENAMIENTO (PVC)
# ==========================================================
resource "kubernetes_persistent_volume_claim" "custom_storage" {
  metadata { 
    name = "custom-pvc" 
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

# ==========================================================
# 2. CAPA DE DATOS (DB)
# ==========================================================

# --- MySQL ---
resource "kubernetes_deployment_v1" "db_mysql" {
  count = var.db_enabled && var.db_type == "mysql" ? 1 : 0
  metadata { 
    name = "custom-db" 
  }
  spec {
    replicas = 1
    selector { 
      match_labels = { app = "custom-db" } 
    }
    template {
      metadata { 
        labels = { app = "custom-db" } 
      }
      spec {
        container {
          name  = "mysql"
          image = "mysql:5.7"
          resources {
            limits = {
              cpu    = var.web_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.web_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
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
  metadata { name = "custom-db-service" }
  spec {
    selector = { app = "custom-db" }
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
  metadata { name = "custom-db" }
  spec {
    replicas = 1
    selector { match_labels = { app = "custom-db" } }
    template {
      metadata { labels = { app = "custom-db" } }
      spec {
        container {
          name  = "postgres"
          image = "postgres:14"
          resources {
            limits = {
              cpu    = var.web_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.web_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
            }
          }
          port { container_port = 5432 }
          
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
          persistent_volume_claim { claim_name = "custom-pvc" }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "svc_postgres" {
  count = var.db_enabled && var.db_type == "postgresql" ? 1 : 0
  metadata { name = "custom-db-service" }
  spec {
    selector = { app = "custom-db" }
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
  metadata { name = "custom-db" }
  spec {
    replicas = 1
    selector { match_labels = { app = "custom-db" } }
    template {
      metadata { labels = { app = "custom-db" } }
      spec {
        container {
          name  = "mongo"
          image = "mongo:latest"
          resources {
            limits = {
              cpu    = var.web_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.web_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
            }
          }
          port { container_port = 27017 }
          
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
          persistent_volume_claim { claim_name = "custom-pvc" }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "svc_mongo" {
  count = var.db_enabled && var.db_type == "mongodb" ? 1 : 0
  metadata { name = "custom-db-service" }
  spec {
    selector = { app = "custom-db" }
    type = "ClusterIP"
    port { 
      port        = 27017
      target_port = 27017 
    }
  }
}

# ==========================================================
# 3. CAPA WEB (NGINX / APACHE)
# ==========================================================

resource "kubernetes_config_map_v1" "web_content" {
  count = var.web_enabled ? 1 : 0
  metadata { name = "custom-web-content" }
  data = {
    "index.html" = <<-EOF
      <h1>${var.web_custom_name}</h1>
      <p>Subdominio: ${var.subdomain}.sylobi.org</p>
      <hr>
      <p>CPU: ${var.cpu} / RAM: ${var.ram} GB</p>
      <p>DB: ${var.db_enabled ? "${var.db_type} (${var.db_name})" : "No"}</p>
    EOF
  }
}

# --- NGINX ---
resource "kubernetes_deployment_v1" "web_nginx" {
  count = var.web_enabled && var.web_type == "nginx" ? 1 : 0
  metadata { name = "custom-web" }
  spec {
    replicas = 1
    selector { match_labels = { app = "custom-web" } }
    template {
      metadata { labels = { app = "custom-web" } }
      spec {
        # WEB CONTAINER
        container {
          name  = "web-server"
          image = var.image_web
          resources {
            limits = {
              cpu    = var.db_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.db_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
            }
          }
          
          # 🔥 PUERTO DINAMICO 🔥
          port { 
            container_port = var.web_port_internal 
          }
          
          # 🔥 RUTA DINAMICA 🔥
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
        }
        
        # SSH SIDECAR
        container {
          name  = "ssh"
          image = "lscr.io/linuxserver/openssh-server:latest"
          port { container_port = 2222 }
          
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

        volume {
          name = "html-vol"
          config_map { name = "custom-web-content" }
        }
      }
    }
  }
}

# --- APACHE ---
resource "kubernetes_deployment_v1" "web_apache" {
  count = var.web_enabled && var.web_type == "apache" ? 1 : 0
  metadata { name = "custom-web" }
  spec {
    replicas = 1
    selector { match_labels = { app = "custom-web" } }
    template {
      metadata { labels = { app = "custom-web" } }
      spec {
        # WEB CONTAINER
        container {
          name  = "web-server"
          image = var.image_web
          resources {
            limits = {
              cpu    = var.db_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.db_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
            }
          }
          
          # 🔥 PUERTO DINAMICO 🔥
          port { 
            container_port = var.web_port_internal 
          }

          # 🔥 RUTA DINAMICA 🔥
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
        }
        
        # SSH SIDECAR
        container {
          name  = "ssh"
          image = "lscr.io/linuxserver/openssh-server:latest"
          port { container_port = 2222 }
          
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
        
        volume {
          name = "html-vol"
          config_map { name = "custom-web-content" }
        }
      }
    }
  }
}

# ==========================================================
# 4. SERVICIO WEB
# ==========================================================
resource "kubernetes_service_v1" "web_service" {
  count = var.web_enabled ? 1 : 0
  metadata { 
    name = "web-service" 
  }
  spec {
    selector = { app = "custom-web" }
    type = "NodePort"
    port { 
      name        = "http"
      port        = 80
      # 🔥 TARGET PORT DINAMICO (80 o 8080) 🔥
      target_port = var.web_port_internal 
    }
    port { 
      name        = "ssh"
      port        = 22
      target_port = 2222 
    }
  }
}

output "web_port" { 
  value = var.web_enabled ? try(kubernetes_service_v1.web_service[0].spec.0.port.0.node_port, "N/A") : "N/A" 
}
output "ssh_port" { 
  value = var.web_enabled ? try(kubernetes_service_v1.web_service[0].spec.0.port.1.node_port, "N/A") : "N/A" 
}