#!/usr/bin/env sh
set -e

PORT="${PORT:-8080}"

# Garante pastas
mkdir -p /run/nginx /run/php /etc/nginx/http.d

# Força o PHP-FPM a escutar em SOCKET (evita colisão com $PORT=9000)
cat > /usr/local/etc/php-fpm.d/zz-railway.conf <<'EOF'
[www]
listen = /run/php/php-fpm.sock
listen.mode = 0660
clear_env = no
EOF

# Nginx ouvindo no $PORT
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

# Sobe serviços
php-fpm -D
echo "[start] Starting nginx on port ${PORT}..."
exec nginx -g "daemon off;"
