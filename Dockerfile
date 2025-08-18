# Dockerfile — Painel Smile PRO (Railway)
FROM php:8.2-fpm-alpine

# Nginx + Postgres client libs
RUN apk add --no-cache nginx bash libpq-dev postgresql-dev

# Extensões PHP necessárias
RUN docker-php-ext-install pdo pdo_pgsql

# App
WORKDIR /var/www
COPY . /var/www

# Nginx runtime
RUN mkdir -p /run/nginx

# Script de start (gera conf com o $PORT e sobe Nginx + FPM)
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080
CMD ["/start.sh"]
