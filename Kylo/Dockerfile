# --- ETAPA 1: COMPILACIÓN (BUILD) ---
# Usamos una imagen que ya tiene Maven y Java 21 instalados
FROM maven:3.9-eclipse-temurin-21 AS build

# Creamos directorio de trabajo
WORKDIR /app

# Copiamos solo el pom.xml primero para aprovechar la caché de Docker
COPY pom.xml .

# Bajamos las dependencias (esto tardará un poco la primera vez)
RUN mvn dependency:go-offline

# Copiamos el código fuente
COPY src ./src

# Compilamos el proyecto y creamos el JAR
RUN mvn package -DskipTests

# --- ETAPA 2: EJECUCIÓN (RUN) ---
# Usamos una imagen ligera solo con Java (sin Maven) para correrlo
FROM eclipse-temurin:21-jre-alpine

WORKDIR /app

# Copiamos el JAR que fabricamos en la etapa 1
COPY --from=build /app/target/kylo-db-1.0-SNAPSHOT.jar app.jar

# Exponemos los puertos (8080 para la Web GUI, 5000 para datos)
EXPOSE 8080
EXPOSE 5000
EXPOSE 3307

# Comando de arranque
CMD ["java", "-jar", "app.jar"]