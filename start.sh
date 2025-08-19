#!/usr/bin/env sh
set -e

# Porta pública do webserver (o seu domínio está mapeado para 8080)
WEBPORT="8080"
# Porta interna do PHP-FPM (não exposta)
FPM_PORT="9001"

# Pastas necessárias
mkdir -p /run/nginx /etc/nginx/http.d

# Força o PHP-FPM a ouvir em 127.0.0.1:9001 (em vez de socket/9000)
CONF="/usr/local/etc/php-fpm.d/www.conf"
sed -ri "s@^listen\s*=.*@listen = 127.0.0.1:${FPM_PORT}@" "$CONF"
sed -ri 's@^;?clear_env\s*=.*@clear_env = no@' "$CONF"

# Config do Nginx ouvindo no 8080 e repassando pro FPM:9001
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
