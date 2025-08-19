#!/usr/bin/env sh
set -e

PORT="${PORT:-8080}"

mkdir -p /run/nginx /etc/nginx/http.d

cat > /etc/nginx/http.d/default.conf <<EOF
server {
    listen 0.0.0.0:${PORT};
    server_name _;

    root /var/www/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    client_max_body_size 25M;
}
EOF

echo "[start] Testing nginx config..."
nginx -t

php-fpm -D
echo "[start] Starting nginx on port ${PORT}..."
exec nginx -g "daemon off;"
