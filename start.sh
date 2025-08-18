#!/usr/bin/env sh
set -e

PORT="${PORT:-8080}"

# Gera a config do Nginx usando o $PORT (Alpine usa http.d)
cat > /etc/nginx/http.d/default.conf <<EOF
server {
    listen ${PORT};
    server_name _;

    root /var/www/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
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

# Sobe serviços
php-fpm -D
nginx -g "daemon off;"
