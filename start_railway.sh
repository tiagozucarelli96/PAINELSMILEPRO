#!/bin/bash
# Script de inicialização para Railway
# Garante que a variável PORT seja expandida corretamente

set -e

# Obter porta do Railway ou usar padrão
PORT=${PORT:-8080}

echo "🚀 Iniciando servidor PHP na porta $PORT"

# Iniciar servidor PHP embutido
# IMPORTANTES:
# - Aumentar limites de upload/POST (evita warning de Content-Length e quebra de session/header)
# - Manter erros em log (stderr), sem exibir warning no browser por padrão
exec php \
  -d upload_max_filesize=25M \
  -d post_max_size=60M \
  -d memory_limit=256M \
  -d max_execution_time=300 \
  -d max_input_time=300 \
  -d display_errors=0 \
  -d display_startup_errors=0 \
  -d log_errors=1 \
  -d error_log=php://stderr \
  -S 0.0.0.0:$PORT -t public public/router.php
