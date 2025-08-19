# Dockerfile — PHP embutido, só Postgres
FROM php:8.2-cli

RUN apt-get update && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

EXPOSE 8080
# IMPORTANTE: usa o router.php (e não o index.php) para evitar loops
CMD ["bash", "-lc", "php -S 0.0.0.0:${PORT:-8080} -t public public/router.php"]
