# --- CONFIGURACIÓN GLOBAL ---

variable "nombre" {
  description = "Nombre del cluster Minikube"
  type        = string
}

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.nombre
}

# --- CONFIGURACIÓN DEL MAESTRO ---

resource "kubernetes_config_map" "mysql_master_config" {
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

resource "kubernetes_service" "mysql_master" {
  metadata {
    name = "mysql-master"
  }
  spec {
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

resource "kubernetes_stateful_set" "mysql_master" {
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
          name  = "mysql"
          image = "mysql:8.0"

          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = "password_root"
          }
          env {
            name  = "MYSQL_DATABASE"
            value = "kylo_db"
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

          resources {
            requests = {
              cpu    = "250m"
              memory = "256Mi"
            }
            limits = {
              cpu    = "1000m"
              memory = "1Gi"
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
          requests = {
            storage = "1Gi"
          }
        }
      }
    }
  }
}
