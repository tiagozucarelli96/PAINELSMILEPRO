# Dockerfile — Painel Smile (PHP server embutido, só Postgres)
FROM php:8.2-cli

# Apenas Postgres
RUN apt-get update && apt-get install -y libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Sobe o PHP embutido servindo /public na porta do Railway
EXPOSE 8080
CMD ["bash", "-lc", "php -S 0.0.0.0:${PORT:-8080} -t public public/index.php"]
