variable "nombre" {
  description = "Nombre del cluster Minikube"
  type        = string
}

# Ya no necesitamos la variable ssh_password aquí
# variable "ssh_password" { ... }

provider "kubernetes" {
  config_path    = pathexpand("~/.kube/config")
  config_context = var.nombre
}

# =================================================================
# CAPA WEB (Nginx Alta Disponibilidad)
# =================================================================

# CONTENIDO WEB
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
          h1 { color: #0d6efd; margin-bottom: 10px; }
          .badge { background-color: #28a745; color: white; padding: 5px 10px; border-radius: 5px; font-weight: bold; font-size: 0.9em; }
          .info { color: #666; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px; }
        </style>
      </head>
      <body>
        <div class="card">
          <h1>¡Hola desde SYLO!</h1>
          <p>Tu sitio web está servido por <strong>Nginx en Alta Disponibilidad</strong>.</p>
          <p>Estado: <span class="badge">OPERATIVO (2 Réplicas)</span></p>
          <div class="info">
            <p>Si uno de los servidores falla, el otro responderá inmediatamente.</p>
            <small>Gestionado por SYLO Orchestrator</small>
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

          resources {
            requests = {
              cpu    = "50m"
              memory = "64Mi"
            }
            limits = {
              cpu    = "200m"
              memory = "128Mi"
            }
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

# ACCESO WEB
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

output "mensaje_exito" {
  value = "Web HA desplegada con 2 réplicas."
}