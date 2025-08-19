#!/usr/bin/env sh
set -e

# Web em 8080 (porta pública do serviço)
WEBPORT="8080"
# PHP-FPM na porta padrão 9000 (interna)
FPM_PORT="9000"

# Pastas necessárias
mkdir -p /run/nginx /etc/nginx/http.d

# Força o PHP-FPM a ouvir em 127.0.0.1:9000
CONF="/usr/local/etc/php-fpm.d/www.conf"
sed -ri "s@^listen\s*=.*@listen = 127.0.0.1:${FPM_PORT}@" "$CONF"
sed -ri 's@^;?clear_env\s*=.*@clear_env = no@' "$CONF"

# Nginx ouvindo em 8080 e repassando para o FPM:9000
cat > /etc/nginx/http.d/default.conf <<EOF
server {
    listen 0.0.0.0:${WEBPORT};
    server_name _;

    access_log /dev/stdout;
    error_log  /dev/stderr info;

    root /var/www/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_pass 127.0.0.1:${FPM_PORT};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    client_max_body_size 25M;
}
EOF

echo "[start] Testing nginx config..."; nginx -t
echo "[start] Testing php-fpm config..."; php-fpm -t

php-fpm -D
echo "[start] Starting nginx on port ${WEBPORT}..."
exec nginx -g "daemon off;"
