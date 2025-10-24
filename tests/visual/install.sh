#!/bin/bash

# install.sh — Script de instalação para validação visual
set -e

echo "🚀 Instalando sistema de validação visual do Painel Smile PRO..."

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

# Instalar Playwright
echo "🎭 Instalando Playwright..."
npx playwright install chromium

# Criar diretórios necessários
echo "📁 Criando diretórios..."
mkdir -p screens/{desktop,tablet,mobile}
mkdir -p baseline/{desktop,tablet,mobile}
mkdir -p diff/{desktop,tablet,mobile}
mkdir -p logs

# Tornar runner.js executável
chmod +x runner.js

# Verificar se o servidor está rodando
echo "🔍 Verificando servidor..."
if curl -s http://localhost/public/index.php > /dev/null 2>&1; then
    echo "✅ Servidor encontrado em http://localhost"
else
    echo "⚠️ Servidor não encontrado. Certifique-se de que o Painel Smile PRO está rodando."
fi

# Executar teste básico
echo "🧪 Executando teste básico..."
if npm run visual:test > /dev/null 2>&1; then
    echo "✅ Teste básico executado com sucesso"
else
    echo "⚠️ Teste básico falhou. Verifique a configuração."
fi

echo ""
echo "🎉 Instalação concluída!"
echo ""
echo "📋 Comandos disponíveis:"
echo "  npm run visual:test          # Executar todos os testes"
echo "  npm run visual:routes        # Executar com rotas específicas"
echo "  npm run visual:update-baseline # Atualizar baseline"
echo ""
echo "📁 Arquivos gerados:"
echo "  tests/visual/report.html      # Relatório HTML"
echo "  tests/visual/log.json         # Log detalhado"
echo "  tests/visual/screens/         # Screenshots atuais"
echo "  tests/visual/baseline/        # Screenshots de referência"
echo "  tests/visual/diff/            # Imagens de diferenças"
echo ""
echo "🔧 Configuração:"
echo "  tests/visual/config.js        # Configurações"
echo "  tests/visual/routes.txt       # Rotas prioritárias"
echo "  tests/visual/README.md        # Documentação"
echo ""
echo "🚀 Para começar:"
echo "  npm run visual:test"
