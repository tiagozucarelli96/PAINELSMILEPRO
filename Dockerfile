# Dockerfile — Painel Smile PRO (Railway)
FROM php:8.2-fpm-alpine

# 1) Pacotes do sistema
RUN apk add --no-cache nginx bash postgresql-dev

# 2) Extensões PHP necessárias
RUN docker-php-ext-install pdo pdo_pgsql

# 3) Estrutura de app
WORKDIR /var/www
RUN mkdir -p /var/www/public /run/nginx /run/php /etc/nginx/http.d

# 4) Copia código do app (somente public) e start.sh
COPY public/ /var/www/public/
COPY start.sh /start.sh

# 5) Permissões e EOL do start.sh
RUN chmod +x /start.sh && sed -i 's/\r$//' /start.sh

# 6) Exposição e entrada
EXPOSE 8080
CMD ["/start.sh"]
