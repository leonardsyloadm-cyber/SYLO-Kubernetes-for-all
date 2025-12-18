# ==========================================
# VARIABLES DE INFRAESTRUCTURA GENERAL
# ==========================================

variable "cluster_name" {
  description = "Nombre único del cluster (ID del pedido)."
  type        = string
}

variable "ssh_password" {
  description = "Contraseña generada para el acceso SSH."
  type        = string
  sensitive   = true
}

# ==========================================
# VARIABLES DE RECURSOS (SLIDERS)
# ==========================================

variable "cpu" {
  description = "Número de núcleos de CPU asignados."
  type        = string
  default     = "1"
}

variable "ram" {
  description = "Cantidad de memoria RAM en GB."
  type        = string
  default     = "2"
}

variable "storage" {
  description = "Capacidad del disco persistente en GB."
  # AQUÍ ESTABA EL ERROR:
  type        = string
  default     = "5"
}

# ==========================================
# VARIABLES DE SELECCIÓN (TOGGLES)
# ==========================================

variable "db_enabled" {
  description = "Interruptor para crear o no la Base de Datos."
  type        = bool
  default     = false
}

variable "db_type" {
  description = "Tipo de motor de base de datos."
  type        = string
  default     = "mysql"
}

variable "web_enabled" {
  description = "Interruptor para crear o no el Servidor Web."
  type        = bool
  default     = true
}

variable "web_type" {
  description = "Tipo de servidor web."
  type        = string
  default     = "nginx"
}
variable "ssh_user" {
  description = "Usuario SSH basado en el nombre del cliente"
  type        = string
  default     = "cliente"
}