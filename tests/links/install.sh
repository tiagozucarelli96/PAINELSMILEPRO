#!/bin/bash

# install.sh — Script de instalação para crawler de links
set -e

echo "🕷️ Instalando crawler de links do Painel Smile PRO..."

# Verificar se Node.js está instalado
if ! command -v node &> /dev/null; then
    echo "❌ Node.js não encontrado. Instale Node.js 16+ primeiro."
    exit 1
fi

# Verificar versão do Node.js
NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_VERSION" -lt 16 ]; then
    echo "❌ Node.js 16+ é necessário. Versão atual: $(node -v)"
    exit 1
fi

echo "✅ Node.js $(node -v) encontrado"

# Verificar se npm está disponível
if ! command -v npm &> /dev/null; then
    echo "❌ npm não encontrado"
    exit 1
fi

echo "✅ npm $(npm -v) encontrado"

# Navegar para o diretório de testes
cd "$(dirname "$0")"

# Instalar dependências
echo "📦 Instalando dependências..."
npm install

# Criar diretórios necessários
echo "📁 Criando diretórios..."
mkdir -p logs

# Tornar crawler.js executável
chmod +x crawler.js
chmod +x demo.js

# Verificar se o servidor está rodando
echo "🔍 Verificando servidor..."
if curl -s http://localhost/public/dashboard.php > /dev/null 2>&1; then
    echo "✅ Servidor encontrado em http://localhost"
else
    echo "⚠️ Servidor não encontrado. Certifique-se de que o Painel Smile PRO está rodando."
fi

# Executar teste básico
echo "🧪 Executando teste básico..."
if npm run links:demo > /dev/null 2>&1; then
    echo "✅ Teste básico executado com sucesso"
else
    echo "⚠️ Teste básico falhou. Verifique a configuração."
fi

echo ""
echo "🎉 Instalação concluída!"
echo ""
echo "📋 Comandos disponíveis:"
echo "  npm run links:install  # Instalar dependências"
echo "  npm run links:crawl    # Executar crawler"
echo "  npm run links:demo     # Demonstração"
echo "  npm run links:clean    # Limpar arquivos gerados"
echo ""
echo "📁 Arquivos gerados:"
echo "  tests/links/report.html # Relatório HTML"
echo "  tests/links/report.json # Relatório JSON"
echo "  tests/links/logs/       # Logs detalhados"
echo ""
echo "🔧 Configuração:"
echo "  tests/links/crawler.js  # Executor principal"
echo "  tests/links/README.md   # Documentação"
echo ""
echo "🚀 Para começar:"
echo "  npm run links:crawl"
