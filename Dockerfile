# Dockerfile — Painel Smile PRO (Railway)
FROM php:8.2-fpm-alpine

# Dependências
RUN apk add --no-cache nginx bash libpq-dev postgresql-dev

# Extensões PHP
RUN docker-php-ext-install pdo pdo_pgsql

# App
WORKDIR /var/www
COPY . /var/www

# Nginx runtime
RUN mkdir -p /run/nginx

# Start script
COPY start.sh /start.sh
RUN chmod +x /start.sh \
 && sed -i 's/\r$//' /start.sh   # normaliza finais de linha

EXPOSE 8080
CMD ["/start.sh"]
