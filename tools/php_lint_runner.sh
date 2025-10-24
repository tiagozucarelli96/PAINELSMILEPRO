#!/bin/bash

# php_lint_runner.sh — Script para executar análise estática de PHP
set -e

echo "🔍 Iniciando análise estática de PHP..."

# Verificar se PHP está disponível
if ! command -v php &> /dev/null; then
    echo "❌ PHP não encontrado. Instale PHP primeiro."
    exit 1
fi

echo "✅ PHP $(php -v | head -n1) encontrado"

# Navegar para o diretório do projeto
cd "$(dirname "$0")/.."

# Criar diretórios necessários
mkdir -p logs
mkdir -p tools

# Executar análise estática
echo "🔍 Executando análise estática..."
php tools/php_include_lint.php "$@"

# Verificar se houve erros
if [ $? -eq 0 ]; then
    echo "✅ Análise concluída sem erros"
else
    echo "❌ Análise encontrou erros"
fi

# Mostrar relatórios gerados
echo ""
echo "📊 Relatórios gerados:"
if [ -f "tools/php_lint_report.json" ]; then
    echo "✅ tools/php_lint_report.json"
fi

if [ -f "tools/php_lint_report.txt" ]; then
    echo "✅ tools/php_lint_report.txt"
fi

if [ -f "logs/php_lint.log" ]; then
    echo "✅ logs/php_lint.log"
fi

echo ""
echo "📋 Para ver o relatório:"
echo "  cat tools/php_lint_report.txt"
echo ""
echo "📋 Para aplicar correções seguras:"
echo "  php tools/php_include_lint.php --fix-safe"
