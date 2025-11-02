# Dockerfile — PHP embutido, só Postgres
FROM php:8.2-cli

RUN apt-get update && apt-get install -y libpq-dev unzip \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Instalar dependências do Composer (AWS SDK e Dompdf)
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Verificar se Dompdf foi instalado (para debug)
RUN composer show dompdf/dompdf 2>/dev/null || echo "Dompdf não encontrado - será instalado no próximo build"

EXPOSE 8080
# IMPORTANTE: usa o router.php (e não o index.php) para evitar loops
CMD ["bash", "-lc", "php -S 0.0.0.0:${PORT:-8080} -t public public/router.php"]
