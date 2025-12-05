# Usamos la imagen oficial de PHP con Apache
FROM php:8.2-apache

# 1. INSTALACIÓN DE DRIVERS MYSQL
# Vital para que el login y el registro funcionen
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 2. PREPARAR EL BUZÓN DE PEDIDOS
# Creamos la carpeta donde la web dejará los archivos JSON
RUN mkdir -p /buzon && \
    chown -R www-data:www-data /buzon && \
    chmod 777 /buzon

# 3. COPIAR EL CÓDIGO WEB
# Copiamos tu index.php, css, imágenes, etc. al servidor
COPY sylo-web /var/www/html/

# 4. AJUSTAR PERMISOS WEB
# Aseguramos que Apache sea el dueño de sus propios archivos
RUN chown -R www-data:www-data /var/www/html

# 5. EXPONER EL PUERTO
EXPOSE 80