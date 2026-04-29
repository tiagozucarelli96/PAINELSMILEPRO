#!/bin/bash

echo "🚀 Iniciando servidor PHP local..."
echo "=================================="

# Verificar se o PHP está instalado
if ! command -v php &> /dev/null; then
    echo "❌ PHP não encontrado. Instale o PHP primeiro."
    echo "   macOS: brew install php"
    echo "   Ubuntu: sudo apt install php"
    echo "   Windows: Baixe do php.net"
    exit 1
fi

# Verificar versão do PHP
echo "📋 Versão do PHP:"
php --version

echo ""
echo "🌐 Iniciando servidor na porta 8000..."
echo "   Acesse: http://localhost:8000"
echo "   Para parar: Ctrl+C"
echo ""

APP_ROOT="$(cd "$(dirname "$0")" && pwd)"

if [ ! -f "$APP_ROOT/.env" ]; then
    echo "❌ Arquivo .env não encontrado em $APP_ROOT"
    echo "   Crie a partir de .env.example e defina DATABASE_URL localmente."
    exit 1
fi

# Iniciar servidor PHP
php \
  -d auto_prepend_file="$APP_ROOT/public/session_bootstrap.php" \
  -d upload_max_filesize=80M \
  -d post_max_size=90M \
  -d memory_limit=256M \
  -d max_execution_time=300 \
  -d max_input_time=300 \
  -d log_errors=1 \
  -d error_log=php://stderr \
  -S localhost:8000 -t "$APP_ROOT/public" "$APP_ROOT/public/router.php"
