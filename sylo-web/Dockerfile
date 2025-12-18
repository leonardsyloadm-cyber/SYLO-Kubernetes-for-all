# Usamos la imagen oficial de PHP con Apache (Basada en Debian)
FROM php:8.2-apache

# --- NUEVO: INSTALACIÓN DE DOCKER Y HERRAMIENTAS ---
# Instalamos el cliente de Docker y curl (necesario para bajar cosas)
RUN apt-get update && apt-get install -y \
    docker.io \
    curl \
    sudo \
    && rm -rf /var/lib/apt/lists/*

# --- NUEVO: INSTALACIÓN DE MINIKUBE ---
# Descargamos e instalamos Minikube para que la web pueda usarlo
RUN curl -LO https://storage.googleapis.com/minikube/releases/latest/minikube-linux-amd64 \
    && install minikube-linux-amd64 /usr/local/bin/minikube \
    && rm minikube-linux-amd64

# 1. INSTALACIÓN DE DRIVERS MYSQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 2. PREPARAR EL BUZÓN DE PEDIDOS
RUN mkdir -p /buzon && \
    chown -R www-data:www-data /buzon && \
    chmod 777 /buzon

# 3. COPIAR EL CÓDIGO WEB
COPY . /var/www/html/

# 4. AJUSTAR PERMISOS WEB
RUN chown -R www-data:www-data /var/www/html

# 5. EXPONER EL PUERTO
EXPOSE 80