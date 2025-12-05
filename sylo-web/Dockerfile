FROM php:8.2-apache

# 1. INSTALAR EXTENSIONES MYSQL (Vital para conectar con la BD)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 2. CONFIGURAR PERMISOS WEB
RUN chown -R www-data:www-data /var/www/html

# 3. CREAR Y CONFIGURAR EL BUZÓN (Para comunicarse con el Orquestador)
RUN mkdir -p /buzon && \
    chown -R www-data:www-data /buzon && \
    chmod 777 /buzon

# 4. COPIAR TU CÓDIGO
COPY sylo-web /var/www/html/

# 5. EXPONER PUERTO
EXPOSE 80