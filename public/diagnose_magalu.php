<?php
// diagnose_magalu.php — Diagnóstico completo do Magalu Object Storage
require_once __DIR__ . '/magalu_storage_helper.php';

function diagnoseMagalu() {
    echo "<h1>🔍 Diagnóstico Magalu Object Storage</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .warning { color: #f59e0b; }
        .error { color: #ef4444; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .code { background: #f3f4f6; padding: 10px; border-radius: 4px; font-family: monospace; margin: 10px 0; }
    </style>";
    
    $magalu = new MagaluStorageHelper();
    $config = $magalu->getConfiguration();
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Configuração Atual</h2>";
    echo "<p><strong>Access Key:</strong> " . $config['access_key'] . "</p>";
    echo "<p><strong>Secret Key:</strong> " . $config['secret_key'] . "</p>";
    echo "<p><strong>Bucket:</strong> " . $config['bucket'] . "</p>";
    echo "<p><strong>Region:</strong> " . $config['region'] . "</p>";
    echo "<p><strong>Endpoint:</strong> " . $config['endpoint'] . "</p>";
    echo "<p><strong>Configurado:</strong> " . ($config['configured'] ? '✅ Sim' : '❌ Não') . "</p>";
    echo "</div>";
    
    if (!$config['configured']) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Configuração Necessária</h2>";
        echo "<p>Configure as seguintes variáveis de ambiente no Railway:</p>";
        echo "<div class='code'>";
        echo "MAGALU_ACCESS_KEY=cadbdf7c-85aa-496a-8015-ff85d9633fd8<br>";
        echo "MAGALU_SECRET_KEY=52fd164c-8667-41a6-8c13-a18e11b7eb8c<br>";
        echo "MAGALU_BUCKET=smilepainel<br>";
        echo "MAGALU_REGION=br-se1<br>";
        echo "MAGALU_ENDPOINT=https://br-se1.magaluobjects.com";
        echo "</div>";
        echo "</div>";
        return;
    }
    
    echo "<div class='section warning-bg'>";
    echo "<h2>🔍 Diagnóstico Detalhado</h2>";
    
    // Teste 1: Verificar se as variáveis estão corretas
    echo "<h3>1. Verificação de Variáveis</h3>";
    $expected_region = 'br-se1';
    $expected_endpoint = 'https://br-se1.magaluobjects.com';
    
    if ($config['region'] === $expected_region) {
        echo "<p class='success'>✅ Região correta: " . $config['region'] . "</p>";
    } else {
        echo "<p class='error'>❌ Região incorreta: " . $config['region'] . " (deveria ser " . $expected_region . ")</p>";
    }
    
    if ($config['endpoint'] === $expected_endpoint) {
        echo "<p class='success'>✅ Endpoint correto: " . $config['endpoint'] . "</p>";
    } else {
        echo "<p class='error'>❌ Endpoint incorreto: " . $config['endpoint'] . " (deveria ser " . $expected_endpoint . ")</p>";
    }
    
    // Teste 2: Verificar conectividade
    echo "<h3>2. Teste de Conectividade</h3>";
    $connection_test = $magalu->testConnection();
    if ($connection_test['success']) {
        echo "<p class='success'>✅ " . $connection_test['message'] . "</p>";
    } else {
        echo "<p class='error'>❌ " . $connection_test['error'] . "</p>";
        
        // Diagnóstico adicional
        echo "<h4>Possíveis Causas:</h4>";
        echo "<ul>";
        echo "<li><strong>Bucket não existe:</strong> Verifique se o bucket 'smilepainel' foi criado no Magalu</li>";
        echo "<li><strong>Região incorreta:</strong> O bucket pode estar em outra região</li>";
        echo "<li><strong>Credenciais incorretas:</strong> Verifique se as chaves estão corretas</li>";
        echo "<li><strong>Permissões:</strong> Verifique se as credenciais têm acesso ao bucket</li>";
        echo "</ul>";
    }
    
    // Teste 3: Verificar se o bucket existe
    echo "<h3>3. Verificação do Bucket</h3>";
    echo "<p><strong>Nome do bucket:</strong> smilepainel</p>";
    echo "<p><strong>Região esperada:</strong> br-se1 (Sudeste)</p>";
    echo "<p><strong>Endpoint esperado:</strong> https://br-se1.magaluobjects.com</p>";
    
    // Teste 4: Verificar credenciais
    echo "<h3>4. Verificação de Credenciais</h3>";
    echo "<p><strong>Access Key:</strong> " . $config['access_key'] . "</p>";
    echo "<p><strong>Secret Key:</strong> " . $config['secret_key'] . "</p>";
    echo "<p><strong>Status:</strong> " . ($config['access_key'] !== 'Não configurado' ? '✅ Configurado' : '❌ Não configurado') . "</p>";
    
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Soluções Recomendadas</h2>";
    echo "<ol>";
    echo "<li><strong>Verificar no painel Magalu:</strong> Confirme se o bucket 'smilepainel' existe na região br-se1</li>";
    echo "<li><strong>Verificar credenciais:</strong> Confirme se as chaves estão corretas e ativas</li>";
    echo "<li><strong>Verificar permissões:</strong> Confirme se as credenciais têm acesso ao bucket</li>";
    echo "<li><strong>Testar região:</strong> Tente criar o bucket na região br-se1 se não existir</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>✅ Configuração Correta</h2>";
    echo "<div class='code'>";
    echo "MAGALU_ACCESS_KEY=cadbdf7c-85aa-496a-8015-ff85d9633fd8<br>";
    echo "MAGALU_SECRET_KEY=52fd164c-8667-41a6-8c13-a18e11b7eb8c<br>";
    echo "MAGALU_BUCKET=smilepainel<br>";
    echo "MAGALU_REGION=br-se1<br>";
    echo "MAGALU_ENDPOINT=https://br-se1.magaluobjects.com";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Próximos Passos</h2>";
    echo "<ol>";
    echo "<li><strong>Acesse o painel Magalu:</strong> Verifique se o bucket 'smilepainel' existe</li>";
    echo "<li><strong>Confirme a região:</strong> Deve estar em br-se1 (Sudeste)</li>";
    echo "<li><strong>Verifique permissões:</strong> As credenciais devem ter acesso ao bucket</li>";
    echo "<li><strong>Teste novamente:</strong> Execute o diagnóstico após correções</li>";
    echo "</ol>";
    echo "</div>";
}

// Executar diagnóstico
diagnoseMagalu();
?>
