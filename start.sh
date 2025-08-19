#!/usr/bin/env sh
set -e

PORT="${PORT:-8080}"

# Pastas necessárias
mkdir -p /run/nginx /run/php /etc/nginx/http.d

# ====== PHP-FPM em SOCKET (evita colisão com $PORT=9000) ======
CONF="/usr/local/etc/php-fpm.d/www.conf"
# ouvir em socket
sed -ri 's@^listen\s*=\s*.*@listen = /run/php/php-fpm.sock@' "$CONF"
# garantir permissões e env
grep -q '^listen.owner' "$CONF" || echo 'listen.owner = nginx' >> "$CONF"
grep -q '^listen.group' "$CONF" || echo 'listen.group = nginx' >> "$CONF"
grep -q '^listen.mode'  "$CONF" || echo 'listen.mode = 0660'  >> "$CONF"
sed -ri 's@^;?clear_env\s*=.*@clear_env = no@' "$CONF"

# ====== NGINX ouvindo no $PORT ======
cat > /etc/nginx/http.d/default.conf <<EOF
server {
    listen 0.0.0.0:${PORT};
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
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    client_max_body_size 25M;
}
EOF

echo "[start] Testing nginx config..."
nginx -t

echo "[start] Testing php-fpm config..."
php-fpm -t

# Sobe serviços
php-fpm -D
echo "[start] Starting nginx on port ${PORT}..."
exec nginx -g "daemon off;"
