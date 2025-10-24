<?php
// test_magalu_storage.php — Testar Magalu Object Storage
require_once __DIR__ . '/magalu_storage_helper.php';

function testMagaluStorage() {
    echo "<h1>🛒 Teste Magalu Object Storage</h1>";
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
        echo "<ul>";
        echo "<li><code>MAGALU_ACCESS_KEY</code> - Sua chave de acesso</li>";
        echo "<li><code>MAGALU_SECRET_KEY</code> - Sua chave secreta</li>";
        echo "<li><code>MAGALU_BUCKET</code> - Nome do bucket</li>";
        echo "<li><code>MAGALU_REGION</code> - Região (sa-east-1)</li>";
        echo "<li><code>MAGALU_ENDPOINT</code> - Endpoint da API</li>";
        echo "</ul>";
        echo "</div>";
        return;
    }
    
    echo "<div class='section " . ($magalu->testConnection()['success'] ? 'success-bg' : 'error-bg') . "'>";
    echo "<h2>🔗 Teste de Conexão</h2>";
    
    $connection_test = $magalu->testConnection();
    if ($connection_test['success']) {
        echo "<p class='success'>✅ " . $connection_test['message'] . "</p>";
    } else {
        echo "<p class='error'>❌ " . $connection_test['error'] . "</p>";
    }
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>📊 Estatísticas de Uso</h2>";
    $stats = $magalu->getUsageStats();
    echo "<p><strong>Provider:</strong> " . $stats['provider'] . "</p>";
    echo "<p><strong>Mensagem:</strong> " . $stats['message'] . "</p>";
    echo "<p><strong>Sugestão:</strong> " . $stats['suggestion'] . "</p>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Funcionalidades Disponíveis</h2>";
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
    echo "<li><strong>Configurar variáveis:</strong> Adicione as credenciais no Railway</li>";
    echo "<li><strong>Testar upload:</strong> Use o sistema de anexos</li>";
    echo "<li><strong>Integrar:</strong> Conectar com sistema de pagamentos</li>";
    echo "<li><strong>Monitorar:</strong> Acompanhar uso no painel Magalu</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Vantagens do Magalu Object Storage</h2>";
    echo "<ul>";
    echo "<li>🇧🇷 <strong>Brasileiro:</strong> Suporte em português</li>";
    echo "<li>💰 <strong>Custo baixo:</strong> Competitivo no mercado</li>";
    echo "<li>🌐 <strong>Região Brasil:</strong> Latência baixa</li>";
    echo "<li>🔧 <strong>API S3-compatible:</strong> Fácil integração</li>";
    echo "<li>📈 <strong>Escalável:</strong> Cresce com sua empresa</li>";
    echo "<li>🔒 <strong>Seguro:</strong> Criptografia e backup</li>";
    echo "</ul>";
    echo "</div>";
}

// Executar teste
testMagaluStorage();
?>
