# ==========================================
# VARIABLES GENERALES
# ==========================================

variable "cluster_name" {
  type = string
}

variable "ssh_password" {
  type      = string
  sensitive = true
}

variable "ssh_user" {
  type    = string
  default = "cliente"
}

# --- NUEVA VARIABLE DE SEGURIDAD (AÑADIDO) ---
variable "owner_id" {
  description = "ID del usuario propietario"
  type        = string
  default     = "admin"
}

# ==========================================
# VARIABLES PERSONALIZADAS
# ==========================================

variable "db_name" {
  type    = string
  default = "custom_db"
}

variable "web_custom_name" {
  type    = string
  default = "Mi Cluster"
}

variable "image_web" {
  type    = string
  default = "nginx:latest"
}

variable "subdomain" {
  type    = string
  default = "demo"
}

# 🔥 VARIABLE CRÍTICA PARA RUTAS (REDHAT/ALPINE) 🔥
variable "web_mount_path" {
  type        = string
  description = "Ruta donde se monta el HTML segun el OS"
  default     = "/usr/share/nginx/html"
}

# 🔥 NUEVA VARIABLE: PUERTO INTERNO (CRÍTICA PARA REDHAT 8080) 🔥
variable "web_port_internal" {
  type        = number
  description = "Puerto del contenedor (80 para Alpine/Ubuntu, 8080 para RedHat)"
  default     = 80
}

# ==========================================
# RECURSOS (HARDWARE)
# ==========================================

variable "cpu" {
  type    = string
  default = "1"
}

variable "ram" {
  type    = string
  default = "2"
}

variable "storage" {
  type    = string
  default = "5"
}

# ==========================================
# TOGGLES (SOFTWARE)
# ==========================================

variable "db_enabled" {
  type    = bool
  default = false
}

variable "db_type" {
  type    = string
  default = "mysql"
}

variable "web_enabled" {
  type    = bool
  default = true
}

variable "web_type" {
  type    = string
  default = "nginx"
}