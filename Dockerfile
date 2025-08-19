# Dockerfile — Painel Smile Paliativo (PHP + Apache)
FROM php:8.2-apache

# Extensões PHP usadas no projeto
RUN docker-php-ext-install pdo pdo_mysql

# Utilitários leves (p/ healthcheck)
RUN apt-get update && apt-get install -y curl && rm -rf /var/lib/apt/lists/*

# Habilita módulos úteis
RUN a2enmod rewrite headers expires

# Ajusta DocumentRoot para /var/www/public
RUN sed -i 's#/var/www/html#/var/www/public#g' /etc/apache2/sites-available/000-default.conf && \
    sed -i 's#/var/www/html#/var/www/public#g' /etc/apache2/apache2.conf && \
    printf "<Directory /var/www/public>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n" \
      > /etc/apache2/conf-available/public-override.conf && \
    a2enconf public-override

# Copia o repositório inteiro (mantém /public)
WORKDIR /var/www
COPY . /var/www

# Permissões básicas (www-data) — seguro para leitura/execução
RUN chown -R www-data:www-data /var/www

# Healthcheck usa o arquivo que você já tem: public/healthz.txt
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD curl -fsS http://localhost/healthz.txt || exit 1

EXPOSE 80

# Usa o start.sh para log inicial e subir Apache
COPY start.sh /start.sh
RUN chmod +x /start.sh
CMD ["/start.sh"]
