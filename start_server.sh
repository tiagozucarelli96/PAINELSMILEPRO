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

# Iniciar servidor PHP
php -S localhost:8000 -t public
