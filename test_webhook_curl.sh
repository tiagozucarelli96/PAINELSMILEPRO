#!/bin/bash
# Script para testar se webhook retorna HTTP 200

URL="https://painelsmilepro-production.up.railway.app/public/asaas_webhook.php"

echo "🔍 Testando webhook com cURL..."
echo "URL: $URL"
echo ""

# Teste 1: POST vazio
echo "📋 Teste 1: POST vazio"
curl -X POST \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "" \
  -w "\n\nHTTP Status: %{http_code}\n" \
  -v \
  "$URL" 2>&1 | grep -E "(HTTP|Status|200|308|301|302)"

echo ""
echo ""

# Teste 2: POST com form data
echo "📋 Teste 2: POST com form data (como Asaas envia)"
curl -X POST \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "data={\"test\":\"true\"}" \
  -w "\n\nHTTP Status: %{http_code}\n" \
  -v \
  "$URL" 2>&1 | grep -E "(HTTP|Status|200|308|301|302)"

echo ""
echo ""
echo "✅ Se ambos os testes mostraram 'HTTP Status: 200', está correto!"
echo "❌ Se mostraram 308, 301, 302 ou outro código, há redirecionamento no servidor"

