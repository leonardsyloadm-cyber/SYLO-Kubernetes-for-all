# 1. Usamos la imagen oficial de PHP con Apache
FROM php:8.2-apache

# 2. INSTALACIÓN DE EXTENSIONES (Vital para conectar con KYLO DB)
# Instalamos los drivers de MySQL para que PHP pueda guardar los pedidos
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 3. CONFIGURACIÓN DEL BUZÓN
# Creamos la carpeta donde se dejarán los pedidos para el orquestador
RUN mkdir -p /buzon && \
    chown -R www-data:www-data /buzon && \
    chmod 777 /buzon

# 4. COPIAR LA WEB
# Copiamos el código fuente (index.php, css, etc) al directorio de Apache
COPY sylo-web /var/www/html/

# 5. PERMISOS
# Aseguramos que Apache sea el dueño de los archivos web
RUN chown -R www-data:www-data /var/www/html

# 6. EXPONER PUERTO
EXPOSE 80