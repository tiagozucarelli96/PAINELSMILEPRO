<?php
// test_asaas_key.php - Script para testar chave Asaas
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/asaas_helper.php';

echo "<h1>🔑 Teste de Chave Asaas API</h1>";
echo "<style>body { font-family: Arial; padding: 20px; background: #f5f5f5; } .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); } .success { color: green; } .error { color: red; } .info { color: #666; } code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }</style>";

echo "<div class='box'>";
echo "<h2>1. Verificação de Variáveis de Ambiente</h2>";

$env_key = $_ENV['ASAAS_API_KEY'] ?? null;
$getenv_key = getenv('ASAAS_API_KEY') ?: null;

echo "<p><strong>_ENV['ASAAS_API_KEY']:</strong> " . ($env_key ? "✅ Definida (primeiros 30 chars: " . substr($env_key, 0, 30) . "...)" : "❌ Não definida") . "</p>";
echo "<p><strong>getenv('ASAAS_API_KEY'):</strong> " . ($getenv_key ? "✅ Definida (primeiros 30 chars: " . substr($getenv_key, 0, 30) . "...)" : "❌ Não definida") . "</p>";

require_once __DIR__ . '/config_env.php';
echo "<p><strong>ASAAS_API_KEY (constante):</strong> " . (defined('ASAAS_API_KEY') ? "✅ Definida (primeiros 30 chars: " . substr(ASAAS_API_KEY, 0, 30) . "...)" : "❌ Não definida") . "</p>";

echo "</div>";

echo "<div class='box'>";
echo "<h2>2. Teste com AsaasHelper</h2>";

try {
    $helper = new AsaasHelper();
    
    // Usar reflection para acessar propriedade privada
    $reflection = new ReflectionClass($helper);
    $property = $reflection->getProperty('api_key');
    $property->setAccessible(true);
    $key_used = $property->getValue($helper);
    
    echo "<p><strong>Chave sendo usada pelo AsaasHelper:</strong></p>";
    echo "<p class='info'>Primeiros 50 chars: <code>" . substr($key_used, 0, 50) . "...</code></p>";
    echo "<p class='info'>Últimos 30 chars: <code>..." . substr($key_used, -30) . "</code></p>";
    echo "<p class='info'>Tamanho total: " . strlen($key_used) . " caracteres</p>";
    
    echo "<h3>3. Teste de Requisição Real</h3>";
    
    // Testar criando um customer de teste
    $test_data = [
        'name' => 'TESTE - Deletar',
        'email' => 'teste@example.com',
        'phone' => '11999999999'
    ];
    
    echo "<p>Tentando criar customer de teste...</p>";
    
    $result = $helper->createCustomer($test_data);
    
    if ($result && isset($result['id'])) {
        echo "<p class='success'>✅ SUCESSO! Customer criado com ID: " . $result['id'] . "</p>";
        echo "<p>Isso significa que a chave está válida!</p>";
    } else {
        echo "<p class='error'>❌ Erro ao criar customer</p>";
        echo "<pre>" . print_r($result, true) . "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ ERRO: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

echo "<div class='box'>";
echo "<h2>4. Informações Úteis</h2>";
echo "<ul>";
echo "<li>Se a chave não está sendo lida do ENV, verifique se a variável está configurada no Railway</li>";
echo "<li>Após atualizar a variável no Railway, é necessário fazer redeploy</li>";
echo "<li>A chave deve começar com: <code>aact_prod_</code> ou <code>aact_</code></li>";
echo "<li>Se continuar dando 401, a chave pode estar inválida ou expirada</li>";
echo "</ul>";
echo "</div>";

