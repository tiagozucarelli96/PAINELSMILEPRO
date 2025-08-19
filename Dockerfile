# Dockerfile — PHP embutido (sem Apache)
FROM php:8.2-cli

# Extensões que você usa
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . /app

# Sobe o servidor embutido do PHP na porta exigida pelo Railway
# -t public => serve a pasta /app/public
# entrypoint único (sem start.sh)
EXPOSE 8080
CMD ["bash", "-lc", "php -S 0.0.0.0:${PORT:-8080} -t public public/index.php"]
