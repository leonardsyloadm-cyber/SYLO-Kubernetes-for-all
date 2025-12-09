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

# ==========================================================
# 1. ALMACENAMIENTO PERSISTENTE
# ==========================================================
resource "kubernetes_persistent_volume_claim" "custom_storage" {
  metadata {
    name = "custom-pvc"
  }
  spec {
    access_modes = ["ReadWriteOnce"]
    # CORREGIDO: Bloque resources expandido
    resources {
      requests = {
        storage = "${var.storage}Gi"
      }
    }
  }
}

# ==========================================================
# 2. CAPA DE DATOS (Selección Lógica)
# ==========================================================

# --- OPCIÓN A: MySQL ---
resource "kubernetes_deployment_v1" "db_mysql" {
  count = var.db_enabled && var.db_type == "mysql" ? 1 : 0
  
  metadata {
    name = "custom-db"
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
          app = "custom-db"
        }
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
          
          # CORREGIDO: Bloques env expandidos
          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "root"
          }
          env {
            name  = "MYSQL_DATABASE"
            value = "custom_db"
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
  metadata {
    name = "custom-db-service"
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

# --- OPCIÓN B: PostgreSQL ---
resource "kubernetes_deployment_v1" "db_postgres" {
  count = var.db_enabled && var.db_type == "postgresql" ? 1 : 0
  
  metadata {
    name = "custom-db"
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
          app = "custom-db"
        }
      }
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

          port {
            container_port = 5432
          }

          env {
            name  = "POSTGRES_PASSWORD"
            value = "root"
          }
          env {
            name  = "POSTGRES_DB"
            value = "custom_db"
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

# --- OPCIÓN C: MongoDB ---
resource "kubernetes_deployment_v1" "db_mongo" {
  count = var.db_enabled && var.db_type == "mongodb" ? 1 : 0
  
  metadata {
    name = "custom-db"
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
          app = "custom-db"
        }
      }
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

# ==========================================================
# 3. CAPA WEB (Selección Lógica)
# ==========================================================

resource "kubernetes_config_map_v1" "web_content" {
  count = var.web_enabled ? 1 : 0
  metadata {
    name = "custom-web-content"
  }
  data = {
    "index.html" = <<-EOF
      <h1>Plan Personalizado Activo</h1>
      <p>CPU Asignada: ${var.cpu} Cores</p>
      <p>RAM Asignada: ${var.ram} GB</p>
      <p>Almacenamiento: ${var.storage} GB</p>
      <hr>
      <p>Web Server: <strong>${var.web_type}</strong></p>
      <p>Base de Datos: <strong>${var.db_enabled ? var.db_type : "Ninguna"}</strong></p>
    EOF
  }
}

# --- OPCIÓN A: Nginx ---
resource "kubernetes_deployment_v1" "web_nginx" {
  count = var.web_enabled && var.web_type == "nginx" ? 1 : 0
  
  metadata {
    name = "custom-web"
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
          app = "custom-web"
        }
      }
      spec {
        container {
          name  = "web-server"
          image = "nginx:alpine"
          
          resources {
            limits = {
              cpu    = var.db_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.db_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
            }
          }

          port {
            container_port = 80
          }
          
          volume_mount {
            name       = "html-vol"
            mount_path = "/usr/share/nginx/html"
          }

          dynamic "env" {
            for_each = var.db_enabled ? [1] : []
            content {
              name  = "DB_HOST"
              value = "custom-db-service"
            }
          }
        }

        # Contenedor SSH
        container {
          name  = "ssh"
          image = "lscr.io/linuxserver/openssh-server:latest"
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
            value = "cliente"
          }
          # Variable para SUDO
          env {
            name  = "SUDO_ACCESS"
            value = "true"
          }
        }

        volume {
          name = "html-vol"
          config_map {
            name = "custom-web-content"
          }
        }
      }
    }
  }
}

# --- OPCIÓN B: Apache (httpd) ---
resource "kubernetes_deployment_v1" "web_apache" {
  count = var.web_enabled && var.web_type == "apache" ? 1 : 0
  
  metadata {
    name = "custom-web"
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
          app = "custom-web"
        }
      }
      spec {
        container {
          name  = "web-server"
          image = "httpd:alpine"
          
          resources {
            limits = {
              cpu    = var.db_enabled ? "${var.cpu / 2}" : "${var.cpu}"
              memory = var.db_enabled ? "${var.ram / 2}Gi" : "${var.ram}Gi"
            }
          }

          port {
            container_port = 80
          }
          
          volume_mount {
            name       = "html-vol"
            mount_path = "/usr/local/apache2/htdocs/"
          }

          dynamic "env" {
            for_each = var.db_enabled ? [1] : []
            content {
              name  = "DB_HOST"
              value = "custom-db-service"
            }
          }
        }

        # Contenedor SSH
        container {
          name  = "ssh"
          image = "lscr.io/linuxserver/openssh-server:latest"
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
            value = "cliente"
          }
          # Variable para SUDO
          env {
            name  = "SUDO_ACCESS"
            value = "true"
          }
        }

        volume {
          name = "html-vol"
          config_map {
            name = "custom-web-content"
          }
        }
      }
    }
  }
}

# --- SERVICIO WEB UNIFICADO ---
resource "kubernetes_service_v1" "web_service" {
  count = var.web_enabled ? 1 : 0
  metadata {
    name = "custom-web-service"
  }
  spec {
    selector = {
      app = "custom-web"
    }
    type = "NodePort"
    port {
      name        = "http"
      port        = 80
      target_port = 80
    }
    port {
      name        = "ssh"
      port        = 22
      target_port = 2222
    }
  }
}

# --- OUTPUTS ---
output "web_port" {
  value = var.web_enabled ? try(kubernetes_service_v1.web_service[0].spec.0.port.0.node_port, "N/A") : "N/A"
}
output "ssh_port" {
  value = var.web_enabled ? try(kubernetes_service_v1.web_service[0].spec.0.port.1.node_port, "N/A") : "N/A"
}