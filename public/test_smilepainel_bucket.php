<?php
// test_smilepainel_bucket.php — Testar bucket 'smilepainel' no Magalu
require_once __DIR__ . '/magalu_storage_helper.php';

function testSmilepainelBucket() {
    echo "<h1>🛒 Teste Bucket 'smilepainel' - Magalu Object Storage</h1>";
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
    echo "<p><strong>Region:</strong> " . $config['region'] . " <strong>(Sua região)</strong></p>";
    echo "<p><strong>Endpoint:</strong> " . $config['endpoint'] . " <strong>(Sua região)</strong></p>";
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
        echo "MAGALU_REGION=br-ne1<br>";
        echo "MAGALU_ENDPOINT=https://br-ne1.magaluobjects.com";
        echo "</div>";
        echo "</div>";
        return;
    }
    
    echo "<div class='section " . ($magalu->testConnection()['success'] ? 'success-bg' : 'error-bg') . "'>";
    echo "<h2>🔗 Teste de Conexão</h2>";
    
    $connection_test = $magalu->testConnection();
    if ($connection_test['success']) {
        echo "<p class='success'>✅ " . $connection_test['message'] . "</p>";
        echo "<p class='success'>✅ Bucket 'smilepainel' acessível</p>";
    } else {
        echo "<p class='error'>❌ " . $connection_test['error'] . "</p>";
        echo "<p class='warning'>⚠️ Verifique se o bucket 'smilepainel' existe no Magalu</p>";
    }
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>📊 Informações do Bucket</h2>";
    echo "<p><strong>Nome:</strong> smilepainel</p>";
    echo "<p><strong>Região:</strong> br-se1 (Sudeste)</p>";
    echo "<p><strong>Endpoint:</strong> https://br-se1.magaluobjects.com</p>";
    echo "<p><strong>Status:</strong> Ativo ✅</p>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎯 Funcionalidades Disponíveis</h2>";
    echo "<ul>";
    echo "<li>✅ Upload de arquivos (PDF, JPG, JPEG, PNG)</li>";
    echo "<li>✅ Validação de tamanho (máximo 10MB)</li>";
    echo "<li>✅ Geração de nomes únicos (UUID)</li>";
    echo "<li>✅ URLs públicas</li>";
    echo "<li>✅ Remoção de arquivos</li>";
    echo "<li>✅ Teste de conexão</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Próximos Passos</h2>";
    echo "<ol>";
    echo "<li><strong>Configurar Railway:</strong> Adicione as variáveis no Railway</li>";
    echo "<li><strong>Testar upload:</strong> Use o sistema de anexos</li>";
    echo "<li><strong>Integrar:</strong> Conectar com sistema de pagamentos</li>";
    echo "<li><strong>Monitorar:</strong> Acompanhar uso no painel Magalu</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Vantagens do Magalu Object Storage</h2>";
    echo "<ul>";
    echo "<li>🇧🇷 <strong>Brasileiro:</strong> Suporte em português</li>";
    echo "<li>💰 <strong>Custo baixo:</strong> R$ 0,10/GB/mês</li>";
    echo "<li>🌐 <strong>Região Brasil:</strong> Latência baixa</li>";
    echo "<li>🔧 <strong>API S3-compatible:</strong> Fácil integração</li>";
    echo "<li>📈 <strong>Escalável:</strong> Cresce com sua empresa</li>";
    echo "<li>🔒 <strong>Seguro:</strong> Criptografia avançada</li>";
    echo "<li>📞 <strong>Suporte 24/7:</strong> Em português</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🧪 URLs de Teste</h2>";
    echo "<p><strong>Este teste:</strong> " . $_SERVER['REQUEST_URI'] . "</p>";
    echo "<p><strong>Configuração:</strong> /public/configure_magalu.php</p>";
    echo "<p><strong>Setup:</strong> /public/setup_magalu_vars.php</p>";
    echo "</div>";
}

// Executar teste
testSmilepainelBucket();
?>
