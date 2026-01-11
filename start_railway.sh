#!/bin/bash
# Script de inicialização para Railway
# Garante que a variável PORT seja expandida corretamente

set -e

# Obter porta do Railway ou usar padrão
PORT=${PORT:-8080}

echo "🚀 Iniciando servidor PHP na porta $PORT"

# Iniciar servidor PHP embutido
exec php -S 0.0.0.0:$PORT -t public public/router.php
