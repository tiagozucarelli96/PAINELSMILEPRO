<?php
// setup_magalu_vars.php — Configurar variáveis do Magalu Object Storage
function showMagaluSetup() {
    echo "<h1>🛒 Configuração Magalu Object Storage</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .warning { background: #fffbeb; border: 1px solid #fed7aa; }
        .code { background: #f3f4f6; padding: 10px; border-radius: 4px; font-family: monospace; }
        .step { margin: 15px 0; padding: 10px; background: #f9fafb; border-left: 4px solid #3b82f6; }
    </style>";
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Variáveis de Ambiente Necessárias</h2>";
    echo "<p>Adicione estas variáveis no Railway para configurar o Magalu Object Storage:</p>";
    
    $variables = [
        'MAGALU_ACCESS_KEY' => 'Sua chave de acesso do Magalu',
        'MAGALU_SECRET_KEY' => 'Sua chave secreta do Magalu',
        'MAGALU_BUCKET' => 'Nome do bucket criado no Magalu',
        'MAGALU_REGION' => 'br-se1 (região Sudeste)',
        'MAGALU_ENDPOINT' => 'https://br-se1.magaluobjects.com'
    ];
    
    foreach ($variables as $var => $description) {
        echo "<div class='step'>";
        echo "<strong>$var:</strong> $description";
        echo "</div>";
    }
    echo "</div>";
    
    echo "<div class='section warning'>";
    echo "<h2>📋 Como Obter as Credenciais</h2>";
    echo "<ol>";
    echo "<li><strong>Acesse o painel Magalu Object Storage</strong></li>";
    echo "<li><strong>Crie um bucket</strong> (ex: painelsmile-anexos)</li>";
    echo "<li><strong>Gere as chaves de acesso</strong> (Access Key + Secret Key)</li>";
    echo "<li><strong>Anote o endpoint</strong> da região Brasil</li>";
    echo "<li><strong>Configure no Railway</strong> as variáveis acima</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section success'>";
    echo "<h2>✅ Exemplo de Configuração</h2>";
    echo "<div class='code'>";
    echo "MAGALU_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE<br>";
    echo "MAGALU_SECRET_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY<br>";
    echo "MAGALU_BUCKET=smilepainel<br>";
    echo "MAGALU_REGION=br-se1<br>";
    echo "MAGALU_ENDPOINT=https://br-se1.magaluobjects.com";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🧪 Testar Configuração</h2>";
    echo "<p>Após configurar as variáveis, teste a conexão:</p>";
    echo "<div class='code'>";
    echo "https://seudominio.railway.app/public/test_magalu_storage.php";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='section success'>";
    echo "<h2>🎯 Benefícios do Magalu Object Storage</h2>";
    echo "<ul>";
    echo "<li>🇧🇷 <strong>Empresa brasileira</strong> - Suporte em português</li>";
    echo "<li>💰 <strong>Custo competitivo</strong> - Preços em reais</li>";
    echo "<li>🌐 <strong>Região Brasil</strong> - Latência baixa</li>";
    echo "<li>🔧 <strong>API S3-compatible</strong> - Fácil integração</li>";
    echo "<li>📈 <strong>Escalável</strong> - Cresce com sua empresa</li>";
    echo "<li>🔒 <strong>Seguro</strong> - Criptografia e backup automático</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section warning'>";
    echo "<h2>⚠️ Importante</h2>";
    echo "<ul>";
    echo "<li><strong>Não compartilhe</strong> suas chaves de acesso</li>";
    echo "<li><strong>Use HTTPS</strong> para todas as comunicações</li>";
    echo "<li><strong>Monitore o uso</strong> no painel Magalu</li>";
    echo "<li><strong>Configure backup</strong> para arquivos críticos</li>";
    echo "</ul>";
    echo "</div>";
}

// Executar configuração
showMagaluSetup();
?>
