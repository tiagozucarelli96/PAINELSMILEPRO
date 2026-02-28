# Dockerfile — PHP embutido, só Postgres
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libgmp-dev \
    libzip-dev \
    zlib1g-dev \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libheif-examples \
    imagemagick \
    libmagickwand-dev \
    poppler-utils \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo_pgsql bcmath gmp zip \
 && pecl install imagick \
 && docker-php-ext-enable imagick \
 && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Instalar dependências do Composer (AWS SDK, Dompdf, PHPMailer, Resend)
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Regenerar autoload para garantir que todas as classes estejam mapeadas
RUN composer dump-autoload --optimize --no-interaction || true

# Verificar se as principais dependências foram instaladas (para debug)
RUN composer show dompdf/dompdf 2>/dev/null || echo "Dompdf não encontrado"
RUN composer show phpmailer/phpmailer 2>/dev/null || echo "PHPMailer não encontrado"
RUN composer show resend/resend-php 2>/dev/null || echo "Resend não encontrado"

EXPOSE 8080

# Tornar script executável
RUN chmod +x /app/start_railway.sh

# IMPORTANTE: usa o router.php (e não o index.php) para evitar loops
# Usar script wrapper para garantir expansão correta da variável PORT
CMD ["/app/start_railway.sh"]
