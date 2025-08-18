FROM php:8.2-fpm

RUN apt-get update && apt-get install -y nginx supervisor libpq-dev && docker-php-ext-install pdo pdo_pgsql

COPY start.sh /start.sh
RUN chmod +x /start.sh

COPY nginx.conf /etc/nginx/nginx.conf

WORKDIR /var/www/html
COPY public/ /var/www/html/

CMD ["/start.sh"]
