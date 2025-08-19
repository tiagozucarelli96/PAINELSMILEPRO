# Dockerfile — Painel Smile PRO (Railway)
FROM php:8.2-fpm-alpine

# Dependências
RUN apk add --no-cache nginx bash postgresql-dev

# Extensões PHP
RUN docker-php-ext-install pdo pdo_pgsql

# App
WORKDIR /var/www
COPY . /var/www

# Pastas do Nginx/FPM
RUN mkdir -p /run/nginx /run/php /etc/nginx/http.d

# Start script
COPY start.sh /start.sh
RUN chmod +x /start.sh && sed -i 's/\r$//' /start.sh

EXPOSE 8080
CMD ["/start.sh"]
