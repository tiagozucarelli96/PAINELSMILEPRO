#!/usr/bin/env sh
set -e

PORT="${PORT:-8080}"

# Gera a configuração do Nginx usando o PORT fornecido pela Railway
cat > /etc/nginx/conf.d/default.conf <<EOF
server {
    listen ${PORT};
    server_name _;

    root /var/www/public;
    index index.php index.html;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    client_max_body_size 25M;
}
EOF

# Sobe o PHP-FPM em background e o Nginx em foreground
php-fpm -D
nginx -g "daemon off;"
