# Usamos la imagen oficial de PHP con Apache
FROM php:8.2-apache

# Habilitamos permisos para que Apache pueda escribir
RUN chown -R www-data:www-data /var/www/html

# Creamos la carpeta del buzón dentro del contenedor para que exista el punto de montaje
RUN mkdir -p /buzon && chown -R www-data:www-data /buzon && chmod 777 /buzon

# Copiamos tu código web dentro del servidor
COPY sylo-web /var/www/html/

# Exponemos el puerto 80
EXPOSE 80
