# --- CONFIGURACIÓN DEL ESCLAVO ---

resource "kubernetes_config_map_v1" "mysql_slave_config" {
  metadata {
    name = "mysql-slave-config"
  }
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
  }
  spec {
    selector = {
      app = "mysql-slave"
    }
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
  }
  spec {
    service_name = "mysql-slave"
    replicas     = 1
    
    selector {
      match_labels = {
        app = "mysql-slave"
      }
    }

    template {
      metadata {
        labels = {
          app = "mysql-slave"
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
            requests = {
              cpu    = "500m"
              memory = "512Mi"
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
            name = "mysql-slave-config"
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